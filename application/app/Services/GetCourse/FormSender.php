<?php

namespace App\Services\GetCourse;

use App\Models\Integrations\Bizon\Viewer;
use App\Models\Integrations\GetCourse\Form;
use App\Models\Integrations\GetCourse\Order;
use App\Models\Integrations\GetCourse\Setting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Tags;

abstract class FormSender
{
    public static function send(
        Client $amoApi,
        Form $form,
        Setting $setting) :string
    {
        $contact = Contacts::search([
            'Телефоны' => [$form->phone],
            'Почта'    => $form->email
        ], $amoApi);

        if ($contact == null) {
            $contact = Contacts::create($amoApi, $form->name);
        }

        $lead = Leads::search($contact, $amoApi);

        if (!$lead)
            $lead = Leads::create($contact, [
                'status_id' => $setting->status_id_form,
                'responsible_user_id' => $setting->responsible_user_id_form,//TODO
            ], 'Новая регистрация Геткурс');//TODO можно сделать своим

//            $note = Notes::add($lead, []);

        Tags::add($lead, ['РегистрацияГеткурс']);//TODO default tag

        $form->lead_id    = $lead->id;
        $form->contact_id = $contact->id;
        $form->status     = 1;
        $form->save();
//            $viewer->note_id    = $note->id;
    }
}
