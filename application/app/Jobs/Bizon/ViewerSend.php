<?php

namespace App\Jobs\Bizon;

use App\Models\Core\Account;
use App\Models\Integrations\Bizon\Setting;
use App\Models\Integrations\Bizon\Viewer;
use App\Services\amoCRM\Client;
use App\Services\Bizon365\ViewerSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class ViewerSend implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество попыток выполнения задания.
     *
     * @var int
     */
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
     * Количество секунд, по истечении которых уникальная блокировка задания будет снята.
     *
     * @var int
     */
//    public int $uniqueFor = 5;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        private Viewer $viewer,
        private Setting $setting,
        private Account $acccount,
    )
    {
        $this->onQueue('bizon_export');
    }

    /**
     * Получить посредника, через которого должно пройти задание.
     *
     * @return array
     */
    public function middleware(): array
    {
        return [];
    }

    public function uniqueId()
    {
        return $this->viewer->id;
    }

    /**
     * Execute the job.
     * @return bool
     * @throws \Exception
     * @var Client $amoApi
     */
    // artisan queue:listen database --queue=bizon_export --sleep=3
    public function handle(): bool
    {
        Artisan::call('app:viewer-send', [
            'viewer'  => $this->viewer,
            'account' => $this->acccount,
            'setting' => $this->setting,
        ]);
    }
}
