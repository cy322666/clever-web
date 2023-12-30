<?php

namespace App\Jobs\Distribution;

use App\Models\Core\Account;
use App\Models\Integrations\Distribution\Setting;
use App\Models\Integrations\Distribution\Transaction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class ResponsibleSend implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Account $account;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Transaction $transaction,
        public Setting $setting,
        public User $user
    ) {
        $this->onQueue('distribution_transaction');

        $this->account = $this->user->account;
    }

    public function tags(): array
    {
        return ['distribution', 'client:'.$this->account->subdomain];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Artisan::call('app:distribution-responsible-send', [
            'transaction' => $this->transaction,
            'account' => $this->account,
            'setting' => $this->setting,
            'user' => $this->user,
        ]);
    }
}
