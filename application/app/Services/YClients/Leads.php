<?php

namespace App\Services\YClients;

use Ufee\Amo\Models\Lead;

abstract class Leads
{
    //TODO не нужно?
//    public static function searchByStatus($contact, $client, int $pipeline_id, int $status_id) : ?array
//    {
//        $leads = [];
//
//        if($contact->leads) {
//
//            foreach ($contact->leads as $lead) {
//
//                if ($lead->status_id == $status_id && $lead->pipeline_id == $pipeline_id) {
//
//                    $lead = $client->service
//                        ->leads()
//                        ->find($lead->id);
//
//                    $leads = array_merge($leads, $lead);
//                }
//            }
//        }
//        return $leads;
//    }

    //TODO не нужно?
//    public static function searchAll($contact, $client, int|array $pipelines = null)
//    {
//        $leads = [];
//
//        if($contact->leads) {
//
//            foreach ($contact->leads as $lead) {
//
//                if ($lead->status_id != 143 && $lead->status_id != 142) {
//
//                    if ($pipelines != null) {
//
//                        if (is_array($pipelines))
//
//                            if (in_array($lead->pipeline_id, $pipelines))
//                                return $lead;
//
//                        elseif ($lead->pipeline_id == $pipelines)
//                            return $lead;
//                    }
//                }
//            }
//        }
//    }

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

    public static function create($contact, Lead $lead, int $statusId, int $pipelineId)
    {
        $lead = $contact->createLead();

        $lead->status_id   = $statusId;
        $lead->pipeline_id = $pipelineId;
        $lead->save();

        return $lead;
    }

    public static function update(Lead $lead, int $statusId, int $pipelineId)
    {
        $lead->status_id   = $statusId;
        $lead->pipeline_id = $pipelineId;
        $lead->save();

        return $lead;
    }

    public static function get($client, $id) : ?Lead
    {
        return $client->service->leads()->find($id);
    }
}
