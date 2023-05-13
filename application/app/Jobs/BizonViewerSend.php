<?php

namespace App\Jobs;

use App\Models\Core\Account;
use App\Models\Integrations\Bizon\Setting;
use App\Models\Integrations\Bizon\Viewer;
use App\Services\amoCRM\Client;
use App\Services\Bizon365\ViewerSender;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Jobs\Middleware\BizonViewerSend as SendMiddleware;

class BizonViewerSend implements ShouldQueue
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
    public int $timeout = 25;

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
    public int $uniqueFor = 5;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        private Viewer $viewer,
        private Setting $setting,
        private Account $account,
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
        return [
//            new SendMiddleware
        ];
    }

    public function uniqueId(): string
    {
        return $this->setting->id;
    }

    /**
     * @throws Exception
     */
    public function handle()
    {
        ViewerSender::send(
            (new Client($this->account))->init(),
            $this->viewer,
            $this->setting
        );
    }
}
