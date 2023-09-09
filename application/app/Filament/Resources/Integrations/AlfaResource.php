<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\AlfaResource\Pages;
use App\Models\amoCRM\Status;
use App\Models\Integrations\Alfa\Branch;
use App\Models\Integrations\Alfa\LeadStatus;
use App\Models\Integrations\Alfa\Setting;
use Filament\Forms\Components\Checkbox;
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

class AlfaResource extends Resource
{
    /**
     * @var string|null
     */
    protected static ?string $model = Setting::class;

    protected static ?string $recordTitleAttribute = 'АльфаСРМ';

    protected static ?string $slug = 'settings/alfacrm';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static bool $shouldRegisterNavigation = false;

    public static function getRecordTitle(?Model $record = null): string|Htmlable|null
    {
        return 'АльфаСРМ';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
               Section::make('Настройки')
                   ->description('Для работы интеграции заполните обязательные поля и выполните настройки')
                   ->schema([
                       Fieldset::make('Доступы')
                           ->schema([
                                TextInput::make('api_key')
                                    ->label('Токен')
                                    ->required(),
                                TextInput::make('domain')
                                    ->label('Домен')
                                    ->required(),
                               TextInput::make('email')
                                    ->label('Email')
                                    ->required(),
                           ])->columnSpan(2),
                   ]),

               Section::make('Настройки интеграции')
                   ->description('Соотнесите статусы воронки amoCRM и этапы в AlfaCRM')
                   ->schema([

                       Fieldset::make('Настройки amoCRM')
                           ->schema([

                               Select::make('status_record_1')
                                   ->label('Статус записи')
                                   ->options(Status::getWithoutUnsorted()->pluck('name', 'id') ?? [])
                                   ->searchable(),

                               Select::make('status_came_1')
                                   ->label('Статус пришедших')
                                   ->options(Status::getWithoutUnsorted()->pluck('name', 'id') ?? [])
                                   ->searchable(),

                               Select::make('status_omission_1')
                                   ->label('Статус отказавшихся')
                                   ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                   ->searchable(),
                           ]),

                       Fieldset::make('Настройка AlfaCRM')
                           ->schema([

                               Select::make('stage_record_1')
                                   ->label('Этап записи')
                                   ->options(LeadStatus::getWithUser()->pluck('name', 'id') ?? [])
                                   ->searchable(),

                               Select::make('stage_came_1')
                                   ->label('Этап пришедших')
                                   ->options(LeadStatus::getWithUser()->pluck('name', 'id') ?? [])
                                   ->searchable(),

                               Select::make('stage_omission_1')
                                   ->label('Этап отказавшихся')
                                   ->options(LeadStatus::getWithUser()->pluck('name', 'id') ?? [])
                                   ->searchable(),

                               Select::make('branch_id')
                                   ->label('Филиал')
                                   ->options(Branch::getWithUser()->pluck('name', 'id') ?? [])
                                   ->required()
                                   ->searchable(),

//                               Checkbox::make('work_lead')
//                                   ->label('Работа с лидами'),
                           ]),

                   ])->columns([
                       'sm' => 2,
                       'lg' => null,
                   ])
           ]
        );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([])
            ->filters([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'edit'   => Pages\EditAlfa::route('/{record}/edit'),
        ];
    }
}
