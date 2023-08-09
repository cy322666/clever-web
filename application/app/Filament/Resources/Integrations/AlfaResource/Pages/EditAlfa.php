<?php

namespace App\Filament\Resources\Integrations\AlfaResource\Pages;

use App\Filament\Resources\Integrations\AlfaResource;
use App\Services\AlfaCRM\Client;
use App\Services\AlfaCRM\Models\Account;
use App\Services\AlfaCRM\Models\Branch;
use App\Services\AlfaCRM\Models\Source;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Auth;

class EditAlfa extends EditRecord
{
    protected static string $resource = AlfaResource::class;

    protected function getActions(): array
    {
        return [
            Actions\Action::make('alfaUpdate')
                ->label('Синхронизировать AlfaCRM')
                ->action('alfaUpdate'),
        ];
    }

    /**
     * @throws GuzzleException
     */
    public function alfaUpdate(): void
    {
        $alfaApi = (new Client(Auth::user()->alfacrm_settings))->init();

        if ($alfaApi->auth !== false) {

//            Account::branches($alfaApi);
            Account::statuses($alfaApi);
            Account::sources($alfaApi);

            Notification::make()
                ->title('Успешно')
                ->success()
                ->send();

        } else
            Notification::make()
                ->title('Авторизуйся')
                ->warning()
                ->send();
    }
}
