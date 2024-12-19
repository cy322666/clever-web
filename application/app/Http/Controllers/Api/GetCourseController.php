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
    public function order(User $user, Request $request, string $template)
    {
        $cost  = explode('.', $request->cost_money)[0] ?: 0;
        $payed = explode('.', $request->payed_money)[0] ?: 0;
        $left  = explode('.', $request->left_cost_money)[0] ?: 0;

        $order = Order::query()->create([
            'template'  => $template,
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

    public function form(User $user, Request $request, string $form)
    {
        $form = Form::query()->create([
            'form'    => $form,
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
