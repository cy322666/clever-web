<?php

namespace App\Filament\Resources\Cases\CompanyCaseResource\Pages;

use App\Filament\Resources\Cases\CompanyCaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompanyCases extends ListRecords
{
    protected static string $resource = CompanyCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
