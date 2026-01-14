<?php

namespace App\Filament\Resources\Integrations\YClients\Pages;

use App\Filament\Resources\Integrations\YClients\YClientsResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListYClients extends ListRecords
{
    protected static string $resource = YClientsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
