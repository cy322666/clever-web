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
        Artisan::call('yc:send-record', [
            'record'  => $this->record,
            'account' => $this->account,
            'setting' => $this->setting,
        ]);

        dd('end');
        Artisan::call('yc:update-entities', [
            'record_id'  => $this->record->id,
            'account_id' => $this->account->id,
            'setting_id' => $this->setting->id,
        ]);
    }
}
