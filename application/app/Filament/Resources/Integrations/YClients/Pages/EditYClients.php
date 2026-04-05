<?php

namespace App\Filament\Resources\Integrations\YClients\Pages;

use App\Filament\Resources\Integrations\YClients\YClientsResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditYClients extends EditRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = YClientsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UpdateButton::activeUpdate($this->record),

            UpdateButton::amoCRMSyncButton(
                Auth::user()->account,
                fn () => $this->amocrmUpdate(),
            ),

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

        $data['link'] = \route('yclients.hook', [
            'user' => Auth::user()->uuid,
        ]);

        return $data;
    }
}
