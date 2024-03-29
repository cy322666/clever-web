<?php

namespace App\Filament\Resources\Integrations\AlfaResource\Pages;

use App\Filament\Resources\Integrations\Active\LeadResource;
use App\Filament\Resources\Integrations\Alfa\TransactionResource;
use App\Filament\Resources\Integrations\AlfaResource;
use App\Helpers\Actions\UpdateButton;
use App\Services\AlfaCRM\Client;
use App\Services\AlfaCRM\Models\Account;
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
            UpdateButton::getAction($this->record),

            Actions\Action::make('instruction')
                ->label('Инструкция'),
            Actions\Action::make('alfaUpdate')
                ->label('Синхронизировать AlfaCRM')
                ->action('alfaUpdate')
                ->disabled(fn() => !$this->record->api_key),

             Actions\Action::make('list')
                 ->label('История')
                 ->url(TransactionResource::getUrl())
        ];
    }

    public function mutateFormDataBeforeFill(array $data): array
    {
        $data['link_record']   = $this->record->getRecordLink();
        $data['link_came']     = $this->record->getCameLink();
        $data['link_omission'] = $this->record->getOmissionLink();

        return parent::mutateFormDataBeforeFill($data);
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
                ->title('Ошибка обновления')
                ->warning()
                ->send();
    }
}
