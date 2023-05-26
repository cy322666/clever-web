<?php

namespace App\Services\GetCourse;

use App\Models\Integrations\Bizon\OrderNote;
use App\Models\Integrations\GetCourse\Order;
use App\Models\Integrations\GetCourse\Setting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Notes;
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

            $contact = Contacts::update($contact, [
                'Телефоны' => [$order->phone],
                'Почта'    => $order->email,
            ]);
        } else
            $lead = Leads::search($contact, $amoApi);

        if (empty($lead)) {

            $statusId = $order->left_cost_money == 0 ? $setting->status_id_order : $setting->status_id_order_close;

            $lead = Leads::create($contact, [
                'status_id' => $statusId,
                'responsible_user_id' => $setting->responsible_user_id_order ?? $setting->response_user_default,
            ], $setting->lead_name_order);
        }

        Notes::addOne($lead, OrderNote::create($order));

        Tags::add($lead, [$setting->tag_order]);

        $lead->sale = (int)$setting->cost_money;
        $lead->save();

        $order->lead_id    = $lead->id;
        $order->contact_id = $contact->id;
        $order->status     = Order::STATUS_OK;
        $order->save();

        return 1;
    }
}
