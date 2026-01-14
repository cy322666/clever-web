<?php

namespace App\Helpers\Traits;

use App\Services\amoCRM\Client;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

trait SyncAmoCRMPage
{
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
