<?php

namespace App\Jobs\YClients;

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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Record $record,
        public Account $account,
        public Setting $setting,
    )
    {
        $this->onQueue('yclients_record');
    }

    public function tags(): array
    {
        return ['yclients', 'client:'.$this->account->subdomain];
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
            $record->save();
        }

        Log::error($message, [
            'record_id' => $this->record->id,
            'account_id' => $this->account->id,
            'setting_id' => $this->setting->id,
            ...$context,
        ]);
    }
}
