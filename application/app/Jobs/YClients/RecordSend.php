<?php

namespace App\Jobs\YClients;

use App\Jobs\Concerns\BuildsHorizonTags;
use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordSend implements ShouldQueue
{
    use BuildsHorizonTags, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

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
        try {
            $exitCode = Artisan::call('yc:send-record', [
                'record_id' => $this->record->id,
                'account_id' => $this->account->id,
                'setting_id' => $this->setting->id,
                '--skip-note' => !$this->createNote,
            ]);

            if ($exitCode !== 0) {
                $this->markFailed('yc:send-record returned non-zero exit code.', [
                    'exit_code' => $exitCode,
                    'command_output' => trim(Artisan::output()),
                ]);

                return;
            }

            $exitCode = Artisan::call('yc:update-entities', [
                'record_id' => $this->record->id,
                'account_id' => $this->account->id,
                'setting_id' => $this->setting->id,
            ]);

            if ($exitCode !== 0) {
                $this->markFailed('yc:update-entities returned non-zero exit code.', [
                    'exit_code' => $exitCode,
                    'command_output' => trim(Artisan::output()),
                ]);
            }
        } catch (Throwable $e) {
            $this->markFailed('Unhandled exception in YClients RecordSend job.', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
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
