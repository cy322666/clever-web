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

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return 'АльфаСРМ';
    }

    protected static ?string $slug = 'settings/alfacrm';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Form $form): Form
    {
        return $form->schema([
               Section::make('Настройки')
                   ->description('Для работы интеграции заполните обязательные поля')
                   ->schema([
                       Fieldset::make('Доступы')
                           ->schema([
                                TextInput::make('login')
                                    ->label('Почта')
                                    ->required(),
                                TextInput::make('password')
                                    ->label('Пароль')
                                    ->required(),
                           ])->columnSpan(2),
                   ]),

               Section::make('Сегментация')
                   ->description('Разделите посетителей вебинара на сегементы по времени нахождения на вебинаре')
                   ->schema([

                       Fieldset::make('Условия')
                           ->schema([
//                                Forms\Components\Builder\Block::make('Этапы')
//                                    ->schema([
                               Select::make('status_record_1')
                                   ->label('Этап записи')
                                   ->options(Status::getWithoutUnsorted()->pluck('name', 'id') ?? [])
                                   ->searchable(),

                               Select::make('status_came_1')
                                   ->label('Этап пришедших')
                                   ->options(Status::getWithoutUnsorted()->pluck('name', 'id') ?? [])
                                   ->searchable(),

                               Select::make('status_omission_1')
                                   ->label('Этап отказавшихся')
                                   ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                   ->searchable(),


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
//                                    ]),

//                                Forms\Components\Builder\Block::make('Этапы')
//                                    ->schema([

//                               Select::make('branch_id')
//                                   ->label('Филиал')
//                                   ->options(Branch::getWithUser()->pluck('name', 'id') ?? [])
//                                   ->searchable(),

                               Checkbox::make('work_lead')
                                   ->label('Работа с лидами')
                                   ->required(),
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
            ->columns([
                //
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAlfas::route('/'),
            'create' => Pages\CreateAlfa::route('/create'),
            'edit'   => Pages\EditAlfa::route('/{record}/edit'),
        ];
    }
}
