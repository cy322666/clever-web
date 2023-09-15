<?php

namespace App\Console\Commands\AlfaCRM;

use App\Models\amoCRM\Status;
use App\Models\Core\Account;
use App\Models\Integrations\Alfa\Setting;
use App\Models\Integrations\Alfa\Transaction;
use App\Services\AlfaCRM\Models\Customer;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Notes;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;
use Illuminate\Support\Env;

class OmissionSend extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:alfacrm-omission-send {transaction} {account} {setting}';

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
        $transaction = Transaction::find($this->argument('transaction'));
        $account = Account::find($this->argument('account'));
        $setting = Setting::find($this->argument('setting'));

        $amoApi = (new Client($account))
            ->init()
            ->setDelay(0.2)
            ->initLogs(Env::get('APP_DEBUG'));

        $alfaApi  = (new \App\Services\AlfaCRM\Client($setting))
            ->setBranch($transaction->alfa_branch_id)
            ->init();

        $customer = (new Customer($alfaApi))->get($transaction->alfa_client_id);

        $parentTransaction = Transaction::query()
            ->where('status', Setting::RECORD)
            ->where('alfa_branch_id', $transaction->alfa_branch_id)
            ->where('alfa_client_id', $transaction->alfa_client_id)
            ->first();

        if ($parentTransaction && $parentTransaction->exists() && $parentTransaction->amo_lead_id) {

            $lead = $amoApi->service
                ->leads()
                ->find($transaction->amo_lead_id);

            $contact = $lead->contact;
        }

        if (empty($contact)) {

            $contact = $amoApi->service
                ->contacts()
                ->search(Contacts::clearPhone($customer->phone[0] ?? null))
                ?->first();
        }

        if (empty($contact)) {

            $contact = Contacts::create($amoApi, $customer->name);
            $contact = Contacts::update($contact, [
                'Телефоны' => $customer->phone,
                'Почта'    => $customer->email[0],
            ]);
        }

        $link = Contacts::buildLink($amoApi, $contact->id);

        (new Customer($alfaApi))->update($transaction->alfa_client_id, [
            'web' => $link,
        ]);

        $lead = empty($lead) ? Leads::create($contact, [], 'Новая сделка из AlfaCRM') : Leads::search($contact, $amoApi);

        $statusId = Status::query()->find($setting->status_omission_1)->status_id;

        $lead = Leads::update($lead, ['status_id' => $statusId], []);

        Notes::addOne($lead, 'Синхронизировано с АльфаСРМ, ссылка на клиента '. $link);

        $lead->status_id = $setting->status_came_1;
        $lead->save();

        $transaction->amo_lead_id = $lead->id;
        $transaction->amo_contact_id = $contact->id;
        $transaction->status_id = $lead->status_id;
        $transaction->save();

        Notes::addOne($lead, 'Клиент пропустил/отменил пробное в AlfaCRM');
    }
}
