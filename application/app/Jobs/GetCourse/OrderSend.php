<?php

namespace App\Jobs\GetCourse;

use App\Models\Core\Account;
use App\Models\Integrations\GetCourse\Order;
use App\Models\Integrations\GetCourse\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class OrderSend implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Order $order,
        public Account $account,
        public Setting $setting,
    )
    {
        $this->onQueue('getcourse_order');
    }

//    public function uniqueId()
//    {
//        return $this->setting->id;
//    }

    public function handle()
    {
        Artisan::call('app:getcourse-order-send', [
            'order'   => $this->order->id,
            'account' => $this->account->id,
            'setting' => $this->setting->id,
        ]);
    }
}
