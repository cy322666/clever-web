<?php

namespace App\Jobs\YClients;

use App\Jobs\Concerns\BuildsHorizonTags;
use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\RecordEvent;
use App\Models\Integrations\YClients\Setting;
use App\Services\YClients\AmoTransportRetry;
use App\Services\YClients\EntityCreateGuard;
use App\Services\YClients\RecordSyncLock;
use App\Services\YClients\RecordSyncContext;
use App\Services\YClients\RecordEventQueue;
use App\Services\YClients\UnconfirmedCreateException;
use App\Services\YClients\YClientsRequestThrottle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RecordSend implements ShouldQueue
{
    use BuildsHorizonTags, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Admission and rate-limit waits are not failed attempts. Transport retries remain bounded below.
    public int $tries = 0;

    public int $maxExceptions = 4;

    public ?string $attemptKey = null;

    public ?int $eventId = null;

    private ?RecordEvent $activeEvent = null;

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
        ?int $eventId = null,
    )
    {
        $this->onQueue('yclients_record');
        $this->attemptKey = (string) Str::uuid();
        $this->eventId = $eventId;
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

        $completedEvent = false;
        try {
            if ($this->eventId && !RecordEventQueue::ready()) {
                $this->job?->release(60);
                return;
            }
            if (RecordEventQueue::ready()) {
                $head = RecordEventQueue::head((int) $this->record->id);
                if (($this->eventId && (int) $head?->id !== $this->eventId)
                    || $head?->status === Record::STATUS_FAILED) {
                    return;
                }
                $this->activeEvent = $head;
                if ($head) {
                    $this->createNote = $head->create_note;
                    if ($head->available_at?->isFuture()) {
                        $seconds = max(1, (int) ceil(now()->diffInSeconds($head->available_at)));
                        if (!$this->job) {
                            throw new \App\Services\YClients\RequestDeferredException('YClients event is waiting for its retry time.', $seconds);
                        }
                        $this->job->release($seconds);
                        return;
                    }
                }
            }
            RecordSyncContext::run(function () use ($lock): void {
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
            }, $this->activeEvent);

            $this->updateState(Record::STATUS_SUCCESS);
            $completedEvent = $this->activeEvent !== null;
            $this->retryCache()->forget($this->retryKey());
            $this->retryCache()->forget($this->rateLimitRetryKey());
        } catch (Throwable $e) {
            if ($e instanceof LockTimeoutException && $this->job) {
                $this->release(30);
                return;
            }

            if ($this->releaseDeferredRequest($e) || $this->releaseTransportFailure($e)) {
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
            if ($completedEvent) {
                RecordEventQueue::dispatchHead((int) $this->record->id);
            }
        }
    }

    private function releaseDeferredRequest(Throwable $exception): bool
    {
        if (!YClientsRequestThrottle::shouldDefer($exception)) {
            return false;
        }

        $this->updateState(Record::STATUS_PENDING);
        if (!$this->job) {
            throw $exception;
        }

        $this->retryCache()->add($this->rateLimitRetryKey(), 0, 86400);
        $attempt = (int) $this->retryCache()->increment($this->rateLimitRetryKey());
        $minimumDelay = min(300, 30 * (2 ** min(4, max(0, $attempt - 1))));
        $delay = app(YClientsRequestThrottle::class)->retryDelay($exception, $minimumDelay);
        if ($this->activeEvent) {
            RecordEventQueue::defer($this->activeEvent, $delay);
        }

        Log::notice('YClients RecordSend deferred by API rate limit or admission.', [
            'record_id' => $this->record->id,
            'account_id' => $this->account->id,
            'retry_delay_seconds' => $delay,
        ]);
        $this->release($delay);

        return true;
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
        if ($this->activeEvent) {
            RecordEventQueue::defer($this->activeEvent, $delay);
        }
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
            if (RecordEventQueue::ready()) {
                if (!$this->eventId && !$this->activeEvent && RecordEvent::where('record_id', $this->record->id)->exists()) {
                    return; // A legacy failure callback has no event identity. The outbox will recover its head.
                }
                $head = RecordEventQueue::head((int) $this->record->id);
                if (($this->eventId && (int) $head?->id !== $this->eventId)
                    || (!$head && RecordEvent::where('record_id', $this->record->id)->exists())) {
                    return;
                }
                $this->activeEvent = $head;
            }
            if ($record && $record->status === Record::STATUS_PENDING) {
                // Jobs queued before this fix still carry the old maxTries=50 payload.
                if ($exception instanceof MaxAttemptsExceededException) {
                    $delay = app(YClientsRequestThrottle::class)->retryDelay(null, 60);
                    Bus::dispatch((new self($record, $this->account, $this->setting, $this->createNote, $this->activeEvent?->id))
                        ->delay(now()->addSeconds($delay)));
                    $record->forceFill(['error_message' => null])->save();
                    return;
                }

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
        if ($this->activeEvent) {
            return 'yclients:record-transport:event:'.$this->activeEvent->id;
        }
        return 'yclients:record-transport:'.($this->job?->uuid() ?: $this->attemptKey ?: $this->record->id);
    }

    private function rateLimitRetryKey(): string
    {
        if ($this->activeEvent) {
            return 'yclients:record-rate-limit:event:'.$this->activeEvent->id;
        }
        return 'yclients:record-rate-limit:'.($this->job?->uuid() ?: $this->attemptKey ?: $this->record->id);
    }

    private function updateState(string $status): void
    {
        if ($this->activeEvent) {
            RecordEventQueue::finish($this->activeEvent, $status);
            return;
        }
        // Always read fresh: a stale model with original error=null will not clear a newer error.
        Record::query()->findOrFail($this->record->id)->forceFill([
            'status' => $status,
            'error_message' => null,
        ])->save();
    }

    private function markFailed(string $message, array $context = []): void
    {
        $record = Record::query()->find($this->record->id);

        if ($this->activeEvent) {
            RecordEventQueue::finish($this->activeEvent, Record::STATUS_FAILED, $this->formatErrorMessage($message, $context));
        } elseif ($record) {
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
