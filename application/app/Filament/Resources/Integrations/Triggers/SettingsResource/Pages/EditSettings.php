<?php

namespace App\Filament\Resources\Integrations\Triggers\SettingsResource\Pages;

use App\Filament\Resources\Integrations\Triggers\SettingsResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSettings extends EditRecord
{
    protected static string $resource = SettingsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
