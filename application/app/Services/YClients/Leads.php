<?php

namespace App\Services\YClients;

use App\Models\Integrations\YClients\Record;
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

    public static function create($contact, object $objectStatus, Record $record): Lead
    {
        $lead = $contact->createLead();

        $lead->name = 'Запись #'.$record->record_id;
        $lead->sale = $record->cost;
        $lead->status_id   = $objectStatus->status_id;
        $lead->pipeline_id = $objectStatus->pipeline_id;
        $lead->save();

        return $lead;
    }

    public static function update(Lead $lead, object $objectStatus, Record $record): Lead
    {
        $lead->sale = $record->cost;
        $lead->status_id   = $objectStatus->status_id;
        $lead->pipeline_id = $objectStatus->pipeline_id;
        $lead->save();

        return $lead;
    }

    public static function get($client, $id) : ?Lead
    {
        return $client->service->leads()->find($id);
    }
}
