<?php

namespace App\Filament\Catalog\Widgets\Widgets\Pages;

use App\Filament\Catalog\Widgets\Widgets\WidgetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWidgets extends ListRecords
{
    protected static string $resource = WidgetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
