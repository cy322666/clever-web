<?php

namespace App\Filament\Resources\Integrations\YClients\Schemas;

use App\Models\amoCRM\Status;
use App\Models\Integrations\YClients\Setting;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\Facades\Auth;

class YClientsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('')
                    ->hiddenLabel()
                    ->schema([
                        Fieldset::make('Ссылки')
                            ->schema([
                                TextInput::make('link')
                                    ->label('Вебхук записи')
                                    ->helperText('Вставьте эту ссылку в настройки интеграции')
                                    ->copyable()
                                    ->disabled(),
                            ]),

                        Fieldset::make('Доступы')
                            ->schema([
                                TextInput::make('partner_token')
                                    ->label('Токен партнера')
                                    ->hint('123123'),

                                TextInput::make('user_token')
                                    ->label('Токен пользователя')
                                    ->hint('123123'),

//                                TextInput::make('login')
//                                    ->label('Логин')
//                                    ->required(),
//                                TextInput::make('password')
//                                    ->label('Пароль')
//                                    ->required(),
                            ]),

                        //TODO соотношение полей

                        Fieldset::make('Настройка amoCRM')
                            ->schema([

                                Select::make('status_id_cancel')
                                    ->label('Этап клиент не пришел')
                                    ->options(Status::getTriggerStatuses())
                                    ->searchable(),

                                Select::make('status_id_wait')
                                    ->label('Этап клиент записан')
                                    ->options(Status::getTriggerStatuses())
                                    ->searchable(),

                                Select::make('status_id_came')
                                    ->label('Этап клиент пришел')
                                    ->options(Status::getTriggerStatuses())
                                    ->searchable(),

                                Select::make('status_id_confirm')
                                    ->label('Этап клиент подтвердил')
                                    ->options(Status::getTriggerStatuses())
                                    ->searchable(),

                                Select::make('status_id_delete')
                                    ->label('Этап запись удалена')
                                    ->options(Status::getTriggerStatuses())
                                    ->searchable(),

                    //TODO нужно ли вообще? при подключении выбираешь же филиалы
//                                Select::make('branches')//TODO кнопка обновления филиалов
//                                    ->label('Филиалы')
//                                    ->multiple()
////                                    ->options(Branch::getWithUser()->pluck('name', 'id') ?? [])
//                                    ->options(
//                                        Staff::query()
//                                            ->where('user_id', Auth::id())
//                                            ->get()
//                                            ->pluck('name', 'staff_id')
//                                    )->searchable(),
                            ]),

                        Fieldset::make('Настройка amoCRM')
                            ->schema([
                                Repeater::make('fields_contact')
                                    ->label('Поля контакта')
                                    ->schema([

                                        Select::make('field_yc')
                                            ->label('Поле из YClients')
                                            ->searchable()
                                            ->options(Setting::YCfieldsSelect()),

                                        Select::make('field_amo')
                                            ->label('Поле из amoCRM')
                                            ->searchable()
                                            ->options(Auth::user()->amocrm_fields_contact()->pluck('name', 'id')),
                                    ])
//                                    ->columns()
//                                    ->collapsible()
                                    ->defaultItems(1)
                                    ->reorderable(false)
                                    ->reorderableWithDragAndDrop(false)
                                    ->addActionLabel('+ Добавить'),

                                Repeater::make('fields_lead')
                                    ->label('Поля сделки')
                                    ->schema([

                                        Select::make('field_yc')
                                            ->label('Поле из YClients')
                                            ->searchable()
                                            ->options(Setting::YCfieldsSelect()),

                                        Select::make('field_amo')
                                            ->label('Поле из amoCRM')
                                            ->searchable()
                                            ->options(Auth::user()->amocrm_fields_lead()->pluck('name', 'id')),
                                    ])
//                                    ->columns()
//                                    ->collapsible()
                                    ->defaultItems(1)
                                    ->reorderable(false)
                                    ->reorderableWithDragAndDrop(false)
                                    ->addActionLabel('+ Добавить')
                            ]),
                    ])
                    ->columnSpan(2),

                Section::make()
                    ->schema([
                        TextEntry::make('link')
                            ->label('Инструкция')
                            ->color('primary')
                            ->fontFamily(FontFamily::Mono)
                            ->weight(FontWeight::ExtraBold),

                        TextEntry::make('price6')
                            ->money('EUR', divideBy: 100),

                        TextEntry::make('price12')
                            ->money('EUR', divideBy: 100),

                        TextEntry::make('updated_at')
                            ->label('Обновлен')
                    ])
                    ->compact()
                    ->columnSpan(1),

            ])->columns(3);
    }
}
