<?php

namespace App\Filament\Resources\Integrations\YClients\Pages;

use App\Filament\Resources\Integrations\YClients\YClientsResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditYClients extends EditRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = YClientsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UpdateButton::activeUpdate($this->record),

            UpdateButton::amoCRMSyncButton(
                $this->record->amoAccount(true),
                fn () => $this->amocrmUpdate(),
            ),

            UpdateButton::amoCRMResetButton($this->record->amoAccount(true)),

            Action::make('list')
                ->label('История')
                ->icon('heroicon-o-list-bullet')
                ->url(YClientsResource::getUrl('list'))
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['fields_contact'] = json_decode($data['fields_contact'], true);
        $data['fields_lead'] = json_decode($data['fields_lead'], true);

        return $data;
    }
}
