<?php

namespace App\Console\Commands\YClients;

use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\amoCRM\Client;
use App\Services\YClients\Notes;
use App\Services\YClients\YClients;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
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
    protected $signature = 'yc:send-record {record_id} {account_id} {setting_id} {--skip-note}';

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
        $recordId = $this->argument('record_id');

        return Cache::store(config('cache.yclients_lock_store', 'database'))
            ->lock('yclients:send-record:' . $recordId, 120)
            ->block(60, fn () => $this->handleLocked());
    }

    /**
     * @throws Throwable
     * @throws ConnectionException
     */
    private function handleLocked(): int
    {
        /** @var Record $record */
        $record  = Record::query()->findOrFail($this->argument('record_id'));
        /** @var Account $account */
        $account = Account::query()->findOrFail($this->argument('account_id'));
        /** @var Setting $setting */
        $setting = Setting::query()->findOrFail($this->argument('setting_id'));

        $objectStatus = $record->getStatusId($setting);

        if (empty($objectStatus?->status_id) || empty($objectStatus?->pipeline_id)) {
            return $this->failRecord(
                $record,
                'Некорректная настройка статусов/воронки - нет смысла продолжать.'
            );
        }

        $amoApi = (new Client($account))->init();
        $ycApi = (new YClients($setting));

        $lead = null;
        $assignResponsible = false;
        $responsibleUserId = $setting->responsibleUserIdForRecord($record);
        $client = $record->scopedClient();

        Log::info('YClients responsible mapping resolved.', [
            'record_db_id' => $record->id,
            'record_id' => $record->record_id,
            'company_id' => $record->company_id,
            'created_user_id' => $record->created_user_id,
            'amo_responsible_user_id' => $responsibleUserId,
        ]);

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

            return $this->failRecord($record, 'YClients client not found for record in scoped lookup.');
        }

        //CONTACT

        if (!empty($client->contact_id)) {
            $contact = ServiceContact::get($amoApi, $client->contact_id);

            if (!$contact)
                $contact = ServiceContact::updateOrCreate($client, $amoApi, $responsibleUserId);
        } else
            $contact = ServiceContact::updateOrCreate($client, $amoApi, $responsibleUserId);

        // LEAD

        if ($record->lead_id) {
            if ($record->isLeadOwnedByAnotherYClientsRecord()) {
                Log::warning('YClients record has lead_id owned by another record, ignoring stale lead link.', [
                    'record_db_id' => $record->id,
                    'record_id' => $record->record_id,
                    'stale_lead_id' => $record->lead_id,
                    'lead_owner_record_id' => $record->leadOwnerRecord()?->record_id,
                    'account_id' => $account->id,
                ]);

                $record->lead_id = null;
            } else {
                $lead = ServiceLead::get($amoApi, $record->lead_id);
            }
        }

        if (empty($lead)) {

            //уже прокинутая в амо сделка
            $recordDouble = Record::query()
                ->where('record_id', $record->record_id)
                ->where('id', '!=', $record->id)
                ->whereNotNull('lead_id')
                ->where('account_id', $account->id)
                ->first();

            if (!empty($recordDouble) && $recordDouble->lead_id) {
                $lead = $recordDouble->isLeadOwnedByAnotherYClientsRecord()
                    ? null
                    : ServiceLead::get($amoApi, $recordDouble->lead_id);

                // уже привязывали сделку к записи
                if ($lead)
                    $assignResponsible = false;

            } else {
                // поиск открытой сделки у контакта в нужной воронке
                $leadCollection = ServiceLead::searchAll($contact, $amoApi, $setting->pipelines);

                if ($leadCollection->count() > 0) {
                    $lead = ServiceLead::firstUnlinkedLead(
                        $leadCollection,
                        fn ($candidateLead): bool => Record::query()
                            ->where('lead_id', $candidateLead->id)
                            ->where('record_id', '!=', $record->record_id)
                            ->where('account_id', $account->id)
                            ->exists()
                    );
                    $assignResponsible = !empty($lead);
                } else
                    $lead = null;
            }
        }

        try {
            if (!empty($lead)) {
                $lead = ServiceLead::update(
                    $lead,
                    $objectStatus,
                    $record,
                    $assignResponsible ? $responsibleUserId : null
                );
            } else {
                $lead = ServiceLead::create($contact, $objectStatus, $record, $responsibleUserId);
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

            $this->failRecord($record, 'YClients lead sync failed during save()', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            throw $e;
        }

        if (!$this->option('skip-note')) {
            Notes::createNoteLead($ycApi, $record, $lead, $amoApi);
        }

        $record->lead_id = $lead->id;
        $record->status = Record::STATUS_SUCCESS;
        $record->save();

        return self::SUCCESS;
    }

    private function failRecord(Record $record, string $message, array $context = []): int
    {
        $record->status = Record::STATUS_FAILED;
        $record->error_message = $this->formatErrorMessage($message, $context);
        $record->save();

        return self::FAILURE;
    }

    private function formatErrorMessage(string $message, array $context = []): string
    {
        $lines = [$message];

        foreach ($context as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $lines[] = $key . ': ' . $value;
        }

        return implode("\n", $lines);
    }
}
