<?php

namespace App\Filament\Resources\BizonResource\Pages;

use App\Filament\Resources\BizonResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBizons extends ListRecords
{
    protected static string $resource = BizonResource::class;

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
