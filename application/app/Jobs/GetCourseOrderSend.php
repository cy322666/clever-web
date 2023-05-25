<?php

namespace App\Jobs;

use App\Models\Core\Account;
use App\Models\Integrations\GetCourse\Order;
use App\Models\Integrations\GetCourse\Setting;
use App\Services\amoCRM\Client;
use App\Services\GetCourse\OrderSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GetCourseOrderSend implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        private Order $order,
        private Setting $setting,
        private Account $account,
    ) {
        $this->onQueue('getcourse_order_export');
    }

    public function uniqueId(): string
    {
        return $this->setting->id;
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws \Exception
     */
    public function handle()
    {
        OrderSender::send(
            (new Client($this->account))->init(),
            $this->order,
            $this->setting,
        );
//        $link = 'https://online.pekarina-zefir.ru/sales/control/deal/update/id/'.$id;

//$lead->attachTag('Оплата '.date('Y-m-d'));
    }
}
