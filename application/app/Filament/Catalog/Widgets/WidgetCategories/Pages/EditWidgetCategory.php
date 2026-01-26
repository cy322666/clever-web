<?php

namespace App\Filament\Catalog\Widgets\WidgetCategories\Pages;

use App\Filament\Catalog\Widgets\WidgetCategories\WidgetCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWidgetCategory extends EditRecord
{
    protected static string $resource = WidgetCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
