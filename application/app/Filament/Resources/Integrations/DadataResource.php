<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\DadataResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\Integrations\Dadata;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class DadataResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Dadata\Setting::class;

    protected static ?string $slug = 'integrations/dadata';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'Dadata';

    public static function getTransactions(): int
    {
        return 0;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Инфо')
                    ->description('Настройки для заполнения полей')
                    ->schema([

                        Fieldset::make('Настройки')
                            ->schema([

                                TextInput::make('link')
                                    ->copyable()
                                    ->label('Вебхук ссылка'),

                                Select::make('field_country')
                                    ->label('Поле страны')
                                    ->options(Auth::user()->amocrm_fields->pluck('name', 'id'))
                                    ->searchable(),

                                Select::make('field_city')
                                    ->label('Поле города')
                                    ->options(Auth::user()->amocrm_fields->pluck('name', 'id'))
                                    ->searchable(),

                                Select::make('field_timezone')
                                    ->label('Поле часового пояса')
                                    ->options(Auth::user()->amocrm_fields->pluck('name', 'id'))
                                    ->searchable(),

                                Select::make('field_region')
                                    ->label('Поле региона')
                                    ->options(Auth::user()->amocrm_fields->pluck('name', 'id'))
                                    ->searchable(),

                                Select::make('field_provider')
                                    ->label('Поле оператора')
                                    ->options(Auth::user()->amocrm_fields->pluck('name', 'id'))
                                    ->searchable(),
                            ]),

                    ])
            ])->columns(1);
    }

    public static function getPages(): array
    {
        return [
            'edit' => Pages\EditDadata::route('/{record}/edit'),
        ];
    }

    public static function clearTransactions(int $days = 7): bool
    {
        return true;
    }
}
