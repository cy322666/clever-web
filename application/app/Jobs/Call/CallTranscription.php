<?php

namespace App\Jobs\Call;

use App\Models\Core\Account;
use App\Models\Integrations\CallTranscription\Setting;
use App\Models\Integrations\CallTranscription\Transaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

class CallTranscription implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Transaction $transaction,
        public Account $account,
        public Setting $setting,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Artisan::call('app:call-send', [
            'transaction_id' => $this->transaction->id,
            'account_id' => $this->account->id,
            'setting_id' => $this->setting->id,
        ]);
    }
}
