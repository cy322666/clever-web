<?php

namespace App\Filament\Resources\Integrations\TildaResource\Pages;

use App\Filament\Resources\Integrations\TildaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTildas extends ListRecords
{
    protected static string $resource = TildaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
