<?php

namespace App\Filament\Resources\Integrations\AlfaResource\Pages;

use App\Filament\Resources\Integrations\AlfaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAlfas extends ListRecords
{
    protected static string $resource = AlfaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
