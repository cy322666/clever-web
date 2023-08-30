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
use Filament\Support\Colors\Color;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Auth;

class EditAlfa extends EditRecord
{
    protected static string $resource = AlfaResource::class;

    protected function getActions(): array
    {
        return [
            Actions\Action::make('activeUpdate')
                ->action(function () {
                    $this->record->active = !$this->record->active;
                    $this->record->save();

                    if ($this->record->active)

                        Notification::make()
                            ->title('Интеграция включена')
                            ->success()
                            ->send();
                    else
                        Notification::make()
                            ->title('Интеграция выключена')
                            ->danger()
                            ->send();
                })
                ->color(fn() => $this->record->active ? Color::Red : Color::Green)
                ->label(fn() => $this->record->active ? 'Выключить' : 'Включить'),

            Actions\Action::make('instruction')
                ->label('Инструкция'),
            Actions\Action::make('alfaUpdate')
                ->label('Синхронизировать AlfaCRM')
                ->action('alfaUpdate')
//                ->disabled(fn() => !$this->record->api_key) //TODO
        ];
    }

    /**
     * @throws GuzzleException
     */
    public function alfaUpdate(): void
    {
        $alfaApi = (new Client(Auth::user()->alfacrm_settings))->init();

        if ($alfaApi->auth !== false) {

            Account::branches($alfaApi);
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
