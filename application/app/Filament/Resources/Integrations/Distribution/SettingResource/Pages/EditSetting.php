<?php

namespace App\Filament\Resources\Integrations\Distribution\SettingResource\Pages;

use App\Filament\Resources\Integrations\Distribution\SettingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSetting extends EditRecord
{
    protected static string $resource = SettingResource::class;

//    protected function getHeaderActions(): array
//    {
//        return [
//            Actions\DeleteAction::make(),
//        ];
//    }
}
