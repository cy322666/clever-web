<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Integrations\GetCourse\Form;
use App\Models\Integrations\GetCourse\Order;
use App\Models\User;
use App\Services\amoCRM\Models\Contacts;
use Illuminate\Http\Request;

class GetCourseController extends Controller
{
    public function pay(User $user, Request $request)
    {

    }

    public function order(User $user, Request $request)
    {
        $order = Order::query()->create([
            'user_id'   => $user->id,
            'phone'     => $request->phone,
            'email'     => $request->email,
            'name'      => $request->name,
            'number'    => $request->number,
            'order_id'  => $request->id,
            'positions' => $request->positions,
            'status_order' => $request->status,
            'link'         => $request->link,
            'cost_money'      => preg_replace("/[^0-9]/", '', $request->cost_money),
            'payed_money'     => preg_replace("/[^0-9]/", '', $request->payed_money),
            'left_cost_money' => preg_replace("/[^0-9]/", '', $request->left_cost_money),
        ]);

        GetCourseOrderSend::dispatch(
            $order,
            $user->getcourse_settings,
            $user->account
        );
    }

    public function form(User $user, Request $request)
    {
        $form = Form::query()->create([
            'user_id' => $user->id,
            'phone'   => $request->phone,
            'email'   => $request->email,
            'name'    => $request->name,
            'utm_medium'  => $request->utm_medium,
            'utm_content' => $request->utm_content,
            'utm_source'  => $request->utm_source,
            'utm_term'    => $request->utm_term,
            'utm_campaign'=> $request->utm_campaign,
        ]);

        GetCourseFormSend::dispatch(
            $form,
            $user->getcourse_settings,
            $user->account,
        );
    }
}
