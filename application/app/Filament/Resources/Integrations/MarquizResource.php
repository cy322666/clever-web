<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\MarquizResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\Marquiz;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class MarquizResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Marquiz\Setting::class;

    protected static ?string $slug = 'settings/marquiz';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'Марквиз';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Настройки')
                    ->description('Для работы интеграции заполните обязательные поля и выполните настройки')
                    ->schema([
                        Repeater::make('settings')
                            ->label('Основное')
                            ->schema([

                                TextInput::make('link')
                                    ->label('Вебхук ссылка')
                                    ->disabled(),

                                TextInput::make('name_form')
                                    ->label('Название')
                                    ->hint('Для различения настроек форм'),

                                Select::make('pipeline_id')
                                    ->label('Воронка')
                                    ->options(Status::getPipelines()->pluck('pipeline_name', 'id'))
                                    ->required(),

                                Select::make('responsible_user_id')
                                    ->label('Ответственный')
                                    ->options(Staff::getWithUser()->pluck('name', 'id'))
                                    ->required(),

                                TextInput::make('phone')
                                    ->label('Телефон'),

                                TextInput::make('email')
                                    ->label('Почта'),

                                TextInput::make('name')
                                    ->label('Имя'),

                                TextInput::make('tag')
                                    ->label('Тег'),

                                Radio::make('is_union')
                                    ->label('Объединять повторные сделки')
                                    ->options([
                                        'yes' => 'Да',
                                        'no'  => 'Нет',
                                    ])
                                    ->required(),

//                                Radio::make('utms')
//                                    ->label('Действия с метками')
//                                    ->options([
//                                        'merge'   => 'Дополнять',
//                                        'rewrite' => 'Перезаписывать',
//                                    ])
//                                    ->required(),
                            ])
                            ->columns()
                            ->collapsible()
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->reorderableWithDragAndDrop(false)
                            ->addActionLabel('+ Добавить квиз'),
                    ])
                ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([])
            ->filters([])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'edit' => Pages\EditMarquiz::route('/{record}/edit'),
        ];
    }
}
