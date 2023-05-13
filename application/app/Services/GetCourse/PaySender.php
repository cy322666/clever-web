<?php

namespace App\Services\GetCourse;

use App\Models\Integrations\Bizon\Setting;
use App\Models\Integrations\Bizon\Viewer;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Tags;

abstract class PaySender
{
    public static function send(
        Client $amoApi,
        Viewer $viewer,
        Setting $setting) :string
    {
        $contact = Contacts::search([
            'Телефоны' => [$viewer->phone],
            'Почта'    => $viewer->email
        ], $amoApi);

        if ($contact == null) {
            $contact = Contacts::create($amoApi, $viewer->username);
        }

        $lead = Leads::search($contact, $amoApi);

        if (!$lead)
            $lead = Leads::create($contact, [
                'status_id' => '',//TODO full pay
                'responsible_user_id' => '',//TODO
            ], 'Новый зритель вебинара');

//            $note = Notes::add($lead, []);

        Tags::add($lead, [
            $setting->tag,
            //TODO cold/hot
        ]);

        $viewer->lead_id    = $lead->id;
        $viewer->contact_id = $contact->id;
//            $viewer->note_id    = $note->id;
        $viewer->status     = 1;
        $viewer->save();

        return 1;
    }
}
