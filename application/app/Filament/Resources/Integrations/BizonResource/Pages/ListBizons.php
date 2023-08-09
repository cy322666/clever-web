<?php

namespace App\Filament\Resources\Integrations\BizonResource\Pages;

use App\Filament\Resources\Integrations\BizonResource;
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
