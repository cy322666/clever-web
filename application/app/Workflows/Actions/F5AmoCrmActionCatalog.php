<?php

declare(strict_types=1);

namespace App\Workflows\Actions;

use App\Models\amoCRM\Field as AmoCrmField;
use App\Models\amoCRM\Staff as AmoCrmStaff;
use App\Models\amoCRM\Status as AmoCrmStatus;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Leek\FilamentWorkflows\Concerns\WorkflowAction;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use Leek\FilamentWorkflows\Forms\Components\VariableTextInput;
use Leek\FilamentWorkflows\Forms\Components\VariableTextarea;

class F5AmoCrmActionCatalog
{
    /**
     * @return array<int, class-string>
     */
    public static function classes(): array
    {
        return [
            AmoCrmCreateLeadAction::class,
            AmoCrmCreateContactAction::class,
            AmoCrmCreateCompanyAction::class,
            AmoCrmCopyLeadAction::class,
            AmoCrmUpdateFieldsAction::class,
            AmoCrmCreateTaskAction::class,
            AmoCrmAddNoteAction::class,
            AmoCrmChangeTagsAction::class,
            AmoCrmChangeLeadStatusAction::class,
            AmoCrmStartSalesBotAction::class,
            AmoCrmStopSalesBotAction::class,
            AmoCrmManageSubscriptionAction::class,
            AmoCrmUpdateTaskAction::class,
            AmoCrmCancelDelayedAction::class,
            AmoCrmNormalizeContactDataAction::class,
            AmoCrmAddProductsAction::class,
            AmoCrmRemoveProductsAction::class,
            AmoCrmFindEntityAction::class,
            AmoCrmLinkEntityAction::class,
            AmoCrmUnlinkEntityAction::class,
        ];
    }
}

abstract class F5AmoCrmAction
{
    use WorkflowAction;

    public static function workflowCategory(): string
    {
        return 'amoCRM';
    }

    public static function workflowColor(): string
    {
        return '#0F766E';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-bolt';
    }

    /**
     * @return array<Component>
     */
    public static function workflowConfigSchema(?string $modelClass = null): array
    {
        return static::schema();
    }

    /**
     * @return array<string, mixed>
     */
    public static function workflowDefaultConfig(): array
    {
        return array_merge([
            'action' => static::workflowType(),
            'delay' => [
                'mode' => 'immediate',
            ],
        ], static::defaults());
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function getConfiguredDescription(array $config): string
    {
        $target = $config['target_entity'] ?? null;

        if (is_string($target) && $target !== '') {
            return static::workflowDescription() . ' <strong>' . e(static::entityLabel($target)) . '</strong>';
        }

        return static::workflowDescription();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function handle(array $config, ?WorkflowContext $context = null): array
    {
        return [
            'success' => false,
            'error' => 'Исполнитель amoCRM-действий ещё не подключён. Карточка и настройки сохранены, API-вызов будет добавлен следующим слоем.',
            'output' => [
                'action' => static::workflowType(),
                'config' => $config,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateWorkflowConfig(array $config): array
    {
        return [
            'valid' => true,
            'errors' => [],
        ];
    }

    /**
     * @return array<Component>
     */
    protected static function schema(): array
    {
        return [
            static::delaySection(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function defaults(): array
    {
        return [];
    }

    protected static function entityLabel(string $entity): string
    {
        return [
            'lead' => 'Сделка',
            'contact' => 'Контакт',
            'company' => 'Компания',
            'customer' => 'Покупатель',
            'task' => 'Задача',
        ][$entity] ?? Str::headline($entity);
    }

    protected static function entitySelect(array $entities = ['lead', 'contact', 'company']): Select
    {
        return Select::make('target_entity')
            ->label('Применить к')
            ->options(
                collect($entities)->mapWithKeys(fn(string $entity): array => [$entity => static::entityLabel($entity)]
                )->all()
            )
            ->default($entities[0] ?? 'lead')
            ->required()
            ->live()
            ->native(false);
    }

    protected static function delaySection(): Section
    {
        return Section::make('Запуск')
            ->description('Можно оставить выполнение сразу или описать отложенный запуск для будущего исполнителя.')
            ->collapsed()
            ->schema([
                Select::make('delay.mode')
                    ->label('Когда выполнить')
                    ->options([
                        'immediate' => 'Сразу',
                        'after_seconds' => 'Через N секунд',
                        'date_field' => 'В дату из поля',
                    ])
                    ->default('immediate')
                    ->afterStateHydrated(function (?string $state, Set $set, Get $get): void {
                        if ($state !== 'after_minutes') {
                            return;
                        }

                        $minutes = (int)($get('delay.minutes') ?: 0);

                        $set('delay.mode', 'after_seconds');
                        $set('delay.seconds', max(1, $minutes * 60));
                    })
                    ->live()
                    ->native(false),

                TextInput::make('delay.seconds')
                    ->label('Задержка, секунд')
                    ->numeric()
                    ->minValue(1)
                    ->visible(fn(Get $get): bool => $get('delay.mode') === 'after_seconds'),

                VariableTextInput::make('delay.date_field')
                    ->label('Поле/переменная с датой')
                    ->placeholder('{{trigger.payload.leads.add.0.created_at}}')
                    ->visible(fn(Get $get): bool => $get('delay.mode') === 'date_field'),
            ]);
    }

    protected static function fieldMappingsSection(string $label = 'Поля', string $entity = 'lead'): Section
    {
        return Section::make($label)
            ->description('Выберите поля из синхронизированного списка amoCRM и задайте значения с переменными.')
            ->schema([
                Repeater::make('fields')
                    ->label('')
                    ->schema([
                        Select::make('field')
                            ->label('Поле')
                            ->options(fn(): array => static::amoFieldOptions($entity))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required(),

                        VariableTextInput::make('value')
                            ->label('Значение')
                            ->placeholder('{{trigger.payload...}}')
                            ->required(),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel('Добавить поле')
                    ->collapsible(),
            ]);
    }

    protected static function amoFieldMappingsSection(string $label = 'Поля'): Section
    {
        return Section::make($label)
            ->description('Выберите поля из синхронизированного списка amoCRM и задайте значения с переменными.')
            ->schema([
                Repeater::make('fields')
                    ->label('')
                    ->schema([
                        Select::make('field')
                            ->label('Поле')
                            ->options(fn(Get $get): array => static::amoFieldOptions(
                                (string)($get('../../target_entity') ?: $get('../target_entity') ?: $get(
                                    'target_entity'
                                ) ?: 'lead'),
                            ))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required(),

                        VariableTextInput::make('value')
                            ->label('Значение')
                            ->placeholder('{{trigger.payload...}}')
                            ->required(),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel('Добавить поле')
                    ->collapsible(),
            ]);
    }

    /**
     * @return array<int|string, string>
     */
    protected static function amoFieldOptions(string $entity): array
    {
        $userId = Auth::id();

        if (!$userId) {
            return [];
        }

        $entityType = static::amoFieldEntityType($entity);
        $query = AmoCrmField::query()
            ->where('user_id', $userId)
            ->where('active', true);

        if ($entityType !== null) {
            $query->where('entity_type', $entityType);
        }

        return $query
            ->whereNotNull('field_id')
            ->orderBy('sort')
            ->orderBy('name')
            ->get(['field_id', 'name', 'type', 'code'])
            ->mapWithKeys(static function (AmoCrmField $field): array {
                $labelParts = array_filter([
                    (string)($field->name ?: $field->code ?: $field->field_id),
                    $field->type ? '[' . $field->type . ']' : null,
                    'ID ' . $field->field_id,
                ]);

                return [(string)$field->field_id => implode(' ', $labelParts)];
            })
            ->all();
    }

    protected static function amoFieldEntityType(string $entity): ?string
    {
        return match ($entity) {
            'lead' => 'leads',
            'contact' => 'contacts',
            'company' => 'companies',
            default => null,
        };
    }

    protected static function commonCreateFields(string $entity): array
    {
        return [
            VariableTextInput::make('name')
                ->label($entity === 'lead' ? 'Название сделки' : 'Название')
                ->placeholder($entity === 'contact' ? 'Имя контакта' : 'Название')
                ->required(),

            VariableTextInput::make('tags')
                ->label('Теги')
                ->placeholder('Новый, VIP, {{trigger.tag}}')
                ->helperText('Несколько тегов можно указывать через запятую.'),

            Select::make('responsible_user_id')
                ->label('Ответственный')
                ->options(fn(): array => static::amoResponsibleOptions())
                ->searchable()
                ->preload()
                ->native(false)
                ->placeholder('Выберите ответственного'),
        ];
    }

    protected static function pipelineFields(): array
    {
        return [
            Select::make('pipeline_id')
                ->label('Воронка')
                ->options(fn(): array => static::amoPipelineOptions())
                ->searchable()
                ->preload()
                ->live()
                ->native(false)
                ->afterStateUpdated(fn(Set $set): null => $set('status_id', null))
                ->placeholder('Выберите воронку'),

            Select::make('status_id')
                ->label('Статус')
                ->options(fn(Get $get): array => static::amoStatusOptions($get('pipeline_id')))
                ->searchable()
                ->preload()
                ->native(false)
                ->disabled(fn(Get $get): bool => blank($get('pipeline_id')))
                ->placeholder('Сначала выберите воронку'),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    protected static function amoResponsibleOptions(): array
    {
        return AmoCrmStaff::query()
            ->where('user_id', Auth::id())
            ->where('active', true)
            ->whereNotNull('staff_id')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn(AmoCrmStaff $staff): array => [
                (string)$staff->staff_id => trim(implode(' ', array_filter([
                    (string)($staff->name ?: 'Сотрудник ' . $staff->staff_id),
                    $staff->login ? '(' . $staff->login . ')' : null,
                ]))),
            ])
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    protected static function amoPipelineOptions(): array
    {
        return AmoCrmStatus::query()
            ->where('user_id', Auth::id())
            ->where('active', true)
            ->where('is_archive', false)
            ->whereNotNull('pipeline_id')
            ->orderBy('pipeline_name')
            ->get()
            ->unique('pipeline_id')
            ->mapWithKeys(fn(AmoCrmStatus $status): array => [
                (string)$status->pipeline_id => (string)($status->pipeline_name ?: 'Воронка ' . $status->pipeline_id),
            ])
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    protected static function amoStatusOptions(mixed $pipelineId): array
    {
        if (blank($pipelineId)) {
            return [];
        }

        return AmoCrmStatus::query()
            ->where('user_id', Auth::id())
            ->where('active', true)
            ->where('is_archive', false)
            ->where('pipeline_id', $pipelineId)
            ->where('name', '!=', 'Неразобранное')
            ->orderBy('sort')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn(AmoCrmStatus $status): array => [
                (string)$status->status_id => (string)$status->name,
            ])
            ->all();
    }
}

class AmoCrmCreateLeadAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_create_lead';
    }

    public static function workflowName(): string
    {
        return 'Создать сделку';
    }

    public static function workflowDescription(): string
    {
        return 'Создаёт новую сделку в amoCRM.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-currency-dollar';
    }

    public static function workflowColor(): string
    {
        return '#16A34A';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Сделка')->schema(array_merge(static::commonCreateFields('lead'), static::pipelineFields())),
            static::fieldMappingsSection('Поля сделки', 'lead'),
            static::delaySection(),
        ];
    }
}

class AmoCrmCreateContactAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_create_contact';
    }

    public static function workflowName(): string
    {
        return 'Создать контакт';
    }

    public static function workflowDescription(): string
    {
        return 'Создаёт контакт и при необходимости связывает его с сущностью.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-user-plus';
    }

    public static function workflowColor(): string
    {
        return '#16A34A';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Контакт')->schema(array_merge([
                static::entitySelect(['lead', 'company']),
            ], static::commonCreateFields('contact'))),
            static::fieldMappingsSection('Поля контакта', 'contact'),
            static::delaySection(),
        ];
    }
}

class AmoCrmCreateCompanyAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_create_company';
    }

    public static function workflowName(): string
    {
        return 'Создать компанию';
    }

    public static function workflowDescription(): string
    {
        return 'Создаёт компанию и связывает её со сделкой или контактом.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-building-office-2';
    }

    public static function workflowColor(): string
    {
        return '#16A34A';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Компания')->schema(array_merge([
                static::entitySelect(['lead', 'contact']),
            ], static::commonCreateFields('company'))),
            static::fieldMappingsSection('Поля компании', 'company'),
            static::delaySection(),
        ];
    }
}

class AmoCrmCopyLeadAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_copy_lead';
    }

    public static function workflowName(): string
    {
        return 'Копировать сделку';
    }

    public static function workflowDescription(): string
    {
        return 'Копирует текущую сделку в новую с выбранными параметрами.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-document-duplicate';
    }

    public static function workflowColor(): string
    {
        return '#0EA5E9';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Новая сделка')->schema(array_merge([
                VariableTextInput::make('name')->label('Название новой сделки')->placeholder('{{lead.name}} (копия)'),
                VariableTextInput::make('tags')->label('Теги')->placeholder('Копия, {{trigger.tag}}'),
                VariableTextInput::make('responsible_user_id')->label('Ответственный')->placeholder(
                    'ID пользователя или переменная'
                ),
            ], static::pipelineFields())),
            static::delaySection(),
        ];
    }
}

class AmoCrmUpdateFieldsAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_update_fields';
    }

    public static function workflowName(): string
    {
        return 'Сменить значение поля';
    }

    public static function workflowDescription(): string
    {
        return 'Меняет одно или несколько полей сделки, контакта или компании.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-pencil-square';
    }

    public static function workflowColor(): string
    {
        return '#2563EB';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Сущность')->schema([
                static::entitySelect(['lead', 'contact', 'company', 'customer'])
                    ->afterStateUpdated(fn(Set $set): mixed => $set('fields', [])),
            ]),
            static::amoFieldMappingsSection('Изменяемые поля'),
            static::delaySection(),
        ];
    }
}

class AmoCrmCreateTaskAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_create_task';
    }

    public static function workflowName(): string
    {
        return 'Поставить задачу';
    }

    public static function workflowDescription(): string
    {
        return 'Создаёт задачу по сделке, контакту, компании или покупателю.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-clipboard-document-check';
    }

    public static function workflowColor(): string
    {
        return '#F59E0B';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Задача')->schema([
                static::entitySelect(['lead', 'contact', 'company', 'customer']),
                VariableTextInput::make('responsible_user_id')->label('Ответственный')->placeholder(
                    'ID пользователя или переменная'
                ),
                VariableTextInput::make('task_type_id')->label('Тип задачи')->placeholder('ID типа задачи'),
                VariableTextInput::make('complete_till')->label('Срок выполнения')->placeholder(
                    '+1 hour / дата / переменная'
                ),
                VariableTextarea::make('text')->label('Текст задачи')->required(),
                Repeater::make('results')
                    ->label('Результаты выполнения')
                    ->schema([
                        TextInput::make('label')->label('Вариант результата'),
                        VariableTextInput::make('workflow_id')->label('Процесс после выбора')->placeholder(
                            'ID процесса'
                        ),
                    ])
                    ->defaultItems(0)
                    ->columns(2)
                    ->collapsible(),
            ]),
            static::delaySection(),
        ];
    }
}

class AmoCrmAddNoteAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_add_note';
    }

    public static function workflowName(): string
    {
        return 'Добавить примечание';
    }

    public static function workflowDescription(): string
    {
        return 'Добавляет примечание в ленту сущности amoCRM.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-chat-bubble-left-ellipsis';
    }

    public static function workflowColor(): string
    {
        return '#CA8A04';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Примечание')->schema([
                static::entitySelect(['lead', 'contact', 'company', 'customer']),
                Toggle::make('is_system')->label('Системное примечание')->default(false),
                VariableTextarea::make('text')->label('Текст примечания')->required(),
            ]),
            static::delaySection(),
        ];
    }
}

class AmoCrmChangeTagsAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_change_tags';
    }

    public static function workflowName(): string
    {
        return 'Сменить теги';
    }

    public static function workflowDescription(): string
    {
        return 'Добавляет или удаляет теги у сущности amoCRM.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-tag';
    }

    public static function workflowColor(): string
    {
        return '#7C3AED';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Теги')->schema([
                static::entitySelect(['lead', 'contact', 'company', 'customer']),
                VariableTextInput::make('tags_to_add')->label('Добавить теги')->placeholder('VIP, Новый'),
                VariableTextInput::make('tags_to_remove')->label('Удалить теги')->placeholder('Старый, Ошибка'),
                Toggle::make('remove_all')->label('Удалить все теги')->default(false),
            ]),
            static::delaySection(),
        ];
    }
}

class AmoCrmChangeLeadStatusAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_change_lead_status';
    }

    public static function workflowName(): string
    {
        return 'Сменить статус сделки';
    }

    public static function workflowDescription(): string
    {
        return 'Переносит сделку в выбранную воронку и этап.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-arrows-right-left';
    }

    public static function workflowColor(): string
    {
        return '#EA580C';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Статус сделки')->schema(static::pipelineFields()),
            static::delaySection(),
        ];
    }
}

class AmoCrmStartSalesBotAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_start_salesbot';
    }

    public static function workflowName(): string
    {
        return 'Запустить SalesBot';
    }

    public static function workflowDescription(): string
    {
        return 'Запускает выбранного SalesBot в amoCRM.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-play-circle';
    }

    public static function workflowColor(): string
    {
        return '#0891B2';
    }

    protected static function schema(): array
    {
        return [
            Section::make('SalesBot')->schema([
                VariableTextInput::make('bot_id')->label('ID бота')->required(),
            ]),
            static::delaySection(),
        ];
    }
}

class AmoCrmStopSalesBotAction extends AmoCrmStartSalesBotAction
{
    public static function workflowType(): string
    {
        return 'amocrm_stop_salesbot';
    }

    public static function workflowName(): string
    {
        return 'Остановить SalesBot';
    }

    public static function workflowDescription(): string
    {
        return 'Останавливает выбранного SalesBot в amoCRM.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-stop-circle';
    }

    public static function workflowColor(): string
    {
        return '#DC2626';
    }
}

class AmoCrmManageSubscriptionAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_manage_subscription';
    }

    public static function workflowName(): string
    {
        return 'Подписать / отписать от сделки';
    }

    public static function workflowDescription(): string
    {
        return 'Управляет подпиской пользователей на сделку или чат.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-bell-alert';
    }

    public static function workflowColor(): string
    {
        return '#7C3AED';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Подписка')->schema([
                Select::make('mode')->label('Действие')->options(
                    ['subscribe' => 'Подписать', 'unsubscribe' => 'Отписать']
                )->default('subscribe')->required()->native(false),
                Select::make('event')->label('Событие')->options(['chat' => 'Чат'])->default('chat')->required(
                )->native(false),
                VariableTextInput::make('user_ids')->label('Пользователи')->placeholder(
                    'ID через запятую или переменная'
                ),
            ]),
            static::delaySection(),
        ];
    }
}

class AmoCrmUpdateTaskAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_update_task';
    }

    public static function workflowName(): string
    {
        return 'Изменить задачу';
    }

    public static function workflowDescription(): string
    {
        return 'Меняет задачи по сущности: текст, статус, ответственного или тип.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-clipboard-document-list';
    }

    public static function workflowColor(): string
    {
        return '#F59E0B';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Поиск задач')->schema([
                static::entitySelect(['lead', 'contact', 'company', 'customer']),
                VariableTextInput::make('task_type_id')->label('Найти задачи по типу')->placeholder('ID типа задачи'),
                Select::make('scope')->label('Применить для')->options(['all' => 'Все найденные', 'last' => 'Последняя']
                )->default('all')->native(false),
            ]),
            Section::make('Изменения')->schema([
                VariableTextInput::make('responsible_user_id')->label('Новый ответственный'),
                VariableTextInput::make('new_task_type_id')->label('Новый тип задачи'),
                Select::make('status')->label('Статус задачи')->options(['open' => 'Открыта', 'closed' => 'Закрыта']
                )->native(false),
                VariableTextarea::make('text')->label('Новый текст задачи'),
            ]),
            static::delaySection(),
        ];
    }
}

class AmoCrmCancelDelayedAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_cancel_delayed_action';
    }

    public static function workflowName(): string
    {
        return 'Удалить отложенное действие';
    }

    public static function workflowDescription(): string
    {
        return 'Отменяет запланированные действия для текущей сущности.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-no-symbol';
    }

    public static function workflowColor(): string
    {
        return '#DC2626';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Что отменить')->schema([
                VariableTextInput::make('workflow_id')->label('ID процесса')->placeholder(
                    'Оставить пустым для текущего'
                ),
                VariableTextInput::make('action_id')->label('ID действия')->placeholder('Опционально'),
            ]),
        ];
    }
}

class AmoCrmSetGlobalVariableAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_set_global_variable';
    }

    public static function workflowName(): string
    {
        return 'Изменить глобальную переменную';
    }

    public static function workflowDescription(): string
    {
        return 'Задаёт, дополняет или очищает глобальную переменную процесса.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-variable';
    }

    public static function workflowColor(): string
    {
        return '#6366F1';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Переменная')->schema([
                TextInput::make('key')->label('Ключ')->required(),
                Select::make('mode')->label('Операция')->options(
                    ['set' => 'Задать', 'append' => 'Дополнить', 'clear' => 'Очистить']
                )->default('set')->native(false),
                VariableTextarea::make('value')->label('Значение')->visible(
                    fn(Get $get): bool => $get('mode') !== 'clear'
                ),
            ]),
        ];
    }
}

class AmoCrmNormalizeContactDataAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_normalize_contact_data';
    }

    public static function workflowName(): string
    {
        return 'Нормализовать телефон и e-mail';
    }

    public static function workflowDescription(): string
    {
        return 'Приводит телефоны и e-mail к единому формату.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-sparkles';
    }

    public static function workflowColor(): string
    {
        return '#0D9488';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Нормализация')->schema([
                static::entitySelect(['contact', 'company']),
                Toggle::make('normalize_phone')->label('Телефон')->default(true),
                Toggle::make('normalize_email')->label('E-mail')->default(true),
                Toggle::make('remove_invalid')->label('Удалять некорректные значения')->default(false),
            ]),
            static::delaySection(),
        ];
    }
}

class AmoCrmAddProductsAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_add_products';
    }

    public static function workflowName(): string
    {
        return 'Добавить товары';
    }

    public static function workflowDescription(): string
    {
        return 'Добавляет товары к сделке.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-shopping-cart';
    }

    public static function workflowColor(): string
    {
        return '#16A34A';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Товары')->schema([
                VariableTextInput::make('catalog_id')->label('Каталог товаров')->placeholder('ID каталога'),
                Repeater::make('products')
                    ->label('Список товаров')
                    ->schema([
                        VariableTextInput::make('product_id')->label('ID товара')->required(),
                        VariableTextInput::make('quantity')->label('Количество')->default('1'),
                        VariableTextInput::make('price_id')->label('Тип цены'),
                    ])
                    ->columns(3)
                    ->defaultItems(1)
                    ->collapsible(),
            ]),
            static::delaySection(),
        ];
    }
}

class AmoCrmRemoveProductsAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_remove_products';
    }

    public static function workflowName(): string
    {
        return 'Удалить товары';
    }

    public static function workflowDescription(): string
    {
        return 'Удаляет товары из выбранного списка в сделке.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-trash';
    }

    public static function workflowColor(): string
    {
        return '#DC2626';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Товары')->schema([
                VariableTextInput::make('catalog_id')->label('Каталог товаров')->placeholder('ID каталога')->required(),
                Toggle::make('remove_all')->label('Удалить все товары списка')->default(true),
            ]),
            static::delaySection(),
        ];
    }
}

class AmoCrmFindEntityAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_find_entity';
    }

    public static function workflowName(): string
    {
        return 'Найти сущность';
    }

    public static function workflowDescription(): string
    {
        return 'Ищет сделку, контакт или компанию по заданным условиям.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-magnifying-glass';
    }

    public static function workflowColor(): string
    {
        return '#2563EB';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Поиск')->schema([
                static::entitySelect(['lead', 'contact', 'company', 'customer'])
                    ->afterStateUpdated(fn(Set $set): mixed => $set('conditions', [])),
                Repeater::make('conditions')
                    ->label('Условия поиска')
                    ->schema([
                        Select::make('field')
                            ->label('Поле')
                            ->options(fn(Get $get): array => static::amoFieldOptions(
                                (string)($get('../../target_entity') ?: $get('../target_entity') ?: $get(
                                    'target_entity'
                                ) ?: 'lead'),
                            ))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required(),
                        Select::make('operator')->label('Сравнение')->options([
                            'equals' => 'Равно',
                            'contains' => 'Содержит',
                            'not_empty' => 'Заполнено',
                            'empty' => 'Пусто',
                        ])->default('equals')->native(false),
                        VariableTextInput::make('value')->label('Значение'),
                    ])
                    ->columns(3)
                    ->defaultItems(1)
                    ->collapsible(),
                TextInput::make('context_key')->label('Сохранить результат в')->default('found_entity'),
            ]),
        ];
    }
}

class AmoCrmLinkEntityAction extends F5AmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_link_entity';
    }

    public static function workflowName(): string
    {
        return 'Прикрепить сущность';
    }

    public static function workflowDescription(): string
    {
        return 'Связывает сущности amoCRM между собой.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-link';
    }

    public static function workflowColor(): string
    {
        return '#0EA5E9';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Связь')->schema([
                static::entitySelect(['lead', 'contact', 'company', 'customer']),
                Select::make('linked_entity')->label('Что прикрепить')->options([
                    'lead' => 'Сделку',
                    'contact' => 'Контакт',
                    'company' => 'Компанию',
                    'customer' => 'Покупателя',
                ])->required()->native(false),
                VariableTextInput::make('linked_entity_id')->label('ID прикрепляемой сущности')->required(),
            ]),
            static::delaySection(),
        ];
    }
}

class AmoCrmUnlinkEntityAction extends AmoCrmLinkEntityAction
{
    public static function workflowType(): string
    {
        return 'amocrm_unlink_entity';
    }

    public static function workflowName(): string
    {
        return 'Открепить сущность';
    }

    public static function workflowDescription(): string
    {
        return 'Удаляет связь между сущностями amoCRM.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-link-slash';
    }

    public static function workflowColor(): string
    {
        return '#DC2626';
    }
}
