<?php

namespace App\Console\Commands\GetCourse;

use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Core\Account;
use App\Models\Integrations\GetCourse\Order;
use App\Models\Integrations\GetCourse\OrderNote;
use App\Models\Integrations\GetCourse\Setting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Notes;
use App\Services\amoCRM\Models\Tags;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Log;

class OrderSend extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:getcourse-order-send  {order} {account} {setting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     * @throws \Exception
     */
    public function handle()
    {
        $order = Order::find($this->argument('order'));
        $account = Account::find($this->argument('account'));
        $setting = Setting::find($this->argument('setting'));

        $rawSetting = json_decode($setting->order_settings, true);

        $setting = !empty($rawSetting[$order->template]) ? $rawSetting[$order->template] : throw new \Exception('no settings order getcourse');

        $amoApi = (new Client($account))
            ->setDelay(0.2)
            ->initLogs(Env::get('APP_DEBUG'));

        $statusId = Status::query()
            ->find($setting['status_id_order'])
            ?->status_id;

        if ($order->payed_money == $order->cost_money &&
            $setting['status_id_order_close']) {

            $statusClose = $statusId = Status::query()->find($setting['status_id_order_close']);

            if ($statusClose->exists())

                $statusId = $statusClose->status_id;
        }

        $responsibleId = Staff::query()
            ->find($setting['response_user_id_order'])
            ?->staff_id;

        $contact = Contacts::search([
            'Телефоны' => [$order->phone ?? null],
            'Почта' => $order->email ?? null,
        ], $amoApi, $account->zone);

        if (empty($contact))
            $contact = Contacts::create($amoApi, $order->name);
        else
            $lead = Leads::search($contact, $amoApi);

        $contact = Contacts::update($contact, [
            'Имя'       => $order->name,
            'Телефоны'  => [$order->phone ?? null],
            'Почта'     => $order->email ?? null,
            'Ответственный' => $responsibleId,
        ], $account->zone);

        if (empty($lead)) {

            $lead = Leads::create($contact, [
                'sale' => $order->cost_money,
                'status_id' => $statusId,
                'responsible_user_id' => $responsibleId,
            ], 'Новый заказ с Геткурс');
        } else
            $lead = Leads::update($lead, [
                'status_id' => $statusId,
                'sale'      => $order->cost_money,
            ], []);

        Log::info(__METHOD__,[
            'lead_id' => $lead->id,
            'status_id' => $statusId,
            'resp' => $responsibleId,
        ]);

        Tags::add($lead, $setting['tag'] ?? null);
        Tags::add($lead, $setting['tag_order'] ?? null);

        Notes::addOne($lead, OrderNote::create($order));

        $order->contact_id = $contact->id;
        $order->lead_id = $lead->id;
        $order->status = 1;
        $order->save();
    }
}
