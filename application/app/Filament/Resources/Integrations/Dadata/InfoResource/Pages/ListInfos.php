<?php

namespace App\Filament\Resources\Integrations\Dadata\InfoResource\Pages;

use App\Filament\Resources\Integrations\Dadata\InfoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInfos extends ListRecords
{
    protected static string $resource = InfoResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
