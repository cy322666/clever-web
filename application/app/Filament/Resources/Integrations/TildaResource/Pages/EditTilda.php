<?php

namespace App\Filament\Resources\Integrations\TildaResource\Pages;

use App\Filament\Resources\Integrations\TildaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTilda extends EditRecord
{
    protected static string $resource = TildaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
