<?php

namespace App\Helpers\Traits;

use App\Services\amoCRM\Client;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

trait SyncAmoCRMPage
{
    public function amocrmAuth(): void
    {
        $account = Auth::user()->account;

        if (!$account->active) {

            $url = $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);

            Redirect::to('https://www.amocrm.ru/oauth/?state='.Auth::user()->uuid.'&mode=popup&client_id='.config('services.amocrm.client_id').'&redirect_uri='.urlencode($url));

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

    public function amocrmUpdate(): void
    {
        $account = Auth::user()->account;

        $amoApi = new Client($account);

        if (!$amoApi->auth)

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
}
