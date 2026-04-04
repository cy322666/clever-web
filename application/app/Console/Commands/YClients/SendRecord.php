<?php

namespace App\Console\Commands\YClients;

use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\amoCRM\Client;
use App\Services\YClients\Notes;
use App\Services\YClients\YClients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\YClients\Leads as ServiceLead;
use App\Services\YClients\Contacts as ServiceContact;
use Throwable;

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
        $lead = null;
        $client = $record->scopedClient();

        if (!$client) {
            Log::error('YClients client not found for record in scoped lookup.', [
                'record_db_id' => $record->id,
                'record_id' => $record->record_id,
                'client_id' => $record->client_id,
                'company_id' => $record->company_id,
                'account_id' => $record->account_id,
                'setting_id' => $record->setting_id,
                'user_id' => $record->user_id,
            ]);

            return self::FAILURE;
        }

        //CONTACT

        if (!empty($client->contact_id)) {
            $contact = ServiceContact::get($amoApi, $client->contact_id);

            if (!$contact)
                $contact = ServiceContact::updateOrCreate($client, $amoApi);
        } else
            $contact = ServiceContact::updateOrCreate($client, $amoApi);

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
                    $lead = ServiceLead::update($lead, $objectStatus, $record);

            } else {
                // поиск открытой сделки у контакта в нужной воронке
                $leadCollection = ServiceLead::searchAll($contact, $amoApi, $setting->pipelines);

                if ($leadCollection->count() > 0) {

                    foreach ($leadCollection as $lead) {

                        $recordDouble = Record::query()
                            ->where('lead_id', $lead->id)
                            ->where('record_id', '!=', $record->record_id)
                            ->where('account_id', $account->id)
                            ->first();

                        //сделка не привязана к какой то записи
                        if (!$recordDouble)

                            break;
                    }
                } else
                    $lead = null;
            }
        }

        try {
            if (!empty($lead)) {
                $lead = ServiceLead::update($lead, $objectStatus, $record);
            } else {
                $lead = ServiceLead::create($contact, $objectStatus, $record);
            }
        } catch (Throwable $e) {
            Log::error('YClients lead sync failed during save()', [
                'record_db_id' => $record->id,
                'record_id' => $record->record_id,
                'account_id' => $account->id,
                'setting_id' => $setting->id,
                'lead_id' => $lead?->id ?? $record->lead_id,
                'attendance' => $record->attendance,
                'cost' => $record->cost,
                'status_id' => $objectStatus->status_id ?? null,
                'pipeline_id' => $objectStatus->pipeline_id ?? null,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            throw $e;
        }

        Notes::createNoteLead($ycApi, $record, $lead, $amoApi);

        $record->lead_id = $lead->id;
        $record->status = Record::STATUS_SUCCESS;
        $record->save();

        return self::SUCCESS;
    }
}
