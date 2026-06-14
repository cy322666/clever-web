<?php

namespace App\Jobs\AlfaCRM;

use App\Jobs\Concerns\BuildsHorizonTags;
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
class ArchiveLead implements ShouldQueue
{
    use BuildsHorizonTags, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

    public function tags(): array
    {
        return $this->horizonTags([
            'widget:alfacrm',
            'queue:alfacrm_hook',
            $this->accountHorizonTags($this->account),
            $this->modelHorizonTag('alfacrm_transaction', $this->transaction),
            $this->modelHorizonTag('alfacrm_setting', $this->setting),
        ]);
    }

    /**
     * Execute the job.
     *
     * @return false
     * @throws Exception
     */
    public function handle()
    {
        Artisan::call('app:alfacrm-archive-send', [
            'transaction_id' => $this->transaction->id,
            'setting_id' => $this->setting->id,
            'account_id' => $this->account->id,
        ]);
    }
}
