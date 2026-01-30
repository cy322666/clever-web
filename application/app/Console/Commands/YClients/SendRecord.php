<?php

namespace App\Console\Commands\YClients;

use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\amoCRM\Client;
use App\Services\YClients\Notes;
use App\Services\YClients\YClients;
use Illuminate\Console\Command;
use App\Services\YClients\Leads as ServiceLead;
use App\Services\YClients\Contacts as ServiceContact;

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

        $ycApi = (new YClients($setting));

        //CONTACT

        if (!empty($record->client->contact_id)) {

            $contact = ServiceContact::get($amoApi, $record->client->contact_id);

            if (!$contact)
                $contact = ServiceContact::updateOrCreate($record->client, $amoApi);
        } else
            $contact = ServiceContact::updateOrCreate($record->client, $amoApi);

        // LEAD

        if ($record->lead_id)

            $lead = ServiceLead::get($amoApi, $record->lead_id);

        if (empty($lead)) {

            //уже прокинутая в амо сделка
            $recordDouble = Record::query()
                ->where('record_id', $record->record_id)
                ->where('id', '!=', $record->id)
                ->whereNotNull('lead_id')
                ->where('account_id', $account->id)
                ->first();

            if (!empty($recordDouble) && $recordDouble->lead_id) {

                $lead = ServiceLead::get($amoApi, $recordDouble->lead_id);

                // уже привязывали сделку к записи
                if ($lead)
                    ServiceLead::update($lead, $objectStatus, $record);

            } else {
                // поиск открытой сделки у контакта в нужной воронке
                $leadCollection = ServiceLead::searchAll($contact, $amoApi, $setting->pipelines);

                if ($leadCollection->count() > 0) {

                    foreach ($leadCollection as $lead) {

                        $recordDouble = Record::query()
                            ->where('lead_id', $lead->id)
                            ->where('record_id', '!=', $record->record_id)
                            ->first();

                        //сделка не привязана к какой то записи
                        if (!$recordDouble)

                            break;
                    }
                } else
                    $lead = null;
            }
        }

        if (!empty($lead))
            ServiceLead::update($lead, $objectStatus, $record);
        else
            $lead = ServiceLead::create($contact, $objectStatus, $record);

        Notes::createNoteLead($ycApi, $record, $lead);

        $record->lead_id = $lead->id;
        $record->status  = 1;
        $record->save();

        return self::SUCCESS;
    }
}
