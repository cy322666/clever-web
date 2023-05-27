<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\amoCRM\Client;
use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * @throws Exception
     */
    public function redirect(Request $request): Factory|View|Application
    {
        Log::info(__METHOD__, $request->toArray());

        $account = Auth::user()->account;

        $account->client_id = $request->client_id;
        $account->code = $request->code;
        $account->save();

        (new Client($account))->init();

        return view('redirect');
    }

    public function secrets(Request $request)
    {
        Log::info(__METHOD__, $request->toArray());

//        $user = User::where('uuid', $request->input('user'))->first();
//
//        $access = $user->access()->where('service_name', 'amocrm')->first();
//
//        if(!$access) {
//
//            $access = new Access();
//            $access->user_id = $user->id;
//            $access->account_id = $user->account->id;
//        }
//
//        $access->service_name = 'amocrm';
//        $access->client_secret = $request->client_secret;
//        $access->client_id = $request->client_id;
//        $access->subdomain = explode($request->referer, '.amocrm.')[0];
//        //$access->state = $request->post('state');
//        $access->save();
    }
}
//TODO пуши в телегу
