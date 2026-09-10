<?php

namespace App\Filament\Resources\Integrations\Vetmanager\Schemas;

use App\Models\amoCRM\Field;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\Vetmanager\Setting;
use App\Services\Vetmanager\VetmanagerApiClient;
use App\Support\Integrations\PricingView;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
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

                        Section::make('Поля amoCRM')
                            ->description('Необязательно. Эти поля помогают точно связывать клиентов и приемы при повторных событиях.')
                            ->compact()
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                Select::make('contact_external_id_field_id')
                                    ->label('Контакт: ID клиента Vetmanager')
                                    ->options(fn ($record = null): array => self::fieldOptions($record, 'contacts', ['text', 'numeric']))
                                    ->searchable(),

                                Select::make('lead_external_id_field_id')
                                    ->label('Сделка: ID приема Vetmanager')
                                    ->options(fn ($record = null): array => self::fieldOptions($record, 'leads', ['text', 'numeric']))
                                    ->searchable(),

                                Select::make('lead_admission_date_field_id')
                                    ->label('Сделка: дата приема')
                                    ->options(fn ($record = null): array => self::fieldOptions($record, 'leads', ['date', 'date_time']))
                                    ->searchable(),

                                Select::make('lead_patient_name_field_id')
                                    ->label('Сделка: питомец')
                                    ->options(fn ($record = null): array => self::fieldOptions($record, 'leads', ['text', 'textarea']))
                                    ->searchable(),

                                Select::make('lead_doctor_name_field_id')
                                    ->label('Сделка: врач')
                                    ->options(fn ($record = null): array => self::fieldOptions($record, 'leads', ['text', 'textarea']))
                                    ->searchable(),

                                Select::make('lead_description_field_id')
                                    ->label('Сделка: описание приема')
                                    ->options(fn ($record = null): array => self::fieldOptions($record, 'leads', ['text', 'textarea']))
                                    ->searchable(),
                            ]),
                    ])
                    ->columnSpan(2),

                Section::make('Состояние')
                    ->extraAttributes(['class' => 'self-start h-fit'])
                    ->schema([
                        TextEntry::make('vetmanager_connection')
                            ->label('Webhook Vetmanager')
                            ->badge()
                            ->state(fn (?Setting $record): string => $record?->webhook_synced_at ? 'Подключен' : 'Не подключен')
                            ->color(fn (?Setting $record): string => $record?->webhook_synced_at ? 'success' : 'gray'),

                        TextEntry::make('webhook_synced_at')
                            ->label('Webhook обновлен')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('Еще не устанавливался'),

                        TextEntry::make('visits_count')
                            ->label('Получено посещений')
                            ->state(fn (?Setting $record): int => $record?->visits()->count() ?? 0),

                        TextEntry::make('entities')
                            ->label('Создаваемые сущности')
                            ->state('Контакт и сделка'),

                        TextEntry::make('pricing')
                            ->hiddenLabel()
                            ->html()
                            ->state(fn ($model) => PricingView::sidebarHtml($model::$cost)),
                    ])
                    ->compact()
                    ->columnSpan(1),
            ])
            ->columns(3);
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
