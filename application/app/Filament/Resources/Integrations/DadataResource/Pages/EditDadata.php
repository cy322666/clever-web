<?php

namespace App\Filament\Resources\Integrations\DadataResource\Pages;

use App\Filament\Resources\Integrations\Active\LeadResource;
use App\Filament\Resources\Integrations\DadataResource;
use App\Helpers\Actions\UpdateButton;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditDadata extends EditRecord
{
    protected static string $resource = DadataResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UpdateButton::getAction($this->record),

            Actions\Action::make('instruction')
                ->label('Инструкция'),

//            Actions\Action::make('list')
//                ->label('История')
//                ->url(LeadResource::getUrl())//TODO
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
