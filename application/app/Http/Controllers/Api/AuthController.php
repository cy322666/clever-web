<?php

namespace App\Http\Controllers\Api;

use App\Filament\Resources\Integrations\AlfaResource;
use App\Http\Controllers\Controller;
use App\Mail\AmoDisconnected;
use App\Mail\SignUp;
use App\Mail\SignUpWidget;
use App\Models\App;
use App\Models\Core\Account;
use App\Models\User;
use App\Services\Integrations\IntegrationProvisioningService;
use App\Services\amoCRM\Client;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuthController extends Controller
{
    //обычная установка
    public function redirect(Request $request): RedirectResponse
    {
        $fallbackRedirect = route('filament.app.pages.dashboard', [], false);

        try {
            $oauthState = $this->decodeOauthState((string)$request->state);

            $user = User::query()
                ->where('uuid', $oauthState['user_uuid'])
                ->first();

            if (!$user instanceof User) {
                return $this->oauthErrorRedirect($request, 'Пользователь не найден.', 404, $fallbackRedirect);
            }

            $widget = Account::normalizeWidget((string)($oauthState['widget'] ?? Account::DEFAULT_WIDGET));
            $account = $user->resolveAmoAccountForWidget($widget, true);

            if (!$account instanceof Account) {
                return $this->oauthErrorRedirect(
                    $request,
                    'Слот amoCRM для виджета не найден.',
                    422,
                    $fallbackRedirect
                );
            }

            $expectedWidgetClientId = (string)config('services.amocrm.widgets.' . $widget . '.client_id', '');
            $incomingClientId = trim((string)$request->input('client_id', ''));
            $resolvedClientId = $incomingClientId !== ''
                ? $incomingClientId
                : ($expectedWidgetClientId !== '' ? $expectedWidgetClientId : (string)$account->client_id);

            if ($widget !== Account::DEFAULT_WIDGET && $expectedWidgetClientId !== '' && $incomingClientId !== '' && $incomingClientId !== $expectedWidgetClientId) {
                return $this->oauthErrorRedirect(
                    $request,
                    'Подключение выполнено через другой amoCRM widget. Откройте подключение заново из нужной интеграции.',
                    422,
                    $fallbackRedirect
                );
            }

            if ($resolvedClientId === '') {
                return $this->oauthErrorRedirect(
                    $request,
                    'Не настроен client_id для выбранного виджета.',
                    422,
                    $fallbackRedirect
                );
            }

            $oauthConfig = $this->resolveOauthConfigForWidget($widget);
            if ((string)$oauthConfig['client_secret'] === '') {
                return $this->oauthErrorRedirect(
                    $request,
                    'Не настроен client_secret для выбранного виджета.',
                    422,
                    $fallbackRedirect
                );
            }

            $amoDomain = $this->extractAmoDomainParts((string)$request->input('referer', ''));
            $primaryUserDomain = $this->getUserPrimaryAmoDomain($user);
            $parsedSubdomain = $amoDomain['subdomain'] ? Str::lower((string)$amoDomain['subdomain']) : null;

            $accountSubdomain = $account->subdomain ? Str::lower((string)$account->subdomain) : null;

            if (!filled($account->refresh_token) && !filled($account->access_token)) {
                // For first connect do not trust stale subdomain in widget slot.
                $accountSubdomain = null;
            }

            if (
                $parsedSubdomain
                && $primaryUserDomain['subdomain']
                && $parsedSubdomain !== $primaryUserDomain['subdomain']
            ) {
                $ownershipError = $this->validateAmoSubdomainUniqueness($user, $parsedSubdomain);

                if ($ownershipError !== null) {
                    return $this->oauthErrorRedirect(
                        $request,
                        $ownershipError,
                        422,
                        $fallbackRedirect
                    );
                }

                $normalizedZone = ($amoDomain['zone'] ? Str::lower((string)$amoDomain['zone']) : null)
                    ?? $primaryUserDomain['zone']
                    ?? ($account->zone ? Str::lower((string)$account->zone) : null)
                    ?? 'ru';

                $this->switchUserAmoDomain($user, $parsedSubdomain, $normalizedZone);

                Log::warning('amocrm.domain switched from callback', [
                    'user_id' => $user->id,
                    'from' => $primaryUserDomain['subdomain'],
                    'to' => $parsedSubdomain,
                    'zone' => $normalizedZone,
                    'widget' => $widget,
                ]);

                $primaryUserDomain = [
                    'subdomain' => $parsedSubdomain,
                    'zone' => $normalizedZone,
                ];

                $account->refresh();
                $accountSubdomain = $parsedSubdomain;
            }

            $subdomain = $parsedSubdomain
                ?? $primaryUserDomain['subdomain']
                ?? $accountSubdomain;
            $zone = ($amoDomain['zone'] ? Str::lower((string)$amoDomain['zone']) : null)
                ?? $primaryUserDomain['zone']
                ?? ($account->zone ? Str::lower((string)$account->zone) : null);

            if (!$subdomain) {
                return $this->oauthErrorRedirect(
                    $request,
                    'Не удалось определить домен amoCRM. Подключите amoCRM с основного аккаунта или укажите корректный домен клиента.',
                    422,
                    $fallbackRedirect
                );
            }

            $candidateSubdomains = array_values(array_unique(array_filter([
                $parsedSubdomain,
                $primaryUserDomain['subdomain'],
                $accountSubdomain,
            ])));

            $amoApi = null;
            $exchangeErrors = [];

            foreach ($candidateSubdomains as $candidateSubdomain) {
                $subdomainValidationError = $this->validateAmoSubdomainUniqueness($user, $candidateSubdomain);
                if ($subdomainValidationError !== null) {
                    $exchangeErrors[] = $candidateSubdomain . ': ' . $subdomainValidationError;
                    continue;
                }

                $account->code = (string)$request->input('code', '');
                $account->widget = $widget;
                $account->zone = $zone ?? $account->zone;
                $account->client_id = $resolvedClientId;
                $account->subdomain = $candidateSubdomain;
                $account->redirect_uri = (string)$oauthConfig['redirect_uri'];
                $account->client_secret = (string)$oauthConfig['client_secret'];
                $account->save();

                Log::info('amocrm.redirect exchange start', [
                    'user_id' => $user->id,
                    'widget' => $widget,
                    'account_id' => $account->id,
                    'selected_subdomain' => $account->subdomain,
                    'selected_zone' => $account->zone,
                    'referer' => (string)$request->input('referer', ''),
                    'parsed_subdomain' => $parsedSubdomain,
                    'primary_user_subdomain' => $primaryUserDomain['subdomain'],
                    'account_subdomain' => $accountSubdomain,
                    'candidates' => $candidateSubdomains,
                ]);

                try {
                    $amoApi = (new Client($account->refresh()));

                    if (!$amoApi->checkAuth()) {
                        $amoApi->init();
                    }

                    $account->active = $amoApi->auth;
                    $account->save();

                    if ($amoApi->auth) {
                        break;
                    }

                    $exchangeErrors[] = $candidateSubdomain . ': auth=false';
                } catch (Throwable $exchangeException) {
                    $exchangeErrors[] = $candidateSubdomain . ': ' . $exchangeException->getMessage();
                }
            }

            if (!$amoApi || !$amoApi->auth) {
                return $this->oauthErrorRedirect(
                    $request,
                    'Не удалось завершить подключение amoCRM. ' . implode(' | ', array_slice($exchangeErrors, 0, 3)),
                    422,
                    $fallbackRedirect
                );
            }

            Log::info('amocrm.redirect success', [
                'user_id' => $user->id,
                'widget' => $widget,
                'account_id' => $account->id,
                'subdomain' => $account->subdomain,
                'zone' => $account->zone,
                'active' => $account->active,
            ]);

            $this->sendOauthResultNotification(
                $user,
                $widget,
                $amoApi->auth,
                $amoApi->auth
                    ? 'Интеграция с amoCRM подключена.'
                    : 'Подключение с amoCRM не завершено.'
            );

            Mail::to($user->email)->queue(new SignUp($user));

            $redirectPath = $this->sanitizeRelativeRedirect(
                $request->input('uri'),
                $fallbackRedirect
            );

            return redirect()
                ->to(
                    $this->appendQuery($redirectPath, [
                    'amocrm_auth' => $amoApi->auth ? 'success' : 'error',
                    'amocrm_auth_message' => $amoApi->auth
                        ? 'amoCRM успешно подключена.'
                        : 'Подключение amoCRM не завершено.',
                    ])
                );
        } catch (Throwable $e) {
            if ($e instanceof HttpExceptionInterface) {
                return $this->oauthErrorRedirect(
                    $request,
                    $e->getMessage() !== '' ? $e->getMessage() : 'Не удалось завершить подключение amoCRM.',
                    $e->getStatusCode(),
                    $fallbackRedirect,
                    $e
                );
            }

            return $this->oauthErrorRedirect(
                $request,
                'Не удалось завершить подключение amoCRM. ' . trim($e->getMessage()),
                500,
                $fallbackRedirect,
                $e
            );
        }
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
        $payload = $request->all();
        $flat = $this->flattenPayload($payload);

        $clientId = $this->firstFilledValue($flat, [
            'client_id',
            'client.id',
            'account.client_id',
            'account.id',
        ]);

        $referer = (string)($this->firstFilledValue($flat, ['referer', 'account.referer']) ?? '');
        if ($referer === '') {
            $referer = (string)$request->header('referer', '');
        }

        $subdomain = $this->extractAmoDomainParts($referer)['subdomain']
            ?? $this->normalizeSubdomain(
                (string)($this->firstFilledValue($flat, [
                    'subdomain',
                    'account.subdomain',
                    'account.domain',
                    'domain',
                ]) ?? '')
            );

        $accountsQuery = Account::query();

        if ($clientId !== null && $clientId !== '') {
            $accountsQuery->where('client_id', $clientId);
        }

        if ($subdomain !== null && $subdomain !== '') {
            $accountsQuery->whereRaw('LOWER(subdomain) = ?', [Str::lower($subdomain)]);
        }

        if (($clientId === null || $clientId === '') && ($subdomain === null || $subdomain === '')) {
            Log::warning('amocrm.off: account matcher is missing', [
                'payload' => $payload,
            ]);

            return response()->json([
                'ok' => true,
                'updated' => 0,
                'reason' => 'No client_id/subdomain in payload',
            ]);
        }

        $accounts = $accountsQuery
            ->with('user:id,name,email')
            ->get();

        $mailPayloadByUser = [];

        foreach ($accounts as $account) {
            $user = $account->user;
            if ($user && filled($user->email)) {
                $userId = (int)$user->id;

                if (!isset($mailPayloadByUser[$userId])) {
                    $mailPayloadByUser[$userId] = [
                        'user' => $user,
                        'widgets' => [],
                        'subdomains' => [],
                    ];
                }

                $mailPayloadByUser[$userId]['widgets'][] = Account::normalizeWidget((string)$account->widget);
                if (filled($account->subdomain)) {
                    $mailPayloadByUser[$userId]['subdomains'][] = Str::lower((string)$account->subdomain);
                }
            }

            $account->code = null;
            $account->access_token = null;
            $account->refresh_token = null;
            $account->subdomain = null;
            $account->active = false;
            $account->save();
        }

        $mailsQueued = 0;
        foreach ($mailPayloadByUser as $payload) {
            $widgets = array_values(array_unique($payload['widgets']));
            $subdomains = array_values(array_unique($payload['subdomains']));

            try {
                Mail::to($payload['user']->email)->queue(
                    new AmoDisconnected(
                        user: $payload['user'],
                        widgets: $widgets,
                        subdomains: $subdomains,
                    )
                );
                $mailsQueued++;
            } catch (Throwable $e) {
                Log::warning('amocrm.off: failed to queue disconnect email', [
                    'user_id' => $payload['user']->id ?? null,
                    'email' => $payload['user']->email ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('amocrm.off processed', [
            'client_id' => $clientId,
            'subdomain' => $subdomain,
            'updated' => $accounts->count(),
            'mail_queued' => $mailsQueued,
        ]);

        return response()->json([
            'ok' => true,
            'updated' => $accounts->count(),
            'mail_queued' => $mailsQueued,
        ]);
    }

    private function sanitizeRelativeRedirect(mixed $redirect, string $fallback): string
    {
        $path = trim((string)$redirect);

        if ($path === '') {
            return $fallback;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $parsed = parse_url($path);
            $host = isset($parsed['host']) ? Str::lower((string)$parsed['host']) : '';
            $appHost = Str::lower((string)(parse_url((string)config('app.url'), PHP_URL_HOST) ?? ''));
            $requestHost = Str::lower((string)request()->getHost());

            if ($host !== '' && ($host === $appHost || $host === $requestHost)) {
                $relative = (string)($parsed['path'] ?? '/');
                $query = (string)($parsed['query'] ?? '');
                $fragment = (string)($parsed['fragment'] ?? '');

                if (!str_starts_with($relative, '/')) {
                    $relative = '/' . ltrim($relative, '/');
                }

                if ($query !== '') {
                    $relative .= '?' . $query;
                }

                if ($fragment !== '') {
                    $relative .= '#' . $fragment;
                }

                return $relative;
            }
        }

        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $fallback;
        }

        return $path;
    }

    private function oauthErrorRedirect(
        Request $request,
        string $message,
        int $status = 422,
        ?string $fallback = null,
        ?Throwable $e = null,
    ): RedirectResponse {
        $fallbackPath = $fallback ?: route('filament.app.pages.dashboard', [], false);
        $redirectPath = $this->sanitizeRelativeRedirect($request->input('uri'), $fallbackPath);

        Log::warning('amocrm.redirect failed', [
            'status' => $status,
            'message' => $message,
            'user_uuid' => $request->input('state'),
            'uri' => $request->input('uri'),
            'safe_uri' => $redirectPath,
            'client_id' => $request->input('client_id'),
            'referer' => $request->input('referer'),
            'exception' => $e?->getMessage(),
        ]);

        $context = $this->resolveOauthContextFromRequest($request);
        $this->sendOauthResultNotification(
            $context['user'],
            $context['widget'],
            false,
            $message
        );

        return redirect()
            ->to(
                $this->appendQuery($redirectPath, [
                'amocrm_auth' => 'error',
                'amocrm_auth_status' => $status,
                'amocrm_auth_message' => $message,
                ])
            );
    }

    private function sendOauthResultNotification(?User $user, string $widget, bool $success, string $message): void
    {
        if (!$user) {
            return;
        }

        $widget = Account::normalizeWidget($widget);
        $widgetLabel = $widget === Account::DEFAULT_WIDGET ? 'platform' : $widget;

        try {
            $notification = FilamentNotification::make()
                ->title($success ? 'amoCRM подключена' : 'Ошибка подключения amoCRM')
                ->body(trim($message) . PHP_EOL . 'Виджет: ' . $widgetLabel)
                ->persistent();

            if ($success) {
                $notification->success();
            } else {
                $notification->danger();
            }

            $notification->sendToDatabase($user);
        } catch (Throwable $e) {
            Log::warning('amocrm.oauth notification failed', [
                'user_id' => $user->id,
                'widget' => $widget,
                'success' => $success,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveOauthContextFromRequest(Request $request): array
    {
        $widget = Account::DEFAULT_WIDGET;
        $user = null;

        try {
            $decoded = $this->decodeOauthState((string)$request->input('state', ''));
            $widget = Account::normalizeWidget((string)($decoded['widget'] ?? Account::DEFAULT_WIDGET));
            $userUuid = (string)($decoded['user_uuid'] ?? '');

            if ($userUuid !== '') {
                $user = User::query()
                    ->where('uuid', $userUuid)
                    ->first();
            }
        } catch (Throwable) {
            // ignore malformed state in fallback notification flow
        }

        return [
            'user' => $user instanceof User ? $user : null,
            'widget' => $widget,
        ];
    }

    private function getUserPrimaryAmoDomain(User $user): array
    {
        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->whereNotNull('subdomain')
            ->where('subdomain', '<>', '')
            ->orderByRaw("CASE WHEN widget = ? OR widget IS NULL THEN 0 ELSE 1 END", [Account::DEFAULT_WIDGET])
            ->orderByDesc('active')
            ->orderByDesc('id')
            ->get();

        foreach ($accounts as $account) {
            $subdomain = Str::lower((string)$account->subdomain);

            return [
                'subdomain' => $subdomain !== '' ? $subdomain : null,
                'zone' => $account->zone ? Str::lower((string)$account->zone) : null,
            ];
        }

        return [
            'subdomain' => null,
            'zone' => null,
        ];
    }

    private function switchUserAmoDomain(User $user, string $subdomain, string $zone): void
    {
        Account::query()
            ->where('user_id', $user->id)
            ->update([
                'subdomain' => $subdomain,
                'zone' => $zone,
                'code' => null,
                'access_token' => null,
                'refresh_token' => null,
                'active' => false,
            ]);
    }

    private function appendQuery(string $path, array $params): string
    {
        $query = http_build_query(array_filter($params, static fn($value) => $value !== null && $value !== ''));
        if ($query === '') {
            return $path;
        }

        return $path . (str_contains($path, '?') ? '&' : '?') . $query;
    }

    private function decodeOauthState(string $state): array
    {
        $state = trim($state);

        if ($state === '') {
            abort(422, 'Invalid oauth state.');
        }

        $normalized = strtr($state, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);

        if ($decoded !== false) {
            $payload = json_decode($decoded, true);

            if (is_array($payload) && !empty($payload['user_uuid'])) {
                return [
                    'user_uuid' => (string)$payload['user_uuid'],
                    'widget' => Account::normalizeWidget((string)($payload['widget'] ?? Account::DEFAULT_WIDGET)),
                ];
            }
        }

        if (str_contains($state, '|')) {
            [$uuid, $widget] = array_pad(explode('|', $state, 2), 2, '');

            if ($uuid !== '') {
                return [
                    'user_uuid' => $uuid,
                    'widget' => Account::normalizeWidget($widget),
                ];
            }
        }

        return [
            'user_uuid' => $state,
            'widget' => Account::DEFAULT_WIDGET,
        ];
    }

    private function resolveOauthConfigForWidget(string $widget): array
    {
        $prefix = 'services.amocrm.widgets.' . $widget . '.';

        $clientSecret = (string)config($prefix . 'client_secret', '');
        $redirectUri = (string)config($prefix . 'redirect_uri', '');

        return [
            'client_secret' => $clientSecret !== ''
                ? $clientSecret
                : (string)config('services.amocrm.client_secret'),
            'redirect_uri' => $redirectUri !== ''
                ? $redirectUri
                : (string)config('services.amocrm.redirect_uri'),
        ];
    }

    private function extractAmoDomainParts(string $referer): array
    {
        $referer = trim($referer);

        if ($referer === '') {
            return ['subdomain' => null, 'zone' => null];
        }

        $host = parse_url($referer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            $host = parse_url('https://' . ltrim($referer, '/'), PHP_URL_HOST);
        }

        if (!is_string($host) || $host === '') {
            return ['subdomain' => null, 'zone' => null];
        }

        $parts = array_values(array_filter(explode('.', Str::lower($host))));
        if (count($parts) < 2) {
            return ['subdomain' => null, 'zone' => null];
        }

        $subdomain = $parts[0] ?? null;
        $zone = end($parts) ?: null;

        if (!is_string($subdomain) || !preg_match('/^[a-z0-9-]+$/', $subdomain)) {
            $subdomain = null;
        }

        if (!is_string($zone) || !preg_match('/^[a-z]{2,10}$/', $zone)) {
            $zone = null;
        }

        return [
            'subdomain' => $subdomain,
            'zone' => $zone,
        ];
    }

    private function validateAmoSubdomainUniqueness(User $user, string $subdomain): ?string
    {
        $subdomain = Str::lower(trim($subdomain));

        if ($subdomain === '') {
            return null;
        }

        $subdomainBelongsToAnotherUser = Account::query()
            ->where('user_id', '<>', $user->id)
            ->whereNotNull('subdomain')
            ->where('subdomain', '<>', '')
            ->whereRaw('LOWER(subdomain) = ?', [$subdomain])
            ->exists();

        if ($subdomainBelongsToAnotherUser) {
            return 'Этот amoCRM домен уже подключен к другому аккаунту платформы.';
        }

        return null;
    }

    private function flattenPayload(array $payload, string $prefix = ''): array
    {
        $flat = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;

            if (is_array($value)) {
                $flat += $this->flattenPayload($value, $path);
                continue;
            }

            $flat[$path] = $value;
            $flat[(string)$key] = $value;
        }

        return $flat;
    }

    private function firstFilledValue(array $flat, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $flat)) {
                continue;
            }

            $value = $flat[$key];

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function normalizeSubdomain(string $value): ?string
    {
        $value = Str::lower(trim($value));

        if ($value === '') {
            return null;
        }

        $value = preg_replace('/^https?:\/\//', '', $value);
        $value = preg_replace('/\.amocrm\..*$/', '', (string)$value);
        $value = explode('/', (string)$value)[0] ?? '';
        $value = explode(':', (string)$value)[0] ?? '';

        if (!preg_match('/^[a-z0-9-]+$/', (string)$value)) {
            return null;
        }

        return (string)$value;
    }
}
//TODO пуши в телегу
