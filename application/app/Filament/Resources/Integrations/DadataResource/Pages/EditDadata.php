<?php

namespace App\Filament\Resources\Integrations\DadataResource\Pages;

use App\Filament\Resources\Integrations\Active\LeadResource;
use App\Filament\Resources\Integrations\Dadata\InfoResource;
use App\Filament\Resources\Integrations\DadataResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditDadata extends EditRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = DadataResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UpdateButton::activeUpdate($this->record),

            UpdateButton::amoCRMSyncButton(
                Auth::user()->account,
                fn () => $this->amocrmUpdate(),
            ),

            Actions\Action::make('instruction')
                ->label('Инструкция'),

            Actions\Action::make('list')
                ->label('История')
                ->icon('heroicon-o-list-bullet')
                ->url(InfoResource::getUrl())
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['link'] = route('data.hook', [
            'user' => Auth::user()->uuid,
        ]);

        return $data;
    }
}
