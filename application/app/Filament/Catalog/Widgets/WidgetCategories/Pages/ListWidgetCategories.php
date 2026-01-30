<?php

namespace App\Filament\Catalog\Widgets\WidgetCategories\Pages;

use App\Filament\Catalog\Widgets\WidgetCategories\WidgetCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWidgetCategories extends ListRecords
{
    protected static string $resource = WidgetCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
