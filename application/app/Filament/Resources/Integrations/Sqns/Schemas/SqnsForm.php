<?php

namespace App\Filament\Resources\Integrations\Sqns\Schemas;

use App\Models\amoCRM\Field;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\Sqns\Setting;
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

class SqnsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('')
                    ->hiddenLabel()
                    ->extraAttributes(['class' => 'self-start h-fit'])
                    ->schema([
                        Fieldset::make('Подключение SQNS')
                            ->schema([
                                Select::make('api_base_url')
                                    ->label('Сервер SQNS')
                                    ->options([
                                        'https://crm3.sqns.ru' => 'crm3.sqns.ru',
                                        'https://crm2.sqns.ru' => 'crm2.sqns.ru',
                                    ])
                                    ->default('https://crm3.sqns.ru')
                                    ->required(),

                                TextInput::make('email')
                                    ->label('Email')
                                    ->email()
                                    ->required(),

                                TextInput::make('password')
                                    ->label('Пароль')
                                    ->password()
                                    ->revealable()
                                    ->dehydrated(fn (?string $state): bool => filled($state))
                                    ->required(fn (?Setting $record): bool => blank($record?->password)),
                            ]),

                        Fieldset::make('Этапы amoCRM')
                            ->schema([
                                Select::make('pipelines')
                                    ->label('Воронки SQNS')
                                    ->options(fn ($record = null): array => self::pipelineOptions($record))
                                    ->multiple()
                                    ->searchable()
                                    ->helperText('Поиск и создание сделок выполняются только в этих воронках.'),

                                Select::make('default_responsible_user_id')
                                    ->label('Ответственный по умолчанию')
                                    ->options(fn ($record = null): array => self::staffOptions($record))
                                    ->searchable(),

                                Select::make('status_id_wait')
                                    ->label('Клиент записан')
                                    ->options(fn ($record = null): array => self::statusOptions($record))
                                    ->required()
                                    ->searchable(),

                                Select::make('status_id_confirm')
                                    ->label('Клиент подтвердил')
                                    ->options(fn ($record = null): array => self::statusOptions($record))
                                    ->required()
                                    ->searchable(),

                                Select::make('status_id_came')
                                    ->label('Клиент пришёл')
                                    ->options(fn ($record = null): array => self::statusOptions($record))
                                    ->required()
                                    ->searchable(),

                                Select::make('status_id_cancel')
                                    ->label('Клиент отменил')
                                    ->options(fn ($record = null): array => self::statusOptions($record))
                                    ->required()
                                    ->searchable(),

                                Select::make('status_id_delete')
                                    ->label('Запись удалена')
                                    ->options(fn ($record = null): array => self::statusOptions($record))
                                    ->required()
                                    ->searchable(),
                            ]),

                        Section::make('Соотношение полей amoCRM')
                            ->description('Укажите только поля, которые требуется обновлять из SQNS.')
                            ->compact()
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                Tabs::make('Маппинг полей')
                                    ->contained(false)
                                    ->persistTabInQueryString('sqns-fields-tab')
                                    ->tabs([
                                        Tab::make('Сделка')
                                            ->icon('heroicon-o-briefcase')
                                            ->schema([
                                                Repeater::make('fields_lead')
                                                    ->hiddenLabel()
                                                    ->schema(self::mappingFields(
                                                        fn ($record = null): array => self::fieldOptions($record, 'leads'),
                                                    ))
                                                    ->columns(2)
                                                    ->defaultItems(0)
                                                    ->reorderable(false)
                                                    ->addActionLabel('+ Добавить поле сделки'),
                                            ]),

                                        Tab::make('Контакт')
                                            ->icon('heroicon-o-user')
                                            ->schema([
                                                Repeater::make('fields_contact')
                                                    ->hiddenLabel()
                                                    ->schema(self::mappingFields(
                                                        fn ($record = null): array => self::fieldOptions($record, 'contacts'),
                                                    ))
                                                    ->columns(2)
                                                    ->defaultItems(0)
                                                    ->reorderable(false)
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
                                    ->state(fn ($model) => PricingView::sidebarHtml($model::$cost)),
                            ]),
                    ])
                    ->compact()
                    ->columnSpan(1),
            ])
            ->columns(3);
    }

    private static function mappingFields(callable $amoFields): array
    {
        return [
            Select::make('field_sqns')
                ->label('SQNS')
                ->searchable()
                ->options(Setting::sourceFieldOptions()),

            Select::make('field_amo')
                ->label('amoCRM')
                ->searchable()
                ->options($amoFields),
        ];
    }

    private static function ownerUserId(mixed $record): ?int
    {
        return $record instanceof Setting && $record->user_id
            ? (int) $record->user_id
            : (auth()->id() ? (int) auth()->id() : null);
    }

    private static function pipelineOptions(mixed $record): array
    {
        $userId = self::ownerUserId($record);

        return $userId ? Status::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->where('is_archive', false)
            ->select('pipeline_id', 'pipeline_name')
            ->groupBy('pipeline_id', 'pipeline_name')
            ->orderBy('pipeline_name')
            ->pluck('pipeline_name', 'pipeline_id')
            ->toArray() : [];
    }

    private static function statusOptions(mixed $record): array
    {
        $userId = self::ownerUserId($record);
        $options = [];

        if (! $userId) {
            return $options;
        }

        foreach (Status::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->where('is_archive', false)
            ->where('name', '!=', 'Неразобранное')
            ->orderBy('pipeline_name')
            ->orderBy('id')
            ->get() as $status) {
            $options[$status->pipeline_name][$status->pipeline_id.'.'.$status->status_id] = $status->name;
        }

        return $options;
    }

    private static function staffOptions(mixed $record): array
    {
        $userId = self::ownerUserId($record);

        return $userId ? Staff::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->orderBy('name')
            ->pluck('name', 'staff_id')
            ->toArray() : [];
    }

    private static function fieldOptions(mixed $record, string $entityType): array
    {
        $userId = self::ownerUserId($record);

        if (! $userId) {
            return [];
        }

        $options = Field::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->where('entity_type', $entityType)
            ->orderBy('name')
            ->pluck('name', 'field_id')
            ->toArray();

        return $entityType === 'leads' ? ['system:price' => 'Бюджет'] + $options : $options;
    }
}
