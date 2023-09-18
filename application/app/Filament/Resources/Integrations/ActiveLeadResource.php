<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\ActiveLeadResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\amoCRM\Status;
use App\Models\Integrations\ActiveLead\Setting;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class ActiveLeadResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Setting::class;

    protected static ?string $slug = 'settings/active';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'Проверка дубля';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Регистрации')
                    ->description('Настройки для регистраций')
                    ->schema([

                        Fieldset::make('Условия')
                            ->schema([

                                TextInput::make('link')
                                    ->label('Вебхук ссылка'),

                                Select::make('condition')
                                    ->label('Проверять по одной воронке')
                                    ->options([
                                        Setting::CONDITION_PIPELINE => 'Проверять воронку',
                                        Setting::CONDITION_ALL      => 'Проверять везде',
                                    ]),

                                Select::make('pipeline_id')
                                    ->label('Воронка для проверки')
                                    ->options(Status::getPipelines()->pluck('pipeline_name', 'id'))
                                    ->searchable(),

                                TextInput::make('tag')
                                    ->label('Тег'),
                            ]),

                    ])->columns([
                        'sm' => 2,
                        'lg' => null,
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'edit' => Pages\EditActiveLead::route('/{record}/edit'),
        ];
    }
}
