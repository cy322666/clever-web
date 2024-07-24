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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    //обычная установка
    public function redirect(Request $request): RedirectResponse
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

        $amoApi = (new Client($account->refresh()));

        if (!$amoApi->checkAuth()) {

            $amoApi->init();
        }

        $account->active = $amoApi->auth;
        $account->save();

        return redirect()->route('filament.app.resources.core.users.view', [
            'record' => $user,
            'auth'   => $amoApi->auth,
        ]);
    }

    public function form(Request $request)
    {
        Log::info(__METHOD__, $request->toArray());
    }

    //установка с ОР
    public function edtechindustry(Request $request)
    {
        Log::info(__METHOD__, $request->toArray());

        exit();
        //TODO создаем а не находим
        $user = User::query()
            ->where('uuid', $request->state)
            ->first();

        sleep(2);//observer работает

        $account = $user->account;

        $account->code = $request->code;
        $account->zone = explode('.', $request->referer)[2];
        $account->client_id = $request->client_id;
        $account->subdomain = explode('.', $request->referer)[0];
        $account->redirect_uri  = config('services.amocrm.redirect_uri');
        $account->client_secret = config('services.amocrm.client_secret');
        $account->save();

        $amoApi = (new Client($account->refresh()));

        if (!$amoApi->checkAuth()) {

            $amoApi->init();
        }

        $account->active = $amoApi->auth;
        $account->save();
    }

    public function off(Request $request)
    {
        Log::info(__METHOD__, $request->toArray());
    }
}
//TODO пуши в телегу
