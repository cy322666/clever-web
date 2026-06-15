<?php

namespace App\Filament\Resources\Integrations\YClients\Schemas;

use App\Models\amoCRM\Field;
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

class YClientsForm
{
    public static function configure(Schema $schema): Schema
    {
        $pipelineOptions = Status::getPipelines()->pluck('pipeline_name', 'pipeline_id');
        $triggerStatusOptions = Status::getTriggerStatuses();
        $leadFieldOptions = Field::getLeadSelectFields();
        $contactFieldOptions = Field::getContactSelectFields();
        $ycFieldOptions = Setting::YCfieldsSelect();

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
                                    ->options($pipelineOptions)
                                    ->multiple()
                                    ->helperText(
                                        'Выберите воронки, которые будут использоваться для синхронизации с amoCRM'
                                    )
                                    ->searchable(),

                                Select::make('status_id_cancel')
                                    ->label('Этап клиент не пришел')
                                    ->options($triggerStatusOptions)
                                    ->searchable(),

                                Select::make('status_id_wait')
                                    ->label('Этап клиент записан')
                                    ->options($triggerStatusOptions)
                                    ->searchable(),

                                Select::make('status_id_came')
                                    ->label('Этап клиент пришел')
                                    ->options($triggerStatusOptions)
                                    ->searchable(),

                                Select::make('status_id_confirm')
                                    ->label('Этап клиент подтвердил')
                                    ->options($triggerStatusOptions)
                                    ->searchable(),

                                Select::make('status_id_delete')
                                    ->label('Этап запись удалена')
                                    ->options($triggerStatusOptions)
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
                                                    ->schema(self::mappingFields($leadFieldOptions, $ycFieldOptions))
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
                                                    ->schema(self::mappingFields($contactFieldOptions, $ycFieldOptions))
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

    private static function mappingFields($amoFields, array $ycFields): array
    {
        return [
            Select::make('field_yc')
                ->label('YClients')
                ->searchable()
                ->options($ycFields),

            Select::make('field_amo')
                ->label('amoCRM')
                ->searchable()
                ->options($amoFields),
        ];
    }
}
