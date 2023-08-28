<?php

namespace App\Console\Commands\GetCourse;

use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Core\Account;
use App\Models\Integrations\Bizon\Setting;
use App\Models\Integrations\GetCourse\Form;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Notes;
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
        Log::channel('getcourse-order')->info(__METHOD__.' > начало отправки order id : '.$this->argument('order'));

        $order   = Form::find($this->argument('order'));
        $setting = Setting::find($this->argument('setting'));
        $account = Account::find($this->argument('account'));

        $amoApi = (new Client($account))
            ->init()
            ->initLogs(Env::get('APP_DEBUG'));

        $statusId = Status::query()
            ->find($setting->status_id_form ?? $setting->status_id_default)
            ?->status_id;

        $responsibleId = Staff::query()
            ->find($setting->response_user_id_form ?? $setting->response_user_id_default)
            ?->staff_id;

        $contact = Contacts::search([
            'Телефоны' => [$order->phone],
            'Почта'    => $order->email,
        ], $amoApi);



        if ($this->order->payed_money == $this->order->cost_money) {

            $statusId = $this->setting->status_id_order_close ?? $this->setting->status_id_order;
        } else
            $statusId = $this->setting->status_id_order;

            $responsibleId = $this->setting->responsible_user_id_order ?? $this->setting->responsible_user_id_default;

        try {


//'Новый заказ GetCourse'

            $contact = Contacts::search([
                'Телефоны' => [$this->order->phone],
                'Почта'    => $this->order->email,
            ], $amoApi);

            if ($contact == null) {

                $contact = Contacts::create($amoApi, $this->order->name);
            }



        $order->contact_id = $contact->id;
        $order->lead_id = $lead->id;
        $order->status = 1;
        $order->save();

        Notes::addOne($lead, $this->order->text());

        return true;
    }
}
