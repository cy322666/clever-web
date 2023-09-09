<?php

namespace App\Filament\Resources\ActiveLeadResource\Pages;

use App\Filament\Resources\ActiveLeadResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditActiveLead extends EditRecord
{
    protected static string $resource = ActiveLeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
