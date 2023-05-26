<?php


namespace App\Services\amoCRM\Models;


use App\Services\amoCRM\Client;
use Illuminate\Support\Facades\Log;
use Ufee\Amo\Models\Lead;

abstract class Leads
{
    public static function searchByStatus($contact, $client, int $pipeline_id, int $status_id) : ?array
    {
        $leads = [];

        if($contact->leads) {

            foreach ($contact->leads as $lead) {

                if ($lead->status_id == $status_id && $lead->pipeline_id == $pipeline_id) {

                    $lead = $client->service
                        ->leads()
                        ->find($lead->id);

                    $leads = array_merge($leads, $lead);
                }
            }
        }
        return $leads;
    }

    //поиск активных в воронке
    public static function search($contact, $client, int $pipeline_id = null)
    {
        if($contact->leads) {

            foreach ($contact->leads as $lead) {

                if ($lead->status_id != 143 &&
                    $lead->status_id != 142) {

                    if($pipeline_id != null && $lead->pipeline_id == $pipeline_id) {

                        return $client->service
                            ->leads()
                            ->find($lead->id);
                    }
                }
            }
        }
    }

    public static function create($contact, array $params, ?string $leadname)
    {
        $lead = $contact->createLead();

        $lead->name = $leadname;

        if(!empty($params['sale']))
            $lead->sale = $params['sale'];

        if(!empty($params['responsible_user_id']))
            $lead->responsible_user_id = $params['responsible_user_id'];

        if(!empty($params['status_id']))
            $lead->status_id = $params['status_id'];

        $lead->contacts_id = $contact->id;
        $lead->save();

        return $lead;
    }

    public static function setUtms(Lead $lead, array $utms): Lead
    {
        if (!empty($utms['utm_source'])) {

            $lead->cf('utm_source')->setValue($utms['utm_source']);
        }
        if (!empty($utms['utm_content'])) {

            $lead->cf('utm_content')->setValue($utms['utm_content']);
        }
        if (!empty($utms['utm_term'])) {

            $lead->cf('utm_term')->setValue($utms['utm_term']);
        }
        if (!empty($utms['utm_campaign'])) {

            $lead->cf('utm_campaign')->setValue($utms['utm_campaign']);
        }
        if (!empty($utms['utm_medium']) && !$lead->cf('utm_medium')->getValue()) {

            $lead->cf('utm_medium')->setValue($utms['utm_medium']);
        }

        return $lead;
    }

    public static function update($lead, array $params, array $fields)
    {
        try {

            if($lead !== null) {

                if($fields) {

                    foreach ($fields as $key => $field) {

                        $lead->cf($key)->setValue($field);
                    }
                }

                if(!empty($params['responsible_user_id']))
                    $lead->responsible_user_id = $params['responsible_user_id'];

                if(!empty($params['status_id']))
                    $lead->status_id = $params['status_id'];

                $lead->updated_at = time();
                $lead->save();

                return $lead;
            }

        } catch (\Exception $exception) {

            Log::error(__METHOD__. ' : ошибка обновления '.$exception->getMessage(). ' , сделка : '.$lead->id);
        }
    }

    public static function get($client, $id)
    {
        try {
            return $client->service->leads()->find($id);

        } catch (\Exception $exception) {

            Log::error(__METHOD__. ' : '.$exception->getMessage(). ' , сделка : '.$id);
        }
    }
}
