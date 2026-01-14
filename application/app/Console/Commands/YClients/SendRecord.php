<?php

namespace App\Console\Commands\YClients;

use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Tags;
use App\Services\YClients\Notes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use App\Services\YClients\Leads as ServiceLead;
use App\Services\YClients\Contacts as ServiceContact;
use Vgrish\Yclients\Yclients;

class SendRecord extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'yc:send-record {record} {account} {setting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return int
     * @throws \Exception
     */
    public function handle()
    {
        $record  = $this->argument('record');
        $account = $this->argument('account');
        $setting = $this->argument('setting');

        $objectStatus = $record->getStatusId($setting);
dd($objectStatus);
        $amoApi = (new Client($account))->init();

        $ycApi = Yclients::getInstance()
            ->setPartnerToken($setting->partner_token)
            ->setUserToken($setting->user_token);

        //уже прокинутая в амо сделка
        $recordDouble = Record::query()
            ->where('record_id', $record->record_id)
            ->where('id', '!=', $record->id)
            ->where('lead_id', '!=', null)
            ->where('user_id', $setting->id)
            ->where('account_id', $account->id)
            ->first();

        if (!empty($record->client->contact_id)) {

            $contact = ServiceContact::get($amoApi, $record->client->contact_id);

            if (!$contact)
                $contact = ServiceContact::updateOrCreate($record->client);
        } else
            $contact = ServiceContact::updateOrCreate($record->client, $amoApi);

        if (!empty($recordDouble) && $recordDouble->lead_id)

            $leadDouble = ServiceLead::get($amoApi, $recordDouble->lead_id);

        //уже привязывали сделку к записи
        if (!empty($recordDouble) && !empty($leadDouble)) {

            $lead = ServiceLead::get($amoApi, $recordDouble->lead_id);

            if ($lead)
                ServiceLead::update($record, $lead, $objectStatus->status_id);
            else
                //не нашли сделку к которой привязывались
                $lead = ServiceLead::create($contact, $record, $objectStatus->status_id);

        } else {
            //поиск открытой сделки у контакта
            $lead = ServiceLead::search($contact, $record);

            if ($lead)
                ServiceLead::update($lead, $objectStatus->status_id, $objectStatus->pipeline_id);
            else
                $lead = ServiceLead::create($contact, $record, $objectStatus->status_id);
        }

        Notes::createNoteLead($ycApi, $record, $lead);

        $record->lead_id = $lead->id;
        $record->status = 1;
        $record->save();
    }
}
