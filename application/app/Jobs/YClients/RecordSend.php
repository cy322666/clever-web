<?php

namespace App\Jobs\YClients;

use App\Jobs\Concerns\BuildsHorizonTags;
use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\YClients\AmoTransportRetry;
use App\Services\YClients\EntityCreateGuard;
use App\Services\YClients\RecordSyncLock;
use App\Services\YClients\UnconfirmedCreateException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RecordSend implements ShouldQueue
{
    use BuildsHorizonTags, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Lock contention must not consume the four transport attempts.
    public int $tries = 50;

    public int $maxExceptions = 4;

    public ?string $attemptKey = null;

    public int $timeout = 300;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 300];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Record $record,
        public Account $account,
        public Setting $setting,
        public bool $createNote = true,
    )
    {
        $this->onQueue('yclients_record');
        $this->attemptKey = (string) Str::uuid();
    }

    public function tags(): array
    {
        return $this->horizonTags([
            'widget:yclients',
            'queue:yclients_record',
            $this->accountHorizonTags($this->account),
            $this->modelHorizonTag('yclients_record', $this->record),
            $this->modelHorizonTag('yclients_setting', $this->setting),
        ]);
    }
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // During an expand/contract deploy, do not start writes before the receipt migration.
        if (!EntityCreateGuard::ready()) {
            if ($this->job) {
                Log::warning('YClients sync deferred until create receipts migration is applied.');
                $this->release(60);
                return;
            }
            throw new RuntimeException('YClients create receipts migration is required before synchronization.');
        }

        $lock = $this->syncLock();

        if (!$lock->get()) {
            if ($this->job) {
                $this->release(30);
                return;
            }

            throw new LockTimeoutException('YClients record is already being synchronized.');
        }

        try {
            $this->updateState(Record::STATUS_PENDING);
            $exitCode = Artisan::call('yc:send-record', [
                'record_id' => $this->record->id,
                'account_id' => $this->account->id,
                'setting_id' => $this->setting->id,
                '--skip-note' => !$this->createNote,
                '--lock-owner' => $lock->owner(),
                '--defer-status' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException('yc:send-record failed: '.trim(Artisan::output()));
            }

            $exitCode = Artisan::call('yc:update-entities', [
                'record_id' => $this->record->id,
                'account_id' => $this->account->id,
                'setting_id' => $this->setting->id,
                '--lock-owner' => $lock->owner(),
                '--defer-status' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException('yc:update-entities failed: '.trim(Artisan::output()));
            }

            $this->updateState(Record::STATUS_SUCCESS);
            $this->retryCache()->forget($this->retryKey());
        } catch (Throwable $e) {
            if ($e instanceof LockTimeoutException && $this->job) {
                $this->release(30);
                return;
            }

            if ($this->releaseTransportFailure($e)) {
                return;
            }

            $this->markFailed('Unhandled exception in YClients RecordSend job.', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            if ($this->job) {
                $this->fail($e);
            } else {
                throw $e;
            }
        } finally {
            $lock->release();
        }
    }

    private function releaseTransportFailure(Throwable $exception): bool
    {
        if (!AmoTransportRetry::isTransportError($exception) && !$exception instanceof UnconfirmedCreateException) {
            return false;
        }

        $record = Record::query()->find($this->record->id);

        if (!$record) {
            return false;
        }

        // Queue release does not reserialize mutated job properties. Keep the bounded
        // transport counter in the shared store, independently from lock contention.
        $this->retryCache()->add($this->retryKey(), 0, 86400);
        $attempt = (int) $this->retryCache()->increment($this->retryKey());

        if (!$this->job || $attempt >= 4) {
            return false;
        }

        $delay = $this->backoff[$attempt - 1] ?? $this->backoff[array_key_last($this->backoff)];
        $record->forceFill([
            'status' => Record::STATUS_PENDING,
            'error_message' => null,
        ])->save();

        Log::warning('YClients RecordSend transport failure released for retry.', [
            'record_id' => $record->id,
            'yclients_record_id' => $record->record_id,
            'account_id' => $record->account_id,
            'setting_id' => $record->setting_id,
            'lead_id' => $record->lead_id,
            'attempt' => $attempt,
            'next_attempt' => $attempt + 1,
            'retry_delay_seconds' => $delay,
            'error' => $exception->getMessage(),
        ]);

        $this->release($delay);

        return true;
    }

    public function failed(?Throwable $exception): void
    {
        $lock = $this->syncLock();
        if (!$lock->isOwnedByCurrentProcess() && !$lock->get()) {
            return; // Another job still owns the record. Do not overwrite its result.
        }

        try {
            $record = Record::query()->find($this->record->id);
            if ($record && $record->status === Record::STATUS_PENDING) {
                $this->markFailed('YClients RecordSend exhausted its queue attempts.', [
                    'error' => $exception?->getMessage(),
                    'exception' => $exception ? $exception::class : null,
                ]);
            }
        } finally {
            $lock->release();
        }
    }

    private function syncLock(): \Illuminate\Contracts\Cache\Lock
    {
        $uuid = $this->job?->uuid();
        return RecordSyncLock::make((int) $this->record->id, $uuid ? 'job:'.$uuid : null);
    }

    private function retryCache(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store(config('cache.yclients_lock_store', 'database'));
    }

    private function retryKey(): string
    {
        return 'yclients:record-transport:'.($this->job?->uuid() ?: $this->attemptKey ?: $this->record->id);
    }

    private function updateState(string $status): void
    {
        // Always read fresh: a stale model with original error=null will not clear a newer error.
        Record::query()->findOrFail($this->record->id)->forceFill([
            'status' => $status,
            'error_message' => null,
        ])->save();
    }

    private function markFailed(string $message, array $context = []): void
    {
        $record = Record::query()->find($this->record->id);

        if ($record) {
            $record->status = Record::STATUS_FAILED;

            if (blank($record->error_message)) {
                $record->error_message = $this->formatErrorMessage($message, $context);
            }

            $record->save();
        }

        Log::error($message, [
            'record_id' => $this->record->id,
            'account_id' => $this->account->id,
            'setting_id' => $this->setting->id,
            ...$context,
        ]);
    }

    private function formatErrorMessage(string $message, array $context = []): string
    {
        $lines = [$message];

        foreach ($context as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $lines[] = $key . ': ' . $value;
        }

        return implode("\n", $lines);
    }
}
