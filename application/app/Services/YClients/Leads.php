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
    public static function search($contact, $client, int|array $pipelines = null) : bool|Lead
    {
         return $contact->leads->filter(function($lead) use ($client, $pipelines) {

            if ($lead->status_id != 143 &&
                $lead->status_id != 142) {

                if($pipelines != null) {

                    if (is_array($pipelines)) {

                        if (in_array($lead->pipeline_id, $pipelines))

                            return true;

                    } elseif ($lead->pipeline_id == $pipelines)

                        return true;
                }

                return $lead;
            }
        })?->first();
    }

    public static function searchAll(Contact $contact, $client, int|array $pipelines = null): ?Collection
    {
        return $contact->leads->filter(function($lead) use ($client, $pipelines) {

            if ($lead->status_id != 143 &&
                $lead->status_id != 142) {

                if($pipelines != null) {

                    if (is_array($pipelines)) {

                        if (in_array($lead->pipeline_id, $pipelines))

                            return true;

                    } elseif ($lead->pipeline_id == $pipelines)

                        return true;
                }

                return false;
            }
        });
    }

    public static function create($contact, object $objectStatus, Record $record): Lead
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
        $lead->save();

        return $lead;
    }

    public static function update(Lead $lead, object $objectStatus, Record $record): Lead
    {
        $statusId = (int)($objectStatus->status_id ?? 0);
        $pipelineId = (int)($objectStatus->pipeline_id ?? 0);

        if ($statusId <= 0 || $pipelineId <= 0) {
            throw new InvalidArgumentException('Invalid amoCRM status/pipeline mapping for YClients lead update.');
        }

        self::fillLeadForUpdate($lead, $statusId, $pipelineId, $record);

        try {
            $lead->save();

            return $lead;
        } catch (Throwable $exception) {
            if (!self::isAmoLastModifiedConflict($exception)) {
                throw $exception;
            }

            $freshLead = self::reloadLead($lead);

            if (!$freshLead) {
                throw $exception;
            }

            Log::warning('amoCRM lead update conflict, retrying with fresh lead state', [
                'lead_id' => $lead->id,
                'record_id' => $record->record_id,
            ]);

            self::fillLeadForUpdate($freshLead, $statusId, $pipelineId, $record);
            $freshLead->save();

            return $freshLead;
        }
    }

    private static function fillLeadForUpdate(Lead $lead, int $statusId, int $pipelineId, Record $record): void
    {
        $lead->sale = $record->cost;
        $lead->status_id = $statusId;
        $lead->pipeline_id = $pipelineId;
    }

    private static function isAmoLastModifiedConflict(Throwable $exception): bool
    {
        return Str::contains(
            $exception->getMessage(),
            'Last modified date is older than in.',
            true
        );
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
