<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GetCourse\FormSend;
use App\Jobs\GetCourse\OrderSend;
use App\Models\Integrations\GetCourse\Form;
use App\Models\Integrations\GetCourse\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GetCourseController extends Controller
{
    public function pay(User $user, Request $request) {}

    public function order(User $user, Request $request)
    {
        Log::channel('input')->info(__METHOD__, $request->toArray());

        $cost  = str_replace(',00', $request->cost_money);
        $payed = str_replace(',00', $request->payed_money);
        $left  = str_replace(',00', $request->left_cost_money);

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
            'cost_money'      => preg_replace("/[^0-9]/", '', $cost),
            'payed_money'     => preg_replace("/[^0-9]/", '', $payed),
            'left_cost_money' => preg_replace("/[^0-9]/", '', $left),
        ]);

        OrderSend::dispatch(
            $order,
            $user->account,
            $user->getcourse_settings,
        );
    }

    public function form(User $user, Request $request)
    {
        Log::channel('input')->info(__METHOD__, $request->toArray());

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

        FormSend::dispatch(
            $form,
            $user->account,
            $user->getcourse_settings,
        );
    }
}
