<?php

namespace App\Services\YClients;

use App\Models\Integrations\YClients\Record;
use InvalidArgumentException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Ufee\Amo\Base\Collections\Collection;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Models\Lead;

abstract class Leads
{
    public static function search($contact, $client, int|array|null $pipelines = null): bool|Lead
    {
         return $contact->leads->filter(function($lead) use ($client, $pipelines) {
             return self::isLeadAllowedForSync($lead, $pipelines);
        })?->first();
    }

    public static function searchAll(Contact $contact, $client, int|array|null $pipelines = null): ?Collection
    {
        return AmoTransportRetry::run(function (int $attempt) use ($contact, $pipelines): ?Collection {
            $currentContact = $contact;

            if ($attempt > 1) {
                $currentContact = $contact->service->find($contact->id);

                if (!$currentContact instanceof Contact || (int)$currentContact->id !== (int)$contact->id) {
                    throw new \RuntimeException('Existing amoCRM contact not found while retrying lead search.');
                }
            }

            return $currentContact->leads->filter(function ($lead) use ($pipelines) {
                return self::isLeadAllowedForSync($lead, $pipelines);
            });
        }, ['operation' => 'lead_search', 'contact_id' => $contact->id]);
    }

    public static function isLeadAllowedForSync(object $lead, int|array|null $pipelines = null): bool
    {
        if ((int)$lead->status_id === 143 || (int)$lead->status_id === 142) {
            return false;
        }

        if ($pipelines === null) {
            return true;
        }

        if (is_array($pipelines)) {
            return in_array((int)$lead->pipeline_id, array_map('intval', $pipelines), true);
        }

        return (int)$lead->pipeline_id === (int)$pipelines;
    }

    public static function searchByRecordId(Contact $contact, int|string $recordId, array $fieldIds): ?Lead
    {
        if ($fieldIds === []) {
            return null;
        }

        return AmoTransportRetry::run(function (int $attempt) use ($contact, $recordId, $fieldIds): ?Lead {
            $currentContact = $attempt > 1 ? $contact->service->find($contact->id) : $contact;
            if (!$currentContact instanceof Contact || (int) $currentContact->id !== (int) $contact->id) {
                throw new \RuntimeException('Existing amoCRM contact not found during YClients record ID search.');
            }

            $contactLeadIds = array_map('intval', $currentContact->leads_id);
            $loadedIds = [];
            $matches = [];

            // The SDK loads linked lead IDs in chunks. Do not filter out closed or other-pipeline leads.
            foreach ($currentContact->leads as $lead) {
                if (!$lead instanceof Lead || !in_array((int) $lead->id, $contactLeadIds, true)) {
                    throw new \RuntimeException('Unexpected amoCRM lead during YClients record ID search.');
                }
                $loadedIds[] = (int) $lead->id;
                if (in_array((string) $recordId, self::mappedRecordIds($lead, $fieldIds), true)) {
                    $matches[(int) $lead->id] = $lead;
                }
            }

            if (array_diff($contactLeadIds, $loadedIds)) {
                throw new \RuntimeException('Incomplete amoCRM lead list during YClients record ID search.');
            }
            if (count($matches) > 1) {
                throw new \RuntimeException('Multiple amoCRM leads match YClients record '.$recordId.': '.implode(', ', array_keys($matches)));
            }

            return $matches ? reset($matches) : null;
        }, ['operation' => 'lead_record_id_search', 'contact_id' => $contact->id, 'record_id' => $recordId]);
    }

    public static function hasMappedRecordId(Lead $lead, array $fieldIds): bool
    {
        return $fieldIds !== [] && self::mappedRecordIds($lead, $fieldIds) !== [];
    }

    /** @return list<string> */
    private static function mappedRecordIds(Lead $lead, array $fieldIds): array
    {
        $values = [];
        foreach ($lead->custom_fields as $field) {
            if (!in_array((int) data_get($field, 'id'), $fieldIds, true)) {
                continue;
            }
            foreach (data_get($field, 'values', []) as $entry) {
                $value = data_get($entry, 'value');
                if (is_scalar($value) && !is_bool($value) && trim((string) $value) !== '') {
                    $values[] = trim((string) $value);
                }
            }
        }

        return $values;
    }

    public static function firstUnlinkedLead(iterable $leads, callable $isLinkedToAnotherRecord): ?object
    {
        foreach ($leads as $lead) {
            if (!$isLinkedToAnotherRecord($lead)) {
                return $lead;
            }
        }

        return null;
    }

    public static function ensureContactLinked(
        \App\Services\amoCRM\Client $amoApi,
        int $leadId,
        int $contactId,
    ): void {
        if ($leadId <= 0 || $contactId <= 0) {
            throw new InvalidArgumentException('Existing amoCRM lead and contact IDs are required for linking.');
        }

        AmoTransportRetry::run(function () use ($amoApi, $leadId, $contactId): void {
            // Re-read on every attempt: a timed-out link request may already have succeeded.
            $contactIds = self::currentContactIds($amoApi, $leadId);
            if (in_array($contactId, $contactIds, true)) {
                return;
            }

            $link = ['to_entity_id' => $contactId, 'to_entity_type' => 'contacts'];
            if ($contactIds === []) {
                $link['metadata'] = ['is_main' => true];
            }

            // Add only this relationship; never replace other contacts or their main flag.
            $amoApi->requestV4('POST', '/api/v4/leads/'.$leadId.'/link', [$link]);

            if (!in_array($contactId, self::currentContactIds($amoApi, $leadId), true)) {
                throw new \RuntimeException("amoCRM contact {$contactId} link to lead {$leadId} was not confirmed.");
            }
        }, ['operation' => 'lead_contact_link', 'lead_id' => $leadId, 'contact_id' => $contactId]);
    }

    private static function currentContactIds(\App\Services\amoCRM\Client $amoApi, int $leadId): array
    {
        $lead = $amoApi->requestV4('GET', '/api/v4/leads/'.$leadId, [], ['with' => 'contacts']);
        if ((int) ($lead['id'] ?? 0) !== $leadId || !empty($lead['is_deleted'])) {
            throw new \RuntimeException('Existing amoCRM lead was not found while checking contact links.');
        }

        $contacts = data_get($lead, '_embedded.contacts', []);
        if (!is_array($contacts)) {
            throw new \RuntimeException('Invalid amoCRM lead contacts response.');
        }

        $ids = [];
        foreach ($contacts as $contact) {
            if (!is_array($contact) || (int) ($contact['id'] ?? 0) <= 0) {
                throw new \RuntimeException('Invalid amoCRM lead contact ID.');
            }
            $ids[] = (int) $contact['id'];
        }

        return $ids;
    }

    public static function recoverCreated(
        \App\Services\amoCRM\Client $amoApi,
        Record $record,
        ?int $contactId,
        object $status,
        int $startedAt,
    ): ?Lead {
        $matches = [];
        for ($page = 1; $page <= 5; $page++) {
            $response = AmoTransportRetry::run(fn () => $amoApi->requestV4('GET', '/api/v4/leads', [], [
                'query' => 'Запись #'.$record->record_id,
                'with' => 'contacts', 'page' => $page, 'limit' => 250,
            ]));
            foreach (data_get($response, '_embedded.leads', []) as $lead) {
                if (($lead['name'] ?? '') !== 'Запись #'.$record->record_id
                    || (int) ($lead['pipeline_id'] ?? 0) !== (int) $status->pipeline_id
                    || (int) ($lead['created_at'] ?? 0) < $startedAt - 5
                    || (int) ($lead['created_at'] ?? 0) > $startedAt + 300) {
                    continue;
                }
                $contacts = array_map('intval', array_column(data_get($lead, '_embedded.contacts', []), 'id'));
                if ($contactId ? !in_array($contactId, $contacts, true) : $contacts !== []) {
                    continue;
                }
                $ownedElsewhere = Record::query()->where('account_id', $record->account_id)
                    ->where('lead_id', $lead['id'])
                    ->where(fn ($query) => $query->where('record_id', '!=', $record->record_id)
                        ->orWhere('company_id', '!=', $record->company_id))->exists();
                if (!$ownedElsewhere) {
                    $matches[(int) $lead['id']] = true;
                }
            }
            if (empty(data_get($response, '_links.next.href'))) {
                return count($matches) === 1 ? static::get($amoApi, (int) array_key_first($matches)) : null;
            }
        }

        // An incomplete or ambiguous search is not permission to POST again.
        return null;
    }

    public static function create(
        ?Contact $contact,
        object $objectStatus,
        Record $record,
        ?int $responsibleUserId = null,
        ?\App\Services\amoCRM\Client $amoApi = null,
        ?EntityCreateGuard $guard = null,
    ): Lead
    {
        $statusId = (int)($objectStatus->status_id ?? 0);
        $pipelineId = (int)($objectStatus->pipeline_id ?? 0);

        if ($statusId <= 0 || $pipelineId <= 0) {
            throw new InvalidArgumentException('Invalid amoCRM status/pipeline mapping for YClients lead create.');
        }

        $lead = $contact
            ? $contact->createLead()
            : $amoApi->service->leads()->create();

        $lead->name = 'Запись #'.$record->record_id;
        $lead->status_id = $statusId;
        $lead->pipeline_id = $pipelineId;

        if ($responsibleUserId) {
            $lead->responsible_user_id = $responsibleUserId;
        }

        $save = function () use ($lead): Lead {
            $lead->save();
            return $lead;
        };
        $guard ? $guard->create($save) : $save();

        return $lead;
    }

    public static function update(
        Lead $lead,
        object $objectStatus,
        Record $record,
        ?int $responsibleUserId = null
    ): Lead
    {
        if ((int)$lead->id <= 0) {
            throw new InvalidArgumentException('Existing amoCRM lead ID is required for YClients lead update.');
        }

        return AmoTransportRetry::run(function (int $attempt) use ($lead, $objectStatus, $record, $responsibleUserId): Lead {
            $currentLead = $lead;

            if ($attempt > 1) {
                $currentLead = $lead->service->find($lead->id);

                if (!$currentLead instanceof Lead || (int)$currentLead->id !== (int)$lead->id) {
                    throw new \RuntimeException('Existing amoCRM lead not found while retrying YClients update.');
                }
            }

            return self::updateWithConflictRetry($currentLead, $objectStatus, $record, $responsibleUserId);
        }, [
            'record_db_id' => $record->id,
            'record_id' => $record->record_id,
            'account_id' => $record->account_id,
            'setting_id' => $record->setting_id,
            'lead_id' => $lead->id,
        ]);
    }

    private static function updateWithConflictRetry(
        Lead $lead,
        object $objectStatus,
        Record $record,
        ?int $responsibleUserId
    ): Lead
    {
        $statusId = (int)($objectStatus->status_id ?? 0);
        $pipelineId = (int)($objectStatus->pipeline_id ?? 0);

        if ($statusId <= 0 || $pipelineId <= 0) {
            throw new InvalidArgumentException('Invalid amoCRM status/pipeline mapping for YClients lead update.');
        }

        $currentLead = $lead;
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            self::fillLeadForUpdate($currentLead, $statusId, $pipelineId, $record, $responsibleUserId);

            try {
                $currentLead->save();

                return $currentLead;
            } catch (Throwable $exception) {
                if (!self::isAmoLastModifiedConflict($exception) || $attempt >= $maxAttempts) {
                    throw $exception;
                }

                $freshLead = self::reloadLead($currentLead);

                if (!$freshLead) {
                    throw $exception;
                }

                Log::warning('amoCRM lead update conflict, retrying with fresh lead state', [
                    'lead_id' => $currentLead->id,
                    'record_id' => $record->record_id,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'error' => $exception->getMessage(),
                ]);

                usleep(250000 * $attempt);
                $currentLead = $freshLead;
            }
        }

        return $currentLead;
    }

    private static function fillLeadForUpdate(
        Lead $lead,
        int $statusId,
        int $pipelineId,
        Record $record,
        ?int $responsibleUserId = null
    ): void
    {
        $lead->status_id = $statusId;
        $lead->pipeline_id = $pipelineId;

        if ($responsibleUserId) {
            $lead->responsible_user_id = $responsibleUserId;
        }
    }

    private static function isAmoLastModifiedConflict(Throwable $exception): bool
    {
        return Str::contains($exception->getMessage(), 'Last modified date is older than in', true);
    }

    private static function reloadLead(Lead $lead): ?Lead
    {
        if (!$lead->id) {
            return null;
        }

        try {
            $freshLead = $lead->service->find($lead->id);

            return $freshLead instanceof Lead ? $freshLead : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function get($client, $id) : ?Lead
    {
        return AmoTransportRetry::run(
            fn () => $client->service->leads()->find($id),
            ['operation' => 'lead_get', 'lead_id' => $id],
        );
    }
}
