<?php

namespace App\Filament\Resources\Integrations\Vetmanager\Schemas;

use App\Models\amoCRM\Field;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\Vetmanager\Setting;
use App\Services\Vetmanager\VetmanagerApiClient;
use App\Support\Integrations\PricingView;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Throwable;

class VetmanagerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('')
                    ->hiddenLabel()
                    ->extraAttributes(['class' => 'self-start h-fit'])
                    ->schema([
                        Fieldset::make('Подключение Vetmanager')
                            ->schema([
                                TextInput::make('base_url')
                                    ->label('Адрес кабинета')
                                    ->placeholder('https://clinic.vetmanager.ru')
                                    ->helperText('HTTPS-адрес вашего кабинета Vetmanager.')
                                    ->required()
                                    ->rules([
                                        function (): Closure {
                                            return function (string $attribute, mixed $value, Closure $fail): void {
                                                try {
                                                    VetmanagerApiClient::normalizeBaseUrl((string) $value);
                                                } catch (Throwable $exception) {
                                                    $fail($exception->getMessage());
                                                }
                                            };
                                        },
                                    ])
                                    ->columnSpanFull(),

                                TextInput::make('api_key')
                                    ->label('REST API ключ')
                                    ->password()
                                    ->revealable()
                                    ->dehydrated(fn (?string $state): bool => filled($state))
                                    ->required(fn (?Setting $record): bool => blank($record?->api_key))
                                    ->columnSpanFull(),

                                Select::make('timezone')
                                    ->label('Часовой пояс')
                                    ->options([
                                        'Europe/Kaliningrad' => 'Калининград',
                                        'Europe/Moscow' => 'Москва',
                                        'Asia/Yekaterinburg' => 'Екатеринбург',
                                        'Asia/Novosibirsk' => 'Новосибирск',
                                        'Asia/Vladivostok' => 'Владивосток',
                                    ])
                                    ->default('Europe/Moscow')
                                    ->searchable()
                                    ->required(),

                                TextInput::make('webhook_url')
                                    ->label('Webhook URL')
                                    ->copyable()
                                    ->disabled()
                                    ->columnSpanFull(),
                            ]),

                        Fieldset::make('Создание сделки')
                            ->schema([
                                Select::make('target_status')
                                    ->label('Этап сделки')
                                    ->options(fn ($record = null): array => self::statusOptions($record))
                                    ->searchable()
                                    ->required(),

                                Select::make('responsible_user_id')
                                    ->label('Ответственный')
                                    ->options(fn ($record = null): array => self::staffOptions($record))
                                    ->searchable(),

                                Toggle::make('sync_price')
                                    ->label('Записывать сумму приема в бюджет сделки')
                                    ->default(true)
                                    ->columnSpanFull(),
                            ]),

                        Section::make('Соотношение полей amoCRM')
                            ->description('Настройте только нужные поля. Сделки и контакты разделены по вкладкам.')
                            ->compact()
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                Tabs::make('Маппинг полей')
                                    ->contained(false)
                                    ->persistTabInQueryString('vetmanager-fields-tab')
                                    ->tabs([
                                        Tab::make('Сделка')
                                            ->icon('heroicon-o-briefcase')
                                            ->schema([
                                                Repeater::make('fields_lead')
                                                    ->hiddenLabel()
                                                    ->schema(self::mappingFields('leads'))
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
                                                    ->schema(self::mappingFields('contacts'))
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
                                    ->state(fn ($model) => PricingView::sidebarHtml($model::$cost)),
                            ]),
                    ])
                    ->compact()
                    ->columnSpan(1),
            ])
            ->columns(3);
    }

    /**
     * @return array<int, Select>
     */
    private static function mappingFields(string $entityType): array
    {
        return [
            Select::make('field_vetmanager')
                ->label('Vetmanager')
                ->options(Setting::sourceFieldOptions($entityType))
                ->searchable()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('field_amo', null))
                ->required(),

            Select::make('field_amo')
                ->label('amoCRM')
                ->options(fn (Get $get, $record = null): array => self::fieldOptions(
                    $record,
                    $entityType,
                    Setting::sourceFieldTypes($entityType, (string) $get('field_vetmanager')),
                ))
                ->searchable()
                ->required(),
        ];
    }

    private static function ownerUserId(mixed $record): ?int
    {
        return $record instanceof Setting && $record->user_id
            ? (int) $record->user_id
            : (auth()->id() ? (int) auth()->id() : null);
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

    /**
     * @param  array<int, string>  $types
     */
    private static function fieldOptions(mixed $record, string $entityType, array $types): array
    {
        $userId = self::ownerUserId($record);

        return $userId ? Field::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->where('entity_type', $entityType)
            ->whereIn('type', $types)
            ->orderBy('name')
            ->pluck('name', 'field_id')
            ->toArray() : [];
    }
}
