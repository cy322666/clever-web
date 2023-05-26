<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BizonResource\Pages;
use App\Models\Integrations\Bizon\Setting;
use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BizonResource extends Resource
{
    protected static ?string $model = Setting::class;

    protected static ?string $navigationIcon = 'heroicon-o-collection';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Настройки')
                    ->description('Для работы интеграции заполните обязательные поля')
                    ->schema([

                        Forms\Components\Fieldset::make('Доступы')
                            ->schema([
//                                Forms\Components\TextInput::make('login'),
//                                Forms\Components\TextInput::make('password'),
                                Forms\Components\TextInput::make('token')->label('Токен'),
                            ])->columnSpan(2),
                    ]),

                Forms\Components\Section::make('Сегментация')
                    ->description('Разделите посетителей вебинара на сегементы по времени нахождения на вебинаре')
                    ->schema([

                        Forms\Components\Fieldset::make('Условия')
                            ->schema([
                                Forms\Components\Builder\Block::make('Этапы')
                                    ->schema([
                                        Forms\Components\TextInput::make('status_id_cold')->label('Этап холодных'),
                                        Forms\Components\TextInput::make('status_id_soft')->label('Этап теплых'),
                                        Forms\Components\TextInput::make('status_id_hot')->label('Этап горячих'),
                                    ]),

                                Forms\Components\Builder\Block::make('Этапы')
                                    ->schema([
                                        Forms\Components\TextInput::make('time_cold')->label('Время холодных'),
                                        Forms\Components\TextInput::make('time_soft')->label('Время теплых'),
                                        Forms\Components\TextInput::make('time_hot')->label('Время горячих'),
                                    ]),

                            ])->columns([
                                'sm' => 2,
                                'lg' => null,
                            ]),

                        Forms\Components\Fieldset::make('Сделки')
                            ->schema([

                                Forms\Components\Builder\Block::make('Теги')
                                    ->schema([
                                        Forms\Components\TextInput::make('tag_cold')->label('Тег холодных'),
                                        Forms\Components\TextInput::make('tag_soft')->label('Тег теплых'),
                                        Forms\Components\TextInput::make('tag_hot')->label('Тег горячих'),
                                        Forms\Components\TextInput::make('tag')->label('Тег по умолчанию'),
                                    ]),

                                Forms\Components\Builder\Block::make('Другое')
                                    ->schema([
                                        Forms\Components\TextInput::make('response_user_id')->label('Ответственный по умолчанию'),
                                        Forms\Components\TextInput::make('pipeline_id')->label('Вебинарная воронка'),
                                    ]),
                            ])
                            ->columns([
                                'sm' => 2,
                                'lg' => null,
                            ]),
                    ])
            ]);
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
//                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
//                Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListBizons::route('/'),
            'create' => Pages\CreateBizon::route('/create'),
            'edit' => Pages\EditBizon::route('/{record}/edit'),
        ];
    }
}
