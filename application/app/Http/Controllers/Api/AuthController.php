<?php

namespace App\Http\Controllers\Api;

use App\Filament\Resources\Core\UserResource;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\amoCRM\Client;
use Exception;
use Filament\Facades\Filament;
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
    public function redirect(Request $request): string
    {
        Log::info(__METHOD__, $request->toArray());

        $user = User::query()
            ->where('uuid', $request->state)
            ->first();

        $account = $user->account;

        $account->code = $request->code;
        $account->zone = explode('.', $request->referer)[2];
        $account->client_id = $request->client_id;
        $account->subdomain = explode('.', $request->referer)[0];
        $account->redirect_uri  = config('services.amocrm.redirect_uri');
        $account->client_secret = config('services.amocrm.client_secret');
        $account->save();

        (new Client($account))->init();

        redirect(route('filament.app.resources.core.users.view', ['record' => $user]));
    }

    public function secrets(Request $request)
    {
        Log::info(__METHOD__, $request->toArray());

//        $user = User::query()
//            ->where('uuid', $request->input('user'))
//            ->first();
//
//        $account = $user->account;
//
//        $account->subdomain = explode($request->referer, '.amocrm.')[0];//TODO
//        $account->save();
        //$access->state = $request->post('state');
    }

    public function off(Request $request)
    {
        Log::info(__METHOD__, $request->toArray());
    }
}
//TODO пуши в телегу
