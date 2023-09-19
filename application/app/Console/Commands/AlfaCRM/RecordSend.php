<?php

namespace App\Console\Commands\AlfaCRM;

use App\Models\Core\Account;
use App\Models\Integrations\Alfa\Branch;
use App\Models\Integrations\Alfa\Transaction;
use App\Models\Integrations\Alfa\Customer;
use App\Models\Integrations\Alfa\Setting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Notes;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;
use Illuminate\Support\Env;

class RecordSend extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:alfacrm-record-send {transaction} {account} {setting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     * @throws Exception
     * @throws GuzzleException
     */
    public function handle()
    {
        $transaction = Transaction::find($this->argument('transaction'));
        $account = Account::find($this->argument('account'));
        $setting = Setting::find($this->argument('setting'));

        $amoApi = (new Client($account))
            ->setDelay(0.2)
            ->initLogs(Env::get('APP_DEBUG'));

        $alfaApi = (new \App\Services\AlfaCRM\Client($setting))
            ->setBranch($transaction->alfa_branch_id)
            ->init();

        $lead = $amoApi->service
            ->leads()
            ->find($transaction->amo_lead_id);

        $contact = $lead->contact;

        if (!$contact)
            throw new Exception('Lead without contact');//TODO add note in lead

        $alfaApi->branchId = $setting::getBranchId($lead, $contact, $setting);

//        $fieldValues = $setting->getFieldValues($lead, $contact, $setting);

        $fieldsAlfacrm = [
            'phone' => $contact->cf('Телефон')->getValue(),
            'email' => $contact->cf('Email')->getValue(),
            'stage_id'  => $setting->stage_record_1,
            'name'      => $contact->name,
            'branch_id' => $setting->branch_id,
            'lead_status_id' => Branch::query()->find($setting->stage_record_1)->branch_id,
        ];

        $customer = $setting->customerUpdateOrCreate($fieldsAlfacrm, $alfaApi);//TODO разделить на сервисы это че за пздц

//        Field::prepareCreateLead($fieldValues, $amoApi, $alfaApi, $contact);

        $transaction->amo_contact_id = $contact->id;
        $transaction->alfa_client_id = $customer->id;
        $transaction->fields = json_encode($fieldsAlfacrm);
        $transaction->alfa_branch_id = $fieldsAlfacrm['branch_id'];
        $transaction->save();

        Notes::addOne($lead, 'Синхронизировано с АльфаСРМ, ссылка на лид '.Customer::buildLink($alfaApi, $customer->id));
    }
}
