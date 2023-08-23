<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\GetCourseResource\Pages;
use App\Models\Integrations\GetCourse;
use Filament\Forms;
use Filament\Resources\Resource;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class GetCourseResource extends Resource
{
    protected static ?string $model = GetCourse\Setting::class;

//    protected static ?string $navigationIcon = 'heroicon-o-collection';

    protected static ?string $slug = 'settings/getcourse';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'Геткурс';

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return 'Геткурс';
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Настройки')
                    ->description('Для работы интеграции заполните обязательные поля')
                    ->schema([

                        Forms\Components\Fieldset::make('Основное')
                            ->schema([
//                                Forms\Components\Builder\Block::make('Теги')
//                                    ->schema([
                                        Forms\Components\TextInput::make('response_user_id_default')
                                            ->label('Отв. по умолчанию')
                                            ->required(),
                                        Forms\Components\TextInput::make('response_user_id_form')->label('Отв. по заявкам'),
                                        Forms\Components\TextInput::make('response_user_id_order')->label('Отв. по заказам'),
//                                    ]),

//                                Forms\Components\Builder\Block::make('Этапы')
//                                    ->schema([
                                        Forms\Components\TextInput::make('status_id_order')
                                            ->label('Этап новых заказов')
                                            ->required(),
                                        Forms\Components\TextInput::make('status_id_order_close')
                                            ->label('Этап оплаченных заказов')
                                            ->required(),
                                        Forms\Components\TextInput::make('status_id_form')
                                            ->label('Этап новых заявок')
                                            ->required(),
//                                    ]),
                            ]),

                        Forms\Components\Fieldset::make('Основное')
                            ->schema([
//                                Forms\Components\Builder\Block::make('Названия')
//                                    ->schema([
                                        Forms\Components\TextInput::make('lead_name_order')->label('Название сделки для заказов'),
                                        Forms\Components\TextInput::make('lead_name_form')->label('Название сделки для заказов'),
//                                    ]),

//                                Forms\Components\Builder\Block::make('Теги')
//                                    ->schema([
                                        Forms\Components\TextInput::make('tag_order')->label('Тег для заказов'),
                                        Forms\Components\TextInput::make('tag_form')->label('Тег для заявок'),
//                                    ]),
                            ]),
                    ]),
            ]);
    }

    public static function table(\Filament\Tables\Table $table): \Filament\Tables\Table
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
            'index'  => Pages\ListGetCourses::route('/'),
            'create' => Pages\CreateGetCourse::route('/create'),
            'edit'   => Pages\EditGetCourse::route('/{record}/edit'),
        ];
    }
}