<?php

namespace App\Services\GetCourse;

use App\Models\Integrations\Bizon\Viewer;
use App\Models\Integrations\GetCourse\Order;
use App\Models\Integrations\GetCourse\Setting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Tags;

abstract class OrderSender
{
    public static function send(
        Client $amoApi,
        Order $order,
        Setting $setting) :string
    {
        $contact = Contacts::search([
            'Телефоны' => [$order->phone],
            'Почта'    => $order->email
        ], $amoApi);

        if ($contact == null) {
            $contact = Contacts::create($amoApi, $order->name);
        }

        $lead = Leads::search($contact, $amoApi);

        if (!$lead)
            $lead = Leads::create($contact, [
                'status_id' => $setting->status_id_order,//TODO success
                'responsible_user_id' => $setting->responsible_user_id_order,//TODO
            ], 'Новый зритель вебинара');//TODO можно сделать своим

//            $note = Notes::add($lead, []);

        Tags::add($lead, ['ЗаказГеткурс']);//TODO default tag

        $order->lead_id    = $lead->id;
        $order->contact_id = $contact->id;
        $order->status     = 1;
        $order->save();
//            $viewer->note_id    = $note->id;
    }
}
