<?php

namespace App\Filament\Resources\Integrations\ActiveLeadResource\Pages;

use App\Filament\Resources\Integrations\Active\LeadResource;
use App\Filament\Resources\Integrations\ActiveLeadResource;
use App\Filament\Resources\Integrations\Tilda\FormResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

use Illuminate\Support\Facades\Auth;

use function route;

class EditActiveLead extends EditRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = ActiveLeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UpdateButton::getAction($this->record),

            UpdateButton::amoCRMSyncButton(Auth::user()->account),

            Actions\Action::make('instruction')
                ->label('Инструкция'),

            Actions\Action::make('list')
                ->label('История')
                ->url(LeadResource::getUrl())
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['link'] = route('active-leads.hook', [
            'user' => Auth::user()->uuid,
        ]);

        return $data;
    }
}
