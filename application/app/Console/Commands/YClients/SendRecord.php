<?php

namespace App\Console\Commands\YClients;

use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\amoCRM\Client;
use App\Services\YClients\Notes;
use App\Services\YClients\EntityCreateGuard;
use App\Services\YClients\RecordSyncLock;
use App\Services\YClients\YClients;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
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
    protected $signature = 'yc:send-record {record_id} {account_id} {setting_id} {--skip-note} {--lock-owner=} {--defer-status}';

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

        return RecordSyncLock::run((int) $recordId, fn () => $this->handleLocked(), $this->option('lock-owner'));
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

        if ((int) $record->account_id !== (int) $account->id || (int) $record->setting_id !== (int) $setting->id) {
            throw new \InvalidArgumentException('YClients record account/setting scope mismatch.');
        }

        $amoMapping = $setting->amoMappingForCompany($record->company_id);
        $objectStatus = $record->getStatusId($setting);

        if (empty($objectStatus?->status_id) || empty($objectStatus?->pipeline_id)) {
            return $this->failRecord(
                $record,
                'Некорректная настройка статусов/воронки - нет смысла продолжать.'
            );
        }

        $amoApi = app(Client::class, ['account' => $account])->init();
        $ycApi = (new YClients($setting));

        $lead = null;
        $responsibleUserId = $setting->responsibleUserIdForRecord($record);
        $client = $record->scopedClient();

//        Log::info('YClients responsible mapping resolved.', [
//            'record_db_id' => $record->id,
//            'record_id' => $record->record_id,
//            'company_id' => $record->company_id,
//            'created_user_id' => $record->created_user_id,
//            'amo_responsible_user_id' => $responsibleUserId,
//        ]);

        if (!$client && !empty($record->client_id)) {
            return $this->failRecord($record, 'YClients client not found for record in scoped lookup.');
        }

        //CONTACT

        if ($client) {
            $contact = ServiceContact::updateOrCreate($client, $amoApi, $responsibleUserId);

            if ($contact->id) {
                $contact = ServiceContact::get($amoApi, $contact->id) ?: $contact;
            }
        } else {
            $contact = null;
        }

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

        $createGuard = EntityCreateGuard::forLead($record);

        if (empty($lead) && $createGuard->exists()) {
            $lead = $createGuard->recover(
                fn (int $id) => ServiceLead::get($amoApi, $id),
                fn (int $startedAt) => ServiceLead::recoverCreated($amoApi, $record, $contact?->id, $objectStatus, $startedAt),
            );
        }

        if (empty($lead)) {

            //уже прокинутая в амо сделка
            $recordDouble = Record::query()
                ->where('record_id', $record->record_id)
                ->where('id', '!=', $record->id)
                ->whereNotNull('lead_id')
                ->where('account_id', $account->id)
                ->where('company_id', $record->company_id)
                ->first();

            if (!empty($recordDouble) && $recordDouble->lead_id) {
                $lead = $recordDouble->isLeadOwnedByAnotherYClientsRecord()
                    ? null
                    : ServiceLead::get($amoApi, $recordDouble->lead_id);

            } elseif ($contact) {
                // поиск открытой сделки у контакта в нужной воронке
                $leadCollection = ServiceLead::searchAll($contact, $amoApi, $amoMapping['pipelines']);

                if ($leadCollection->count() > 0) {
                    $lead = ServiceLead::firstUnlinkedLead(
                        $leadCollection,
                        fn ($candidateLead): bool => Record::query()
                            ->where('lead_id', $candidateLead->id)
                            ->where('record_id', '!=', $record->record_id)
                            ->where('account_id', $account->id)
                            ->exists()
                    );
                } else {
                    $lead = null;
                }
            } else {
                $lead = null;
            }
        }

        if (empty($lead) && (int)$record->attendance === 3) {
            Log::info('YClients delete webhook ignored because existing amoCRM lead was not found.', [
                'record_db_id' => $record->id,
                'record_id' => $record->record_id,
                'account_id' => $account->id,
                'setting_id' => $setting->id,
                'company_id' => $record->company_id,
                'client_id' => $record->client_id,
            ]);

            return $this->completeRecordWithoutLead($record);
        }

        $creatingLead = empty($lead);

        try {
            if (!$creatingLead) {
                if ($contact) {
                    ServiceLead::ensureContactLinked($amoApi, (int) $lead->id, (int) $contact->id);
                }

                $lead = ServiceLead::update(
                    $lead,
                    $objectStatus,
                    $record,
                    $responsibleUserId
                );
            } else {
                $lead = ServiceLead::create($contact, $objectStatus, $record, $responsibleUserId, $amoApi, $createGuard);
            }

            // Persist the acknowledged ID before link verification or optional notes can fail.
            $record->lead_id = $lead->id;
            $record->save();

            if ($creatingLead && $contact) {
                ServiceLead::ensureContactLinked($amoApi, (int) $lead->id, (int) $contact->id);
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

        // Reserve the optional note durably: Laravel release() reuses the original job
        // payload, so an in-memory "note attempted" flag cannot prevent duplicate POSTs.
        $noteGuard = EntityCreateGuard::forNote($record);
        if (!$this->option('skip-note') && !$noteGuard->exists()) {
            Notes::createNoteLead($ycApi, $record, $lead, $amoApi, $noteGuard);
        }

        if (!$this->option('defer-status')) {
            $record->refresh()->forceFill(['status' => Record::STATUS_SUCCESS, 'error_message' => null])->save();
        }

        return self::SUCCESS;
    }

    private function failRecord(Record $record, string $message, array $context = []): int
    {
        if ($this->option('defer-status')) {
            $this->error($this->formatErrorMessage($message, $context));
            return self::FAILURE;
        }

        $record->refresh();
        $record->status = Record::STATUS_FAILED;
        $record->error_message = $this->formatErrorMessage($message, $context);
        $record->save();

        return self::FAILURE;
    }

    private function completeRecordWithoutLead(Record $record): int
    {
        if (!$this->option('defer-status')) {
            $record->refresh()->forceFill(['status' => Record::STATUS_SUCCESS, 'error_message' => null])->save();
        }

        return self::SUCCESS;
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
