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
    ) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        OrderSender::send(
            (new Client($this->account))->init(),
            $this->order,
            $this->setting,
        );
//        $link = 'https://online.pekarina-zefir.ru/sales/control/deal/update/id/'.$id;

//if(!empty($_GET['type']) && $_GET['type'] == 'eng') {
//
//    $lead->attachTag('eng');
//    $contact->attachTag('eng');
//}


//$lead->attachTag('Оплата '.date('Y-m-d'));

//if($_GET['payed_money'] > 0)
//    $lead->attachTag('автооплата');

        $cost_money = str_replace(['руб.', ' '], "", $cost_money);
        if ((int)$cost_money !== 0)
            $lead->sale = (int)$cost_money;
        $lead->save();

        if ($_GET['payed_money'] === $_GET['cost_money']) {

            if ($_GET['cost_money'] === '0 руб.') {

                $lead->status_id = 53958366;
                $lead->attachTag('БесплатныйУрок');

            } else
                $lead->status_id = 142;

        } elseif ($_GET['payed_money'] === '0 руб.') {

            $lead->status_id = 53958366;
        } else
            $lead->status_id = 53958370;
    }
}
