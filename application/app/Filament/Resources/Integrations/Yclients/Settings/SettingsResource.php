<?php

namespace App\Filament\Resources\Integrations\Yclients\Settings;

use App\Filament\Resources\Integrations\Yclients\Settings\Pages\CreateSettings;
use App\Filament\Resources\Integrations\Yclients\Settings\Pages\EditSettings;
use App\Filament\Resources\Integrations\Yclients\Settings\Pages\ListSettings;
use App\Filament\Resources\Integrations\Yclients\Settings\Schemas\SettingsForm;
use App\Filament\Resources\Integrations\Yclients\Settings\Tables\SettingsTable;
use App\Models\Integrations\YClients\Setting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SettingsResource extends Resource
{
    protected static ?string $model = Setting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    //TODO
    public static function form(Schema $schema): Schema
    {
        return SettingsForm::configure($schema);
    }

    //TODO
    public static function table(Table $table): Table
    {
        return SettingsTable::configure($table);
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
//            'index'  => ListSettings::route('/'),
//            'create' => CreateSettings::route('/create'),
            'edit'   => EditSettings::route('/{record}/edit'),
        ];
    }
}
