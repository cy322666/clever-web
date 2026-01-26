<?php

namespace App\Jobs\AlfaCRM;

use App\Models\Core\Account;
use App\Models\Integrations\Alfa\Setting;
use App\Models\Integrations\Alfa\Transaction;
use App\Models\Webhook;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Notes;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Отправляет клиента после записи в АльфаСРМ
 */
class Pay implements ShouldQueue
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
        public Setting $setting,
        public Transaction $transaction,
        public Account $account,
    )
    {
        $this->onQueue('alfacrm_hook');
    }

    /**
     * Execute the job.
     *
     * @return false
     * @throws Exception
     */
    public function handle()
    {
        Artisan::call('app:alfacrm-pay-send', [
            'transaction_id' => $this->setting->id,
            'setting_id' => $this->transaction->id,
            'account_id' => $this->account->id,
        ]);
    }
}
