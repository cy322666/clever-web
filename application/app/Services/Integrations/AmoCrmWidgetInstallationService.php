<?php

namespace App\Services\Integrations;

use App\Models\App;
use App\Models\Core\Account;
use App\Models\User;
use App\Services\Billing\WidgetSubscriptionAccessService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;

class AmoCrmWidgetInstallationService
{
    /**
     * @return array{user: User, account: Account, created_user: bool}
     */
    public function install(string $authorizationCode, string $referer, string $widget = 'workflows'): array
    {
        $authorizationCode = trim($authorizationCode);
        $widget = Account::normalizeWidget($widget);
        $domain = $this->parseAmoDomain($referer);
        $oauth = $this->oauthConfig($widget);

        if ($authorizationCode === '') {
            throw new RuntimeException('amoCRM authorization code is missing.');
        }

        $token = $this->amoRequest()
            ->post($domain['base_url'].'/oauth2/access_token', [
                'client_id' => $oauth['client_id'],
                'client_secret' => $oauth['client_secret'],
                'grant_type' => 'authorization_code',
                'code' => $authorizationCode,
                'redirect_uri' => $oauth['redirect_uri'],
            ])
            ->throw()
            ->json();

        $accessToken = trim((string) data_get($token, 'access_token', ''));
        $refreshToken = trim((string) data_get($token, 'refresh_token', ''));

        if ($accessToken === '' || $refreshToken === '') {
            throw new RuntimeException('amoCRM did not return OAuth tokens.');
        }

        $accountData = $this->amoRequest($accessToken)
            ->get($domain['base_url'].'/api/v4/account')
            ->throw()
            ->json();

        $amoAccountId = (int) data_get($accountData, 'id', 0);
        $currentUserId = (int) data_get($accountData, 'current_user_id', 0);

        if ($amoAccountId <= 0 || $currentUserId <= 0) {
            throw new RuntimeException('amoCRM account response does not contain account or current user ID.');
        }

        $amoUser = $this->amoRequest($accessToken)
            ->get($domain['base_url'].'/api/v4/users/'.$currentUserId)
            ->throw()
            ->json();

        $email = Str::lower(trim((string) data_get($amoUser, 'email', '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('amoCRM installer email is missing or invalid.');
        }

        $result = DB::transaction(function () use (
            $accountData,
            $accessToken,
            $amoAccountId,
            $amoUser,
            $domain,
            $email,
            $oauth,
            $refreshToken,
            $token,
            $widget,
        ): array {
            $account = Account::query()
                ->where('amo_account_id', $amoAccountId)
                ->where('widget', $widget)
                ->lockForUpdate()
                ->first();

            $ownerAccounts = Account::query()
                ->where(function ($query) use ($amoAccountId, $domain): void {
                    $query->where('amo_account_id', $amoAccountId)
                        ->orWhere(function ($query) use ($domain): void {
                            $query->whereNull('amo_account_id')
                                ->where('subdomain', $domain['subdomain'])
                                ->where(function ($query) use ($domain): void {
                                    $query->where('zone', $domain['zone'])
                                        ->orWhereNull('zone');
                                });
                        });
                })
                ->lockForUpdate()
                ->get();

            $ownerUserIds = $ownerAccounts->pluck('user_id')->filter()->unique()->values();
            if ($ownerUserIds->count() > 1) {
                throw new RuntimeException('Several platform users are linked to this amoCRM account.');
            }

            if (! $account) {
                $account = $ownerAccounts->firstWhere('widget', $widget);
            }

            $user = $account?->user ?? $ownerAccounts->first()?->user;
            $createdUser = false;

            if (! $user) {
                $user = User::query()
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->lockForUpdate()
                    ->first();
            }

            if (! $user) {
                $user = User::withoutEvents(function () use ($amoUser, $email): User {
                    $user = new User;
                    $user->forceFill([
                        'uuid' => (string) Str::uuid(),
                        'name' => trim((string) data_get($amoUser, 'name', '')) ?: 'Пользователь amoCRM',
                        'email' => $email,
                        'email_verified_at' => now(),
                        'password' => Hash::make(Str::random(64)),
                        'active' => true,
                    ])->save();

                    return $user;
                });
                $createdUser = true;

                Account::query()->create([
                    'user_id' => $user->id,
                    'widget' => Account::DEFAULT_WIDGET,
                ]);
            }

            if (! $account) {
                $account = Account::query()
                    ->where('user_id', $user->id)
                    ->where('widget', $widget)
                    ->lockForUpdate()
                    ->first();
            }

            if ($account && (int) $account->user_id !== (int) $user->id) {
                throw new RuntimeException('amoCRM account is already linked to another platform user.');
            }

            if (
                $account
                && filled($account->amo_account_id)
                && (int) $account->amo_account_id !== $amoAccountId
            ) {
                throw new RuntimeException('Platform user already has another amoCRM account for this widget.');
            }

            $account ??= new Account([
                'user_id' => $user->id,
                'widget' => $widget,
            ]);

            $account->forceFill([
                'user_id' => $user->id,
                'widget' => $widget,
                'amo_account_id' => $amoAccountId,
                'subdomain' => $domain['subdomain'],
                'zone' => $domain['zone'],
                'client_id' => $oauth['client_id'],
                'client_secret' => $oauth['client_secret'],
                'redirect_uri' => $oauth['redirect_uri'],
                'code' => null,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_in' => (int) data_get($token, 'expires_in', 86400),
                'created_at' => (int) data_get($token, 'created_at', time()),
                'active' => true,
            ])->save();

            Log::info('amocrm.widget.install linked', [
                'widget' => $widget,
                'amo_account_id' => $amoAccountId,
                'amo_account_name' => (string) data_get($accountData, 'name', ''),
                'amo_user_id' => (int) data_get($amoUser, 'id', 0),
                'platform_user_id' => $user->id,
                'platform_account_id' => $account->id,
                'created_user' => $createdUser,
            ]);

            return compact('user', 'account', 'createdUser');
        }, 3);

        /** @var User $user */
        $user = $result['user'];
        /** @var Account $account */
        $account = $result['account'];

        $provisioning = app(IntegrationProvisioningService::class);
        $provisioning->syncCatalogForUser($user);

        $app = App::query()
            ->where('user_id', $user->id)
            ->where('name', $widget)
            ->first();
        if ($app) {
            $provisioning->ensureSettingForApp($app);
        }

        app(WidgetSubscriptionAccessService::class)->ensureTrialForWidget(
            $user,
            $widget,
            (int) config("integrations.definitions.{$widget}.trial_days", 7),
        );

        if ($result['createdUser']) {
            $passwordStatus = Password::sendResetLink(['email' => $user->email]);
            if ($passwordStatus !== Password::RESET_LINK_SENT) {
                Log::warning('amocrm.widget.install password setup email failed', [
                    'user_id' => $user->id,
                    'status' => $passwordStatus,
                ]);
            }
        }

        try {
            Artisan::call('app:sync', ['account' => $account->id]);
        } catch (\Throwable $exception) {
            Log::warning('amocrm.widget.install initial sync failed', [
                'account_id' => $account->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return [
            'user' => $user->fresh(),
            'account' => $account->fresh(),
            'created_user' => (bool) $result['createdUser'],
        ];
    }

    private function amoRequest(?string $accessToken = null): PendingRequest
    {
        $request = Http::acceptJson()->asJson()->timeout(20);

        return $accessToken ? $request->withToken($accessToken) : $request;
    }

    /**
     * @return array{client_id: string, client_secret: string, redirect_uri: string}
     */
    private function oauthConfig(string $widget): array
    {
        $prefix = 'services.amocrm.widgets.'.$widget.'.';
        $config = [
            'client_id' => $this->firstFilled([
                config($prefix.'client_id'),
                config('services.amocrm.client_id'),
            ]),
            'client_secret' => $this->firstFilled([
                config($prefix.'client_secret'),
                config('services.amocrm.client_secret'),
            ]),
            'redirect_uri' => $this->firstFilled([
                config($prefix.'redirect_uri'),
                config('services.amocrm.redirect_uri'),
            ]),
        ];

        foreach ($config as $key => $value) {
            if ($value === '') {
                throw new RuntimeException("amoCRM {$widget} {$key} is not configured.");
            }
        }

        return $config;
    }

    private function firstFilled(array $values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array{subdomain: string, zone: string, base_url: string}
     */
    private function parseAmoDomain(string $referer): array
    {
        $referer = trim($referer);
        $host = parse_url($referer, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            $host = parse_url('https://'.ltrim($referer, '/'), PHP_URL_HOST);
        }

        $host = Str::lower(trim((string) $host, '.'));
        if (! preg_match('/^([a-z0-9-]+)\.amocrm\.([a-z]{2,10})$/', $host, $matches)) {
            throw new RuntimeException('Invalid amoCRM referer.');
        }

        return [
            'subdomain' => $matches[1],
            'zone' => $matches[2],
            'base_url' => 'https://'.$host,
        ];
    }
}
