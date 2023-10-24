<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\GetCourseResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\GetCourse;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class GetCourseResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = GetCourse\Setting::class;

    protected static ?string $slug = 'settings/getcourse';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'Геткурс';

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Настройки')
                    ->description('Для работы интеграции заполните обязательные поля и выполните настройки')
                    ->schema([

                        Forms\Components\Fieldset::make('Вебхуки')
                            ->schema([

                                Forms\Components\TextInput::make('link_order')
                                    ->label('Вебхук ссылка для заказов')
                                    ->disabled(),

                                Forms\Components\Select::make('response_user_id_default')
                                    ->label('Отв. по умолчанию')
                                    ->options(Staff::getWithUser()->pluck('name', 'id'))
                                    ->searchable()
                                    ->required(),
                                Forms\Components\Select::make('response_user_id_order')
                                    ->label('Отв. по заказам')
                                    ->options(Staff::getWithUser()->pluck('name', 'id'))
                                    ->searchable(),
                                Forms\Components\Select::make('status_id_order')
                                    ->label('Этап новых заказов')
                                    ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                    ->searchable(),
                                Forms\Components\Select::make('status_id_order_close')
                                    ->label('Этап оплаченных заказов')
                                    ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                    ->searchable(),

                                Forms\Components\TextInput::make('tag_order')->label('Тег для заказов'),

//                                Forms\Components\TextInput::make('link_form')
//                                    ->label('Вебхук ссылка для форм')
//                                    ->disabled(),
                             ]),

                        Forms\Components\Repeater::make('settings')
                            ->label('Регистрации')
                            ->schema([

                                Forms\Components\TextInput::make('link_form')
                                    ->label('Вебхук ссылка')
                                    ->disabled(),

                                Forms\Components\TextInput::make('name_form')
                                    ->label('Название')
                                    ->hint('Для различения настроек форм'),

                                Forms\Components\Select::make('status_id')
                                    ->label('Этап')
                                    ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                    ->required(),

                                Forms\Components\Select::make('pipeline_id')
                                    ->label('Воронка')
                                    ->options(Status::getPipelines()->pluck('pipeline_name', 'id'))
                                    ->required(),

                                Forms\Components\Select::make('responsible_user_id')
                                    ->label('Ответственный')
                                    ->options(Staff::getWithUser()->pluck('name', 'id'))
                                    ->required(),

                                Forms\Components\TextInput::make('tag')
                                    ->label('Тег'),

                                Forms\Components\Radio::make('is_union')
                                    ->label('Объединять повторные сделки')
                                    ->options([
                                        'yes' => 'Да',
                                        'no'  => 'Нет',
                                    ])
                                    ->required(),

                                Forms\Components\Radio::make('utms')
                                    ->label('Действия с метками')
                                    ->options([
                                        'merge'   => 'Дополнять',
                                        'rewrite' => 'Перезаписывать',
                                    ])
                                    ->required(),

                                Forms\Components\Repeater::make('fields')
                                    ->label('Соотношение полей сделки')
                                    ->schema([

                                        Forms\Components\TextInput::make('field_form')
                                            ->label('Поле из формы')
                                            ->required(),

                                        Forms\Components\Select::make('field_amo')
                                            ->label('Поле из amoCRM')
                                            ->options(Auth::user()->amocrm_fields()->pluck('name', 'id'))
                                            ->required(),
                                    ])
                                    ->columns()
                                    ->collapsible()
                                    ->defaultItems(1)
                                    ->reorderable(false)
                                    ->reorderableWithDragAndDrop(false)
                                    ->addActionLabel('+ Добавить поле')
                            ])
                            ->columns()
                            ->collapsible()
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->reorderableWithDragAndDrop(false)
                            ->addActionLabel('+ Добавить форму')
                    ]),
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
            'edit'   => Pages\EditGetCourse::route('/{record}/edit'),
        ];
    }
}
