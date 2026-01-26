<?php

namespace App\Console\Commands\AlfaCRM;

use App\Models\amoCRM\Status;
use App\Models\Core\Account;
use App\Models\Integrations\Alfa\Setting;
use App\Models\Integrations\Alfa\Transaction;
use App\Services\AlfaCRM\Client as alfaApi;
use App\Services\AlfaCRM\Models\Customer;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Notes;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Log;
use Nikitanp\AlfacrmApiPhp\Entities\CustomerTariff;

class PaySend extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:alfacrm-pay-send {transaction_id} {account_id} {setting_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     * @throws GuzzleException
     * @throws \Exception
     */
    public function handle()
    {
        $transaction = Transaction::query()->find($this->argument('transaction_id'));
        $account = Account::query()->find($this->argument('account_id'));
        $setting = Setting::query()->find($this->argument('setting_id'));

        $alfaApi  = (new alfaApi($setting))
            ->setBranch($transaction->branch_id)
            ->init();

        $amoApi = (new Client($account));

        if (!$transaction->amo_contact_id) {

            $customer = (new \Nikitanp\AlfacrmApiPhp\Entities\Customer(
                $alfaApi,
                $transaction->branch_id,
            ))->getFirst([
                'id' => $transaction->alfa_client_id
            ]);

            if ($customer) {

                $contact = Contacts::search([
                    'Телефоны' => $customer['phone'],
                    'Почта' => $customer['email'][0] ?? null,
                ], $this->amoApi);

                if ($contact) {

                    $transaction->amo_contact_id = $contact->id;
                    $transaction->amo_contact_name = $contact->name;
                    $transaction->amo_contact_email = $contact->cf('Email')->getValue();
                    $transaction->amo_contact_phone = $contact->cf('Телефон')->getValue();
                    $transaction->save();

                    //TODO поле сделать
                    $contact->cf('Ссылка в AlfaCRM')->setValue(
                        "https://podvodoinn.s20.online/company/{$transaction->alfa_branch_id}/customer/view?id={$model->alfa_client_id}"
                    );
                    $contact->save();
                }
            }
        }

        $contact = $amoApi
            ->service
            ->contacts()
            ->find($transaction->amo_contact_id);

        $leads = $contact->leads->toArray();

        foreach ($leads as $lead) {

            if ($lead['status_id'] !== 142 &&
                $lead['status_id'] !== 143) {

                $lead = $this->amoApi->leads()->find($lead['id']);

                Log::info(__METHOD__.' : у клиента есть открытая сделка в основной воронке');

                $transaction->amo_lead_id = $lead->id;

                $lead->status_id = 142;//TODO этап для оплаты
                $lead->save();

                Notes::addOne($lead, 'Клиент совершил оплату на сумму '.$request->fields_new['income']);

                $tariffs = (new CustomerTariff(
                    $alfaApi,
                    $request->branch_id)
                )->get(0, [
                    'customer_id' => $transaction->alfa_client_id,
                ]);

                if ($tariffs['total'] == 0) {

                    Notes::addOne($lead, 'От клиента получена оплата, но у него нет абонементов');

                    $transaction->status = Setting::NO_PAY;
                } else {

//                    $tariff = (new Tariff($this->alfaApi))->getFirst([
//                        'id' => $tariffs['items'][0]['tariff_id'],
//                    ]);

//                    $sale = explode('.', $tariff['price'])[0];

                    Log::info(__METHOD__. ' sale : '.$request->fields_new['income']);

                    $lead->sale = $request->fields_new['income'];
                    $lead->save();

                    Notes::addOne($lead, 'Карточка обновлена информацией абонемента');

                    $transaction->status = Setting::PAY;
                }

//                $tasks = $lead->tasks->toArray();
//
//                foreach ($tasks as $task) {
//
//                    if ($task['is_completed'] == false) {
//
//                        $taskDetail = $this->amoApi->tasks()->find($task['id']);
//                        $taskDetail->is_completed = true;
//                        $taskDetail->save();
//
//                        unset($taskDetail);
//                    }
//                }
//                Notes::addOne($lead, 'Задачи в сделке завершены');
            }
        }

        $transaction->save();
    }
}
