<?php

namespace App\Helpers\Traits;

use App\Models\User;
use App\Models\Core\Account;
use App\Services\amoCRM\Client;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Throwable;

trait SyncAmoCRMPage
{
    public function mountSyncAmoCRMPage(): void
    {
        $authState = (string)(session('amocrm_auth') ?? request()->query('amocrm_auth', ''));

        if ($authState === '') {
            return;
        }

        $message = trim((string)(session('amocrm_auth_message') ?? request()->query('amocrm_auth_message', '')));
        if ($message === '') {
            $message = $authState === 'success'
                ? 'amoCRM успешно подключена.'
                : 'Не удалось подключить amoCRM.';
        }

        if ($authState === 'success') {
            Notification::make()
                ->title('amoCRM подключена')
                ->body($message)
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Ошибка подключения amoCRM')
            ->body($message)
            ->danger()
            ->send();
    }

    public function amocrmAuth(): void
    {
        try {
            $user = Auth::user();
            $widget = $this->resolveAmoWidgetKey();
            $account = $user?->resolveAmoAccountForWidget($widget, true);

            if (!$user || !$account) {
                Notification::make()
                    ->title('Не удалось определить amoCRM аккаунт')
                    ->danger()
                    ->send();

                return;
            }

            if ($this->shouldUseSharedAmoConnector($widget) && !$account->active) {
                if ($this->hydrateWidgetAccountFromSharedConnector($user, $account)) {
                    Notification::make()
                        ->title('amoCRM подключена')
                        ->body('Виджет amo-data использует общий коннектор платформы.')
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Для amo-data нужен общий коннектор')
                    ->body('Сначала подключите amoCRM в основном коннекторе платформы, затем вернитесь в amo-data.')
                    ->danger()
                    ->send();

                return;
            }

            if (!$account->active) {
                $url = $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
                $state = $this->encodeOauthState($user->uuid, $widget);
                $clientId = $this->resolveOauthClientId($widget, $account);

                if ($clientId === '') {
                    Notification::make()
                        ->title('Не настроен client_id для виджета')
                        ->body(
                            'Для подключения amoCRM укажите client_id в services.amocrm.widgets.' . $widget . '.client_id или общий services.amocrm.client_id'
                        )
                        ->danger()
                        ->send();

                    return;
                }

                Redirect::to(
                    'https://www.amocrm.ru/oauth/?state=' . urlencode($state)
                    . '&client_id=' . urlencode($clientId)
                    . '&uri=' . urlencode($url)
                );
            } else {
                $account->code = null;
                $account->access_token = null;
                $account->subdomain = null;
                $account->refresh_token = null;
                $account->client_id = null;
                $account->client_secret = null;
                $account->zone = null;
                $account->active = false;
                $account->save();

                Notification::make()
                    ->title('Авторизация отозвана')
                    ->warning()
                    ->send();
            }
        } catch (Throwable $e) {
            Log::error('amocrmAuth failed', [
                'widget' => $this->resolveAmoWidgetKey(),
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->title('Ошибка подключения amoCRM')
                ->body('Не удалось запустить подключение. Обновите страницу и попробуйте снова.')
                ->danger()
                ->send();
        }
    }

    /**
     * @throws \Exception
     */
    public function amocrmUpdate(): void
    {
        $account = Auth::user()?->resolveAmoAccountForWidget($this->resolveAmoWidgetKey(), false);

        if (!$account) {
            Notification::make()
                ->title('amoCRM аккаунт не найден')
                ->danger()
                ->send();

            return;
        }

        $amoApi = new Client($account);

        $amoApi->init();

        if ($amoApi->auth) {

            Artisan::call('app:sync', ['account' => $account->id]);

            Notification::make()
                ->title('Успешно обновлено')
                ->success()
                ->send();
        } else
            Notification::make()
                ->title('Ошибка авторизации')
                ->danger()
                ->send();
    }

    protected function resolveAmoWidgetKey(): string
    {
        if (method_exists($this, 'getAmoWidgetKey')) {
            $widget = (string)$this->getAmoWidgetKey();

            if ($widget !== '') {
                return Account::normalizeWidget($widget);
            }
        }

        if (property_exists($this, 'record') && $this->record && method_exists($this->record, 'amoWidget')) {
            return $this->record->amoWidget();
        }

        return Account::DEFAULT_WIDGET;
    }

    protected function resolveOauthClientId(string $widget, Account $account): string
    {
        if ($this->shouldUseSharedAmoConnector($widget)) {
            $globalClientId = (string)config('services.amocrm.client_id');

            if ($globalClientId !== '') {
                return $globalClientId;
            }

            $sharedAccount = $this->resolveSharedSourceAccount($account->user, $account);
            if ((string)($sharedAccount?->client_id ?? '') !== '') {
                return (string)$sharedAccount->client_id;
            }
        }

        $configWidgetClientId = (string)config('services.amocrm.widgets.' . $widget . '.client_id', '');
        if ($configWidgetClientId !== '') {
            return $configWidgetClientId;
        }

        if ((string)$account->client_id !== '') {
            return (string)$account->client_id;
        }

        return (string)config('services.amocrm.client_id');
    }

    protected function shouldUseSharedAmoConnector(string $widget): bool
    {
        return Account::normalizeWidget($widget) === 'amo-data';
    }

    protected function hydrateWidgetAccountFromSharedConnector($user, Account $widgetAccount): bool
    {
        $shared = $this->resolveSharedSourceAccount($user, $widgetAccount);
        if (!$shared) {
            return false;
        }

        $hasOauth = (string)($shared->access_token ?? '') !== ''
            && (string)($shared->refresh_token ?? '') !== '';

        if (!$shared->active || !$hasOauth) {
            return false;
        }

        $widgetAccount->forceFill([
            'code' => $shared->code,
            'access_token' => $shared->access_token,
            'refresh_token' => $shared->refresh_token,
            'subdomain' => $shared->subdomain,
            'zone' => $shared->zone,
            'client_id' => $shared->client_id,
            'client_secret' => $shared->client_secret,
            'redirect_uri' => $shared->redirect_uri,
            'token_type' => $shared->token_type,
            'expires_in' => $shared->expires_in,
            'referer' => $shared->referer,
            'endpoint' => $shared->endpoint,
            'state' => $shared->state,
            'active' => true,
        ])->save();

        return true;
    }

    protected function resolveSharedSourceAccount(?User $user, ?Account $exclude = null): ?Account
    {
        if (!$user) {
            return null;
        }

        $query = $user->accounts()
            ->whereNotNull('client_id')
            ->where('client_id', '<>', '')
            ->whereNotNull('client_secret')
            ->where('client_secret', '<>', '')
            ->whereNotNull('redirect_uri')
            ->where('redirect_uri', '<>', '')
            ->orderByRaw("CASE WHEN widget = ? OR widget IS NULL THEN 0 ELSE 1 END", [Account::DEFAULT_WIDGET])
            ->orderByDesc('active')
            ->orderByDesc('id');

        if ($exclude) {
            $query->where('id', '<>', $exclude->id);
        }

        return $query->first();
    }

    protected function encodeOauthState(string $userUuid, string $widget): string
    {
        $payload = json_encode([
            'user_uuid' => $userUuid,
            'widget' => $widget,
        ], JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return $userUuid;
        }

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }
}
