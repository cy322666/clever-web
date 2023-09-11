<?php

namespace App\Filament\Resources\Integrations\Active\LeadResource\Pages;

use App\Filament\Resources\Integrations\Active\LeadResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
