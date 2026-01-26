<?php

namespace App\Filament\Catalog\Widgets\WidgetCategories;

use App\Filament\Catalog\Widgets\WidgetCategories\Pages\CreateWidgetCategory;
use App\Filament\Catalog\Widgets\WidgetCategories\Pages\EditWidgetCategory;
use App\Filament\Catalog\Widgets\WidgetCategories\Pages\ListWidgetCategories;
use App\Filament\Catalog\Widgets\WidgetCategories\Schemas\WidgetCategoryForm;
use App\Filament\Catalog\Widgets\WidgetCategories\Tables\WidgetCategoriesTable;
use App\Models\Widgets\WidgetCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WidgetCategoryResource extends Resource
{
    protected static ?string $model = WidgetCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return WidgetCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WidgetCategoriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWidgetCategories::route('/'),
            'create' => CreateWidgetCategory::route('/create'),
            'edit' => EditWidgetCategory::route('/{record}/edit'),
        ];
    }
}
