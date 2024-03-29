<?php

namespace App\Filament\Resources\Integrations\Tilda\FormResource\Pages;

use App\Filament\Resources\Integrations\Tilda\FormResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListForms extends ListRecords
{
    protected static string $resource = FormResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
