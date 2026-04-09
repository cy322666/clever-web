<?php

namespace App\Helpers\Traits;

use App\Models\Core\Account;
use App\Services\amoCRM\Client;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

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

        if (!$account->active) {

            $url = $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
            $state = $this->encodeOauthState($user->uuid, $widget);
            $clientId = $this->resolveOauthClientId($widget, $account);

            if ($clientId === '') {
                Notification::make()
                    ->title('Не настроен client_id для виджета')
                    ->body(
                        'Для подключения amoCRM укажите client_id в services.amocrm.widgets.' . $widget . '.client_id'
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
        $configWidgetClientId = (string)config('services.amocrm.widgets.' . $widget . '.client_id', '');
        if ($configWidgetClientId !== '') {
            return $configWidgetClientId;
        }

        if ($widget !== Account::DEFAULT_WIDGET) {
            // For widget-scoped OAuth we intentionally do not fallback to global client_id.
            // This prevents connecting the platform app from a widget settings page.
            return '';
        }

        if ((string)$account->client_id !== '') {
            return (string)$account->client_id;
        }

        return (string)config('services.amocrm.client_id');
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
