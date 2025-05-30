<?php

namespace App\Services\GetCourse;

use App\Models\Integrations\GetCourse\Form;
use App\Models\Integrations\GetCourse\FormNote;
use App\Models\Integrations\GetCourse\Setting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Notes;
use App\Services\amoCRM\Models\Tags;

abstract class FormSender
{
    public static function send(
        Client $amoApi,
        Form $form,
        Setting $setting) :string
    {
        if ($amoApi->user->account->zone == 'ru')

            $contact = Contacts::search([
                'Телефоны' => [$form->phone],
                'Почта'    => $form->email,
            ], $amoApi);

        if (empty($contact) || $contact == null) {

            $contact = Contacts::create($amoApi, $form->name);

            $contact = Contacts::update($contact, [
                'Телефоны' => [$form->phone],
                'Почта'    => $form->email,
            ]);
        } else
            $lead = Leads::search($contact, $amoApi);

        if (empty($lead)) {

            $lead = Leads::create($contact, [
                'status_id' => $setting->status_id_form,
                'responsible_user_id' => $setting->responsible_user_id_form ?? $setting->response_user_default,
            ], $setting->lead_name_form);

            Leads::setUtms($lead, [
                'utm_source'   => $form->utm_source,
                'utm_medium'   => $form->utm_medium,
                'utm_content'  => $form->utm_content,
                'utm_term'     => $form->utm_term,
                'utm_campaign' => $form->utm_campaign,
                'utm_referrer' => $form->utm_referrer,
            ]);
        }

        Notes::addOne($lead, FormNote::create($form));

        Tags::add($lead, $setting->tag_form);

        $form->lead_id    = $lead->id;
        $form->contact_id = $contact->id;
        $form->status     = Form::STATUS_OK;
        $form->save();

        return 1;
    }
}
