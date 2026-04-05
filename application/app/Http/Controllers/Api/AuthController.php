<?php

namespace App\Http\Controllers\Api;

use App\Filament\Resources\Integrations\AlfaResource;
use App\Http\Controllers\Controller;
use App\Mail\SignUp;
use App\Mail\SignUpWidget;
use App\Models\App;
use App\Models\User;
use App\Services\Integrations\IntegrationProvisioningService;
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

        if (!$amoApi->checkAuth()) {
            $amoApi->init();
        }

        $account->active = $amoApi->auth;
        $account->save();

        Mail::to($user->email)->queue(new SignUp($user));

        $redirectPath = $this->sanitizeRelativeRedirect(
            $request->input('uri'),
            route('filament.app.pages.dashboard', [], false)
        );

        return redirect()
            ->to($redirectPath)
            ->with([
                'auth' => $amoApi->auth,
            ]);
    }

    //переход через кнопку установить
    //логиним и отправляем на страницу настроек
    public function widget(Request $request, IntegrationProvisioningService $provisioning): RedirectResponse
    {
        $query = $request->getQueryString(); // "amp;email=...%22&widget=tilda" или уже частично декод

        $query = urldecode($query);          // %xx -> символы
        $query = html_entity_decode($query); // &amp; -> &
        $query = str_replace('amp;', '', $query); // ключ amp;email -> email

        parse_str($query, $params);

        $email = isset($params['email']) ? trim($params['email'], "\"' \t\n\r\0\x0B") : null;
        $widget = (string)($params['widget'] ?? '');
        $widget = Str::of($widget)->lower()->trim()->toString();

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-z0-9\-]+$/', $widget)) {
            abort(422, 'Invalid widget install payload.');
        }

        $resourceClass = (string)data_get(config("integrations.definitions.{$widget}"), 'resource', '');
        if ($resourceClass === '') {
            $resourceClass = (string)App::query()
                ->where('name', $widget)
                ->whereNotNull('resource_name')
                ->value('resource_name');
        }

        if ($resourceClass === '' || !class_exists($resourceClass) || !method_exists($resourceClass, 'getModel')) {
            abort(404, 'Widget integration is not supported.');
        }

        $user = User::query()
            ->where('email', $email)
            ->first();

        // Existing account: do not auto-login from external widget callback.
        if ($user) {
            return redirect()->to(route('filament.app.auth.login'));
        }

        $pass = Str::random(10);
        $user = User::query()
            ->create([
                'name' => 'User ' . $email,
                'email' => $email,
                'password' => Hash::make($pass),
            ]);

        // Отправляем пароль на почту.
        Mail::to($user->email)->queue(new SignUpWidget($user, $pass));

        $provisioning->syncCatalogForUser($user);
        $app = App::query()
            ->where('user_id', $user->id)
            ->where('name', $widget)
            ->first();

        if ($app) {
            $provisioning->ensureSettingForApp($app);
        }

        $redirectPath = $app
            ? route('integrations.open', ['app' => $app->id])
            : route('filament.app.pages.dashboard');

        $signedUrl = URL::temporarySignedRoute(
            'auto.login',
            now()->addMinutes(10),
            [
                'user' => $user->id,
                'redirect' => $redirectPath,
            ]
        );

        return redirect()->away($signedUrl);
    }

    public function form(Request $request)
    {
        Log::info(__METHOD__, $request->toArray());
    }

    //установка виджета
    public function install(Request $request)
    {
        Log::warning('install', $request->toArray());
    }

    //установка с ОР
    public function edtechindustry(Request $request)
    {
        Log::info(__METHOD__, $request->toArray());
        return response()->json(['ok' => true], 202);
    }

    public function off(Request $request)
    {
        Log::info(__METHOD__, $request->toArray());
    }

    private function sanitizeRelativeRedirect(mixed $redirect, string $fallback): string
    {
        $path = trim((string)$redirect);

        if ($path === '') {
            return $fallback;
        }

        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $fallback;
        }

        return $path;
    }
}
//TODO пуши в телегу
