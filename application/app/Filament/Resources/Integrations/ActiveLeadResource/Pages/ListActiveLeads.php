<?php

namespace App\Filament\Resources\ActiveLeadResource\Pages;

use App\Filament\Resources\ActiveLeadResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListActiveLeads extends ListRecords
{
    protected static string $resource = ActiveLeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
