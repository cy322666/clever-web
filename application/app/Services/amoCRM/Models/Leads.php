<?php


namespace App\Services\amoCRM\Models;


use App\Services\amoCRM\Client;
use Illuminate\Support\Facades\Log;
use Throwable;
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
    public static function search($contact, $client, int|array $pipelines = null)
    {
        return $contact->leads->filter(function($lead) use ($client, $pipelines) {

            if ($lead->status_id != 143 &&
                $lead->status_id != 142) {

                if($pipelines != null) {

                    if (is_array($pipelines)) {

                        if (in_array($lead->pipeline_id, $pipelines)) {

                            return true;
                        }
                    } elseif ($lead->pipeline_id == $pipelines) {

                        return true;
                    }
                } else
                    return true;
            }
        })->sortBy('created_at', 'DESC')?->first();
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

        if (!empty($utms['_ym_uid']) && !$lead->cf('_ym_uid')->getValue()) {

            $lead->cf('_ym_uid')->setValue($utms['_ym_uid']);
        }

        if (!empty($utms['roistat_visit']) && !$lead->cf('roistat_visit')->getValue()) {

            $lead->cf('roistat')->setValue($utms['roistat']);
        }

        if (!empty($utms['roistat']) && !$lead->cf('roistat')->getValue()) {

            $lead->cf('roistat')->setValue($utms['roistat']);
        }

        if (!empty($utms['referrer']) && !$lead->cf('referrer')->getValue()) {

            $lead->cf('referrer')->setValue($utms['referrer']);
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

    public static function setField(Lead $lead, string $fieldName, $value): Lead
    {
        try {
            $lead->cf($fieldName)->setValue($value);

        } catch (Throwable $e) {}

        return $lead;
    }
}
