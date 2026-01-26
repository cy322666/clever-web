<?php

namespace App\Filament\Catalog\Widgets\WidgetCategories\Pages;

use App\Filament\Catalog\Widgets\WidgetCategories\WidgetCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWidgetCategory extends CreateRecord
{
    protected static string $resource = WidgetCategoryResource::class;
}
