<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AlfaResource\Pages;
use App\Models\Integrations\Alfa\Setting;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AlfaResource extends Resource
{
    /**
     * @var string|null
     */
    protected static ?string $model = Setting::class;

    protected static ?string $slug = 'integrations/alfa/settings';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Form $form): Form
    {
        return $form->schema([
               Forms\Components\Section::make('Настройки')
                   ->description('Для работы интеграции заполните обязательные поля')
                   ->schema([
                       Forms\Components\Fieldset::make('Доступы')
                           ->schema([
//                                Forms\Components\TextInput::make('login'),
//                                Forms\Components\TextInput::make('password'),
                               Forms\Components\TextInput::make('token')
                                   ->label('Токен')
                                   ->required(),
                           ])->columnSpan(2),
                   ]),

               Forms\Components\Section::make('Сегментация')
                   ->description('Разделите посетителей вебинара на сегементы по времени нахождения на вебинаре')
                   ->schema([

                       Forms\Components\Fieldset::make('Условия')
                           ->schema([
//                                Forms\Components\Builder\Block::make('Этапы')
//                                    ->schema([
                               Forms\Components\Select::make('status_id_cold')
                                   ->label('Этап холодных')
                                   ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                   ->searchable(),

                               Forms\Components\Select::make('status_id_soft')
                                   ->label('Этап теплых')
                                   ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                   ->searchable(),

                               Forms\Components\Select::make('status_id_hot')
                                   ->label('Этап горячих')
                                   ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                   ->searchable(),
//                                    ]),

//                                Forms\Components\Builder\Block::make('Этапы')
//                                    ->schema([
                               Forms\Components\TextInput::make('time_cold')
                                   ->label('Время холодных')
                                   ->required(),
                               Forms\Components\TextInput::make('time_soft')
                                   ->label('Время теплых')
                                   ->required(),
                               Forms\Components\TextInput::make('time_hot')
                                   ->label('Время горячих')
                                   ->required(),
                           ]),

                   ])->columns([
                       'sm' => 2,
                       'lg' => null,
                   ]),

               Forms\Components\Fieldset::make('Сделки')
                   ->schema([

//                                Forms\Components\Builder\Block::make('Теги')
//                                    ->schema([
                       Forms\Components\TextInput::make('tag_cold')->label('Тег холодных'),
                       Forms\Components\TextInput::make('tag_soft')->label('Тег теплых'),
                       Forms\Components\TextInput::make('tag_hot')->label('Тег горячих'),
                       Forms\Components\TextInput::make('tag')->label('Тег по умолчанию'),
//                                    ]),

//                                Forms\Components\Builder\Block::make('Другое')
//                                    ->schema([

                       Forms\Components\Select::make('response_user_id')
                           ->label('Ответственный по умолчанию')
                           ->options(Staff::getWithUser()->pluck('name', 'id'))
                           ->searchable(),

                       Forms\Components\Select::make('pipeline_id')
                           ->label('Вебинарная воронка')
                           ->options(Status::getPipelines()->pluck('pipeline_name', 'id'))
                           ->searchable(),
//                                    ]),
                   ])
                   ->columns([
                       'sm' => 2,
                       'lg' => null,
                   ]),
//                    ])
            ]);
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
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAlfas::route('/'),
            'create' => Pages\CreateAlfa::route('/create'),
            'edit' => Pages\EditAlfa::route('/{record}/edit'),
        ];
    }
}
