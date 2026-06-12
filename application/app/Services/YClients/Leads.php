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
        return $contact->leads->filter(function($lead) use ($client, $pipelines) {
            return self::isLeadAllowedForSync($lead, $pipelines);
        });
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

    public static function firstUnlinkedLead(iterable $leads, callable $isLinkedToAnotherRecord): ?object
    {
        foreach ($leads as $lead) {
            if (!$isLinkedToAnotherRecord($lead)) {
                return $lead;
            }
        }

        return null;
    }

    public static function create(
        $contact,
        object $objectStatus,
        Record $record,
        ?int $responsibleUserId = null
    ): Lead
    {
        $statusId = (int)($objectStatus->status_id ?? 0);
        $pipelineId = (int)($objectStatus->pipeline_id ?? 0);

        if ($statusId <= 0 || $pipelineId <= 0) {
            throw new InvalidArgumentException('Invalid amoCRM status/pipeline mapping for YClients lead create.');
        }

        $lead = $contact->createLead();

        $lead->name = 'Запись #'.$record->record_id;
        $lead->sale = $record->cost;
        $lead->status_id = $statusId;
        $lead->pipeline_id = $pipelineId;

        if ($responsibleUserId) {
            $lead->responsible_user_id = $responsibleUserId;
        }

        $lead->save();

        return $lead;
    }

    public static function update(
        Lead $lead,
        object $objectStatus,
        Record $record,
        ?int $responsibleUserId = null
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
        $lead->sale = $record->cost;
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
        return $client->service->leads()->find($id);
    }
}
