<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\DadataResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\amoCRM\Field;
use App\Models\amoCRM\Status;
use App\Models\Integrations\ActiveLead\Setting;
use App\Models\Integrations\Bizon\Viewer;
use App\Models\Integrations\Dadata;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class DadataResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Dadata\Setting::class;

    protected static ?string $slug = 'settings/data';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'Инфо по номеру';

    public static function getTransactions(): int
    {
        return 0;
    }

    public static function form(Schema $form): Schema
    {
        return $form
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
}
