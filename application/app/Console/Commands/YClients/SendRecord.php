<?php

namespace App\Console\Commands\YClients;

use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\amoCRM\Client;
use App\Services\YClients\Notes;
use Illuminate\Console\Command;
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
    protected $signature = 'yc:send-record {record_id} {account_id} {setting_id}';

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
        /** @var Record $record */
        $record  = Record::query()->findOrFail($this->argument('record_id'));
        /** @var Account $account */
        $account = Account::query()->findOrFail($this->argument('account_id'));
        /** @var Setting $setting */
        $setting = Setting::query()->findOrFail($this->argument('setting_id'));

        $objectStatus = $record->getStatusId($setting);

        if (empty($objectStatus?->status_id) || empty($objectStatus?->pipeline_id)) {
            // Некорректная настройка статусов/воронки – нет смысла продолжать
            return self::FAILURE;
        }

        $amoApi = (new Client($account))->init();

        $ycApi = Yclients::getInstance()
            ->setPartnerToken($setting->partner_token)
            ->setUserToken($setting->user_token);

        //CONTACT

        if (!empty($record->client->contact_id)) {

            $contact = ServiceContact::get($amoApi, $record->client->contact_id);

            if (!$contact)
                $contact = ServiceContact::updateOrCreate($record->client, $amoApi);
        } else
            $contact = ServiceContact::updateOrCreate($record->client, $amoApi);

        // LEAD

        //уже прокинутая в амо сделка
        $recordDouble = Record::query()
            ->where('record_id', $record->record_id)
            ->where('id', '!=', $record->id)
            ->whereNotNull('lead_id')
            ->where('user_id', $setting->id)
            ->where('account_id', $account->id)
            ->first();

        if (!empty($recordDouble) && $recordDouble->lead_id) {

            $lead = ServiceLead::get($amoApi, $recordDouble->lead_id);

            // уже привязывали сделку к записи
            if ($lead)
                ServiceLead::update($lead, $objectStatus, $record);

        } else {
            // поиск открытой сделки у контакта в нужной воронке
            $lead = ServiceLead::search($contact, $amoApi, $objectStatus->pipeline_id);

            //тут может быть привязанная сделка к другой записи
            //по-хорошему надо забирать коллекцию активных и в них искать не привязанную
            if ($lead)
                ServiceLead::update($lead, $objectStatus, $record);
            else
                $lead = ServiceLead::create($contact, $objectStatus, $record);
        }

        Notes::createNoteLead($ycApi, $record, $lead);

        $record->lead_id = $lead->id;
        $record->status  = 1;
        $record->save();

        return self::SUCCESS;
    }
}
