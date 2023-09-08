<?php

namespace App\Jobs\AlfaCRM;

use App\Models\AlfaCRM\Setting;
use App\Models\AlfaCRM\Transaction;
use App\Models\Core\Account;
use App\Models\Webhook;
use App\Services\AlfaCRM\Models\Customer;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Notes;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class OmissionWithoutLead implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    /**
     * Количество секунд, в течение которых задание может выполняться до истечения тайм-аута.
     *
     * @var int
     */
//    public int $timeout = 90;

    /**
     * Количество секунд ожидания перед повторной попыткой выполнения задания.
     *
     * @var int
     */
    public int $backoff = 10;

    /**
     * Indicate if the job should be marked as failed on timeout.
     *
     * @var bool
     */
    public bool $failOnTimeout = true;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        public \App\Models\Integrations\Alfa\Transaction $transaction,
        public \App\Models\Integrations\Alfa\Setting $setting,
        public Account $account,
    )
    {
        $this->onQueue('alfacrm_record');
    }

    public function tags(): array
    {
        return ['alfacrm-omission', 'client:'.$this->account->subdomain];
    }

    /**
     * Execute the job.
     *
     * @return false
     * @throws Exception
     */
    public function handle(): bool
    {
        Artisan::call('app:alfacrm-omission-send', [
            'transaction' => $this->transaction->id,
            'account' => $this->account->id,
            'setting' => $this->setting->id,
        ]);
    }
}
