<?php

namespace App\Http\Controllers\Api;

use App\Filament\Resources\Integrations\AlfaResource;
use App\Http\Controllers\Controller;
use App\Mail\SignUpWidget;
use App\Models\App;
use App\Models\User;
use App\Services\amoCRM\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    //обычная установка
    public function redirect(Request $request): RedirectResponse
    {
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

        if (!$amoApi->checkAuth())

            $amoApi->init();

        $account->active = $amoApi->auth;
        $account->save();

        return back()->with('data', [
            'record' => $user,
            'auth'   => $amoApi->auth,
        ]);
    }

    //переход через кнопку установить
    //логиним и отправляем на страницу настроек
    public function widget(Request $request)
    {
        $app = App::query()
            ->where('name', $request->widget)
            ->first();

        $pass = Str::random(10);

        $user = User::query()
            ->where('email', $request->email)
            ->first();

        if (!$user) {

            $user = User::query()
                ->create([
                    'name' => 'User '.$request->email,
                    'email' => $request->email,
                    'password' => Hash::make($pass),
                ]);

            //отправляем пароль на почту
            Mail::to($user->email)->queue(new SignUpWidget($user, $pass));
        }

        $settingClassName = trim($app->resource_name::getModel());

        // sleep(2);

        $query = $settingClassName::query();

        $record = $query
            ->where('user_id', $user->id)
            ->first();

        $redirectPath = route('filament.app.resources.integrations.'.$request->widget.'.edit', ['record' => $record->id]);

        $signedUrl = URL::temporarySignedRoute(
            'auto.login',
            now()->addMinutes(30),
            [
                'user' => $user->id,
                'redirect' => $redirectPath,
            ]);

        return redirect()->away($signedUrl);
    }

    public function form(Request $request)
    {
        Log::info(__METHOD__, $request->toArray());
    }

    //установка виджета
    public function install(Request $request)
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
