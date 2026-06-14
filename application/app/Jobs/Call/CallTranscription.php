<?php

namespace App\Jobs\Call;

use App\Jobs\Concerns\BuildsHorizonTags;
use App\Models\Core\Account;
use App\Models\Integrations\CallTranscription\Setting;
use App\Models\Integrations\CallTranscription\Transaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

class CallTranscription implements ShouldQueue
{
    use BuildsHorizonTags, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Transaction $transaction,
        public Account $account,
        public Setting $setting,
    ) {
        $this->onQueue('call_transcription');
    }

    public function tags(): array
    {
        return $this->horizonTags([
            'widget:call-transcription',
            'queue:call_transcription',
            $this->accountHorizonTags($this->account),
            $this->modelHorizonTag('call_transaction', $this->transaction),
            $this->modelHorizonTag('call_setting', $this->setting),
        ]);
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
