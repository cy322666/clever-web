<?php

namespace App\Jobs\Distribution;

use App\Jobs\Concerns\BuildsHorizonTags;
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
    use BuildsHorizonTags, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Account $account;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public $transaction,
        public $setting,
        public $user
    ) {
        $this->onQueue('distribution_transaction');

        $user = User::query()->find($this->user);

        $this->account = $user->account;
    }

    public function tags(): array
    {
        return $this->horizonTags([
            'widget:distribution',
            'queue:distribution_transaction',
            $this->accountHorizonTags($this->account),
            $this->modelHorizonTag('distribution_transaction', $this->transaction),
            $this->modelHorizonTag('distribution_setting', $this->setting),
            $this->modelHorizonTag('user', $this->user),
        ]);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Artisan::call('app:distribution-responsible-send', [
            'transaction' => $this->transaction,
            'account' => $this->account->id,
            'setting' => $this->setting,
            'user' => $this->user,
        ]);
    }
}
