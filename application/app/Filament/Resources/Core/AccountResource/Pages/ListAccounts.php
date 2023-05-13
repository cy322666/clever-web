<?php

namespace App\Filament\Resources\Core\AccountResource\Pages;

use App\Filament\Resources\Core\AccountResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
