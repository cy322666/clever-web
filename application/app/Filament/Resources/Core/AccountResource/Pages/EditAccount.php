<?php

namespace App\Filament\Resources\Core\AccountResource\Pages;

use App\Filament\Resources\Core\AccountResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAccount extends EditRecord
{
    protected static string $resource = AccountResource::class;

    protected function getActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
