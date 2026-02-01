<?php

namespace App\Jobs;

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
        Transaction $transaction,
        Account $account,
        Setting $setting,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Artisan::call('app:call-send', [
            'transaction' => $this->transaction->id,
            'account' => $this->account->id,
            'setting' => $this->setting->id,
        ]);
    }
}
