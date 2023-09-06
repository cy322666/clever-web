<?php

namespace App\Filament\Resources\Core\LogResource\Pages;

use App\Filament\Resources\Core\LogResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLogs extends ListRecords
{
    protected static string $resource = LogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
