<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GetCourseOrderSend;
use App\Jobs\GetCourseRegistrationSend;
use App\Models\Integrations\GetCourse\Form;
use App\Models\Integrations\GetCourse\Order;
use Illuminate\Http\Request;

class GetCourseController extends Controller
{
    public function pay(Request $request)
    {

    }

    public function order(Request $request)
    {
        $order = Order::query()->create([

        ]);

        GetCourseOrderSend::dispatch($order);
    }

    public function registration(Request $request)
    {
        $form = Form::query()->create([

        ]);

        GetCourseRegistrationSend::dispatch($form);
    }
}
