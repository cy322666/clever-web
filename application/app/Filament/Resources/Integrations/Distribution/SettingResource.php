<?php

namespace App\Filament\Resources\Integrations\Distribution;

use App\Filament\Resources\Integrations\Distribution\SettingResource\Pages;
use App\Models\Integrations\Distribution\Setting;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SettingResource extends Resource
{
    protected static ?string $model = Setting::class;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
            ])
            ->filters([
                //
            ]);
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
            'edit' => Pages\EditSetting::route('/{record}/edit'),
        ];
    }
}
