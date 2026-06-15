<?php

namespace App\Filament\Resources\Integrations\YClients\Schemas;

use App\Models\amoCRM\Field;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\YClients\Setting;
use App\Support\Integrations\PricingView;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Illuminate\Support\Facades\Auth;

class YClientsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('')
                    ->hiddenLabel()
                    ->extraAttributes(['class' => 'self-start h-fit'])
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
                                    ->hint('См инструкцию'),

                                TextInput::make('user_token')
                                    ->label('Токен пользователя')
                                    ->hint('См инструкцию'),

//                                TextInput::make('login')
//                                    ->label('Логин')
//                                    ->required(),
//                                TextInput::make('password')
//                                    ->label('Пароль')
//                                    ->required(),
                            ]),

                        Fieldset::make('Соотношение этапов amoCRM')
                            ->schema([

                                Select::make('pipelines')
                                    ->label('Воронки')
                                    ->options(Status::getPipelines()->pluck('pipeline_name', 'pipeline_id'))
                                    ->multiple()
                                    ->helperText(
                                        'Выберите воронки, которые будут использоваться для синхронизации с amoCRM'
                                    )
                                    ->searchable(),

                                Select::make('default_responsible_user_id')
                                    ->label('Ответственный по умолчанию')
                                    ->helperText('Используется, если для создателя записи YClients не найдено соответствие.')
                                    ->options(fn() => Staff::query()
                                        ->where('user_id', Auth::id())
                                        ->where('active', true)
                                        ->orderBy('name')
                                        ->pluck('name', 'staff_id'))
                                    ->searchable()
                                    ->preload()
                                    ->native(false),

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

                        Section::make('Соотношение полей amoCRM')
                            ->description('Настройте только нужные поля. Сделки и контакты разделены по вкладкам.')
                            ->compact()
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                Tabs::make('Маппинг полей')
                                    ->contained(false)
                                    ->persistTabInQueryString('yc-fields-tab')
                                    ->tabs([
                                        Tab::make('Сделка')
                                            ->icon('heroicon-o-briefcase')
                                            ->schema([
                                                Repeater::make('fields_lead')
                                                    ->hiddenLabel()
                                                    ->schema(self::mappingFields(Field::getLeadSelectFields()))
                                                    ->columns(2)
                                                    ->defaultItems(0)
                                                    ->reorderable(false)
                                                    ->reorderableWithDragAndDrop(false)
                                                    ->addActionLabel('+ Добавить поле сделки'),
                                            ]),

                                        Tab::make('Контакт')
                                            ->icon('heroicon-o-user')
                                            ->schema([
                                                Repeater::make('fields_contact')
                                                    ->hiddenLabel()
                                                    ->schema(self::mappingFields(Field::getContactSelectFields()))
                                                    ->columns(2)
                                                    ->defaultItems(0)
                                                    ->reorderable(false)
                                                    ->reorderableWithDragAndDrop(false)
                                                    ->addActionLabel('+ Добавить поле контакта'),
                                            ]),
                                    ]),
                            ]),

                    ])
                    ->columnSpan(2),

                Section::make()
                    ->extraAttributes(['class' => 'self-start h-fit'])
                    ->schema([

                        Action::make('instruction')
                            ->label('Видео инструкция')
                            ->url('')
                            ->disabled()
                            ->openUrlInNewTab(),

                        Section::make()
                            ->schema([
                                TextEntry::make('pricing')
                                    ->hiddenLabel()
                                    ->html()
                                    ->state(fn($model) => PricingView::sidebarHtml($model::$cost)),
                            ])
                    ])
                    ->compact()
                    ->columnSpan(1),

            ])->columns(3);
    }

    private static function mappingFields($amoFields): array
    {
        return [
            Select::make('field_yc')
                ->label('YClients')
                ->searchable()
                ->options(Setting::YCfieldsSelect()),

            Select::make('field_amo')
                ->label('amoCRM')
                ->searchable()
                ->options($amoFields),
        ];
    }
}
