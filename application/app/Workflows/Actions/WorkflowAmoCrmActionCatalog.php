<?php

declare(strict_types=1);

namespace App\Workflows\Actions;

use App\Models\amoCRM\Field as AmoCrmField;
use App\Models\amoCRM\Staff as AmoCrmStaff;
use App\Models\amoCRM\Status as AmoCrmStatus;
use App\Models\Integrations\Distribution\Setting as DistributionSetting;
use App\Forms\Components\WorkflowValueInput;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Services\Workflows\WorkflowAmoCrmSalesBotService;
use App\Services\Workflows\WorkflowEntityQuery;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Leek\FilamentWorkflows\Concerns\WorkflowAction;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use Leek\FilamentWorkflows\Forms\Components\VariableTextInput;
use Leek\FilamentWorkflows\Forms\Components\VariableTextarea;

class WorkflowAmoCrmActionCatalog
{
    /**
     * @var array<string, string|null>
     */
    private static array $pipelineNameCache = [];

    /**
     * @var array<string, string|null>
     */
    private static array $statusNameCache = [];

    /**
     * @var array<string, string|null>
     */
    private static array $distributionQueueNameCache = [];

    /**
     * @return array<int, class-string>
     */
    public static function classes(): array
    {
        return [
            AmoCrmCreateLeadAction::class,
            AmoCrmReadAction::class,
            AmoCrmGetContactAction::class,
            AmoCrmCreateContactAction::class,
            AmoCrmCreateCompanyAction::class,
            AmoCrmCopyLeadAction::class,
            AmoCrmUpdateLeadFieldsAction::class,
            AmoCrmUpdateContactFieldsAction::class,
            AmoCrmUpdateCompanyFieldsAction::class,
            AmoCrmCreateTaskAction::class,
            AmoCrmAddNoteAction::class,
            AmoCrmChangeTagsAction::class,
            AmoCrmChangeLeadStatusAction::class,
            AmoCrmDistributionQueueAction::class,
            AmoCrmStartSalesBotAction::class,
            AmoCrmStopSalesBotAction::class,
            AmoCrmManageSubscriptionAction::class,
            AmoCrmUpdateTaskAction::class,
            AmoCrmCancelDelayedAction::class,
            AmoCrmNormalizeContactDataAction::class,
            AmoCrmAddProductsAction::class,
            AmoCrmRemoveProductsAction::class,
            AmoCrmFindEntityAction::class,
            AmoCrmQueryLeadsAction::class,
            AmoCrmContactLeadsAction::class,
            AmoCrmLinkEntityAction::class,
            AmoCrmUnlinkEntityAction::class,
        ];
    }

    /**
     * These cards may stay registered so old definitions can still be opened,
     * but they must not be offered for new scenarios until an executor exists.
     *
     * @return array<int, string>
     */
    public static function unsupportedWorkflowTypes(): array
    {
        return [
            'run_workflow',
            'workflow_call',
            'amocrm_stop_salesbot',
            'amocrm_manage_subscription',
            'amocrm_update_task',
            'amocrm_cancel_delayed_action',
            'amocrm_normalize_contact_data',
            'amocrm_add_products',
            'amocrm_remove_products',
        ];
    }

    public static function resolvePipelineName(mixed $pipelineId): ?string
    {
        if (!is_numeric($pipelineId)) return null;
        if (blank($pipelineId)) {
            return null;
        }

        $cacheKey = (string)Auth::id() . ':' . (string)$pipelineId;

        if (array_key_exists($cacheKey, self::$pipelineNameCache)) {
            return self::$pipelineNameCache[$cacheKey];
        }

        $status = AmoCrmStatus::query()
            ->where('user_id', Auth::id())
            ->where('active', true)
            ->where('is_archive', false)
            ->where('pipeline_id', $pipelineId)
            ->whereNotNull('pipeline_name')
            ->orderBy('pipeline_name')
            ->first(['pipeline_name']);

        self::$pipelineNameCache[$cacheKey] = filled($status?->pipeline_name) ? (string)$status->pipeline_name : null;

        return self::$pipelineNameCache[$cacheKey];
    }

    public static function resolveStatusName(mixed $statusId, mixed $pipelineId = null): ?string
    {
        if (!is_numeric($statusId)) return null;
        if (!is_numeric($pipelineId)) $pipelineId = null;
        if (blank($statusId)) {
            return null;
        }

        $cacheKey = (string)Auth::id() . ':' . (string)$pipelineId . ':' . (string)$statusId;

        if (array_key_exists($cacheKey, self::$statusNameCache)) {
            return self::$statusNameCache[$cacheKey];
        }

        $query = AmoCrmStatus::query()
            ->where('user_id', Auth::id())
            ->where('active', true)
            ->where('is_archive', false)
            ->where('status_id', $statusId)
            ->where('name', '!=', 'Неразобранное');

        if (filled($pipelineId)) {
            $query->where('pipeline_id', $pipelineId);
        }

        $status = $query
            ->orderBy('sort')
            ->orderBy('name')
            ->first(['name']);

        self::$statusNameCache[$cacheKey] = filled($status?->name) ? (string)$status->name : null;

        return self::$statusNameCache[$cacheKey];
    }

    public static function resolveDistributionQueueName(mixed $queueUuid): ?string
    {
        if (blank($queueUuid)) {
            return null;
        }

        $cacheKey = (string)Auth::id() . ':' . (string)$queueUuid;

        if (array_key_exists($cacheKey, self::$distributionQueueNameCache)) {
            return self::$distributionQueueNameCache[$cacheKey];
        }

        self::$distributionQueueNameCache[$cacheKey] = self::distributionQueueOptions()[(string)$queueUuid] ?? null;

        return self::$distributionQueueNameCache[$cacheKey];
    }

    /**
     * @return array<string, string>
     */
    public static function distributionQueueOptions(): array
    {
        $userId = Auth::id();

        if (!$userId) {
            return [];
        }

        $setting = DistributionSetting::query()
            ->where('user_id', $userId)
            ->latest('id')
            ->first(['settings']);

        $settings = json_decode($setting?->settings ?? '[]', true);

        if (!is_array($settings)) {
            return [];
        }

        $options = [];

        foreach ($settings as $index => $queue) {
            if (!is_array($queue)) {
                continue;
            }

            $key = (string)($queue['queue_uuid'] ?? $index);
            $options[$key] = self::distributionQueueLabel($queue, (int)$index);
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $queue
     */
    private static function distributionQueueLabel(array $queue, int $index): string
    {
        $name = trim((string)($queue['name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        $strategy = match ((string)($queue['strategy'] ?? '')) {
            DistributionSetting::STRATEGY_ROTATION => 'по очереди',
            DistributionSetting::STRATEGY_RANDOM => 'вразброс',
            default => null,
        };

        return trim('Очередь #' . ($index + 1) . ($strategy ? ' · ' . $strategy : ''));
    }
}

abstract class WorkflowAmoCrmAction
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
            'entity_source' => 'context',
            'target_entity_id' => null,
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
        $description = static::workflowDescription();

        if (is_string($target) && $target !== '') {
            $description .= ' <strong>' . e(static::entityLabel($target)) . '</strong>';
        }

        $details = [];
        $queueName = WorkflowAmoCrmActionCatalog::resolveDistributionQueueName(
            $config['distribution_queue_uuid'] ?? $config['queue_uuid'] ?? null,
        );
        $pipelineName = WorkflowAmoCrmActionCatalog::resolvePipelineName($config['pipeline_id'] ?? null);
        $statusName = WorkflowAmoCrmActionCatalog::resolveStatusName(
            $config['status_id'] ?? null,
            $config['pipeline_id'] ?? null,
        );

        if ($queueName !== null) {
            $details[] = 'Очередь: <strong>' . e($queueName) . '</strong>';
        }

        if ($pipelineName !== null) {
            $details[] = 'Воронка: <strong>' . e($pipelineName) . '</strong>';
        }

        if ($statusName !== null) {
            $details[] = 'Статус: <strong>' . e($statusName) . '</strong>';
        }

        return $details === []
            ? $description
            : $description . ' · ' . implode(' · ', $details);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function handle(array $config, ?WorkflowContext $context = null): array
    {
        return app(WorkflowAmoCrmActionExecutor::class)->execute(static::workflowType(), $config, $context);
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
            'leads' => 'Сделка',
            'contacts' => 'Контакт',
            'companies' => 'Компания',
            'customers' => 'Покупатель',
            'tasks' => 'Задача',
        ][$entity] ?? Str::headline($entity);
    }

    protected static function entitySelect(array $entities = ['lead', 'contact', 'company']): WorkflowValueInput
    {
        return WorkflowValueInput::make('target_entity')
            ->label('Применить к')
            ->options(
                collect($entities)->mapWithKeys(fn(string $entity): array => [$entity => static::entityLabel($entity)]
                )->all()
            )
            ->default($entities[0] ?? 'lead')
            ->required()
            ->live();
    }

    /**
     * @return array<Component>
     */
    protected static function targetEntityFields(
        array $entities = ['lead', 'contact', 'company'],
        ?callable $afterEntityUpdated = null
    ): array {
        $defaultEntity = $entities[0] ?? 'lead';

        return [
            Hidden::make('__context_entity')->dehydrated(false),
            Hidden::make('__context_entity_id')->dehydrated(false),

            static::entityContextSummary($defaultEntity),

            static::entitySourceToggle(),

            static::entitySelect($entities)
                ->afterStateUpdated(
                    function (Set $set) use ($afterEntityUpdated): void {
                        $set('target_entity_id', null);

                        if ($afterEntityUpdated) {
                            $afterEntityUpdated($set);
                        }
                    }
                )
                ->visible(fn(Get $get): bool => ($get('entity_source') ?? 'context') === 'manual'),

            static::targetEntityIdInput($defaultEntity)
                ->required(fn(Get $get): bool => ($get('entity_source') ?? 'context') === 'manual')
                ->visible(fn(Get $get): bool => ($get('entity_source') ?? 'context') === 'manual'),
        ];
    }

    /**
     * @return array<Component>
     */
    protected static function fixedTargetEntityFields(string $entity): array
    {
        return [
            Hidden::make('target_entity')->default($entity),
            Hidden::make('target_entity_locked')->default(true),
            Hidden::make('__context_entity')->default($entity)->dehydrated(false),
            Hidden::make('__context_entity_id')->dehydrated(false),
            static::entityContextSummary($entity),
            static::entitySourceToggle(),
            static::targetEntityIdInput($entity)
                ->required(fn(Get $get): bool => ($get('entity_source') ?? 'context') === 'manual')
                ->visible(fn(Get $get): bool => ($get('entity_source') ?? 'context') === 'manual'),
        ];
    }

    protected static function entitySourceToggle(): ToggleButtons
    {
        return ToggleButtons::make('entity_source')
            ->label('Как выбрать сущность')
            ->options([
                'context' => 'Из контекста',
                'manual' => 'Указать вручную',
            ])
            ->default('context')
            ->inline()
            ->live();
    }

    protected static function entityContextSummary(string $defaultEntity = 'lead'): Placeholder
    {
        return Placeholder::make('__entity_context_summary')
            ->hiddenLabel()
            ->content(function (Get $get) use ($defaultEntity): HtmlString {
                $source = ($get('entity_source') ?? 'context') === 'manual' ? 'manual' : 'context';
                $entity = (string)($source === 'context'
                    ? ($get('__context_entity') ?: $get('target_entity') ?: $defaultEntity)
                    : ($get('target_entity') ?: $defaultEntity));
                $id = $source === 'context' ? $get('__context_entity_id') : $get('target_entity_id');
                $type = static::entityLabel($entity);
                $idText = filled($id) ? '#' . e((string)$id) : ($source === 'context' ? 'ID появится из входа ноды' : 'ID не указан');
                $badge = $source === 'context' ? 'Автоматически' : 'Вручную';

                return new HtmlString(
                    '<div class="workflow-entity-context">'
                    . '<span class="workflow-entity-context__badge">' . $badge . '</span>'
                    . '<strong>' . e($type) . '</strong>'
                    . '<span>' . $idText . '</span>'
                    . ($source === 'context' ? '<small>Контекст запуска или результат предыдущей ноды</small>' : '')
                    . '</div>'
                );
            });
    }

    protected static function targetEntityIdInput(string $entity = 'lead', ?string $label = null): WorkflowValueInput
    {
        return WorkflowValueInput::make('target_entity_id')
            ->label($label ?? 'ID сущности')
            ->default(null)
            ->placeholder('ID или переменная');
    }

    protected static function delaySection(): Component
    {
        return Hidden::make('__workflow_action_delay_placeholder')
            ->dehydrated(false);
    }

    protected static function fieldMappingsSection(string $label = 'Поля', string $entity = 'lead'): Section
    {
        return Section::make($label)
            ->compact()
            ->schema([
                Repeater::make('fields')
                    ->hiddenLabel()
                    ->columns(2)
                    ->schema([
                        Select::make('field')
                            ->label('Поле')
                            ->options(fn(): array => static::amoFieldOptions($entity))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required(),

                        WorkflowValueInput::make('value')
                            ->label('Значение')
                            ->placeholder('{{payload...}}')
                            ,
                    ])
                    ->reorderable(false)
                    ->defaultItems(0)
                    ->addActionLabel('Добавить поле')
                    ->addActionAlignment(Alignment::Start),
            ]);
    }

    protected static function amoFieldMappingsSection(string $label = 'Поля'): Section
    {
        return Section::make($label)
            ->compact()
            ->schema([
                Repeater::make('fields')
                    ->hiddenLabel()
                    ->columns(2)
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

                        WorkflowValueInput::make('value')
                            ->label('Значение')
                            ->placeholder('{{payload...}}')
                            ,
                    ])
                    ->reorderable(false)
                    ->defaultItems(0)
                    ->addActionLabel('Добавить поле')
                    ->addActionAlignment(Alignment::Start),
            ]);
    }

    /**
     * @return array<int|string, string>
     */
    protected static function amoFieldOptions(string $entity): array
    {
        $userId = Auth::id();
        $systemFields = static::amoSystemFieldOptions($entity);

        if (!$userId) {
            return $systemFields;
        }

        $entityType = static::amoFieldEntityType($entity);
        $query = AmoCrmField::query()
            ->where('user_id', $userId)
            ->where('active', true);

        if ($entityType !== null) {
            $query->where('entity_type', $entityType);
        }

        $customFields = $query
            ->whereNotNull('field_id')
            ->orderBy('sort')
            ->orderBy('name')
            ->get(['field_id', 'name', 'type', 'code'])
            ->mapWithKeys(static function (AmoCrmField $field): array {
                $labelParts = array_filter([
                    (string)($field->name ?: $field->code ?: $field->field_id),
                    'ID ' . $field->field_id,
                ]);

                return [(string)$field->field_id => implode(' ', $labelParts)];
            })
            ->all();

        return $systemFields + $customFields;
    }

    /**
     * @return array<string, string>
     */
    protected static function amoSystemFieldOptions(string $entity): array
    {
        return match ($entity) {
            'lead' => [
                'system:name' => 'Название сделки',
                'system:price' => 'Бюджет',
                'system:responsible_user_id' => 'Ответственный',
                'system:pipeline_id' => 'Воронка',
                'system:status_id' => 'Статус',
                'system:closed_at' => 'Дата закрытия',
                'system:loss_reason_id' => 'Причина отказа',
            ],
            'contact' => [
                'system:name' => 'Имя контакта',
                'system:first_name' => 'Имя',
                'system:last_name' => 'Фамилия',
                'system:responsible_user_id' => 'Ответственный',
            ],
            'company' => [
                'system:name' => 'Название компании',
                'system:responsible_user_id' => 'Ответственный',
            ],
            'customer' => [
                'system:name' => 'Название покупателя',
                'system:next_price' => 'Ожидаемая сумма',
                'system:responsible_user_id' => 'Ответственный',
            ],
            default => [],
        };
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
                ->columnSpanFull()
                ->required(),

            VariableTextInput::make('tags')
                ->label('Теги')
                ->placeholder('Новый, VIP, {{tag}}'),

            Select::make('responsible_user_id')
                ->label('Ответственный')
                ->options(fn(): array => static::amoResponsibleOptions())
                ->searchable()
                ->preload()
                ->native(false)
                ->placeholder('Выберите ответственного'),
        ];
    }

    protected static function pipelineFields(bool $required = false): array
    {
        return [
            WorkflowValueInput::make('pipeline_id')
                ->label('Воронка')
                ->options(fn(): array => static::amoPipelineOptions())
                ->live()
                ->required($required)
                ->afterStateUpdated(fn(Set $set): null => $set('status_id', null))
                ->placeholder('Выберите воронку'),

            WorkflowValueInput::make('status_id')
                ->label('Статус')
                ->options(fn(Get $get): array => static::amoStatusOptions($get('pipeline_id')))
                ->required($required)
                ->placeholder(fn(Get $get): string => is_numeric($get('pipeline_id')) ? 'Выберите статус' : 'ID статуса или выражение'),
        ];
    }

    protected static function bodyMode(): ToggleButtons
    {
        return ToggleButtons::make('body_mode')->hiddenLabel()
            ->options(['fields' => 'Поля', 'json' => 'JSON'])->default('fields')->inline()->live();
    }

    protected static function standardUpdateFields(string $entity): array
    {
        $fields = [];
        foreach (static::amoSystemFieldOptions($entity) as $key => $label) {
            $name = substr($key, 7);
            $input = WorkflowValueInput::make('standard_fields.'.$name)->label($label)->placeholder('Не изменять');
            if ($name === 'responsible_user_id') $input->options(fn () => static::amoResponsibleOptions());
            if ($name === 'pipeline_id') $input->options(fn () => static::amoPipelineOptions())->live();
            if ($name === 'status_id') $input->options(fn (Get $get) => static::amoStatusOptions($get('standard_fields.pipeline_id')));
            $fields[] = $input->visible(fn (Get $get) => $get('body_mode') !== 'json');
        }
        return $fields;
    }

    protected static function jsonBody(string $placeholder = '{"name": "{{ $json.name }}"}'): WorkflowValueInput
    {
        return WorkflowValueInput::make('json_body')->label('JSON-тело')->multiline(8)
            ->placeholder($placeholder)->extraInputAttributes(['class' => 'workflow-json-input'])
            ->visible(fn(Get $get): bool => $get('body_mode') === 'json')->required();
    }

    /**
     * @return array<string, string>
     */
    protected static function distributionQueueOptions(): array
    {
        return WorkflowAmoCrmActionCatalog::distributionQueueOptions();
    }

    protected static function createLeadSection(): Section
    {
        return Section::make('Основное')
            ->compact()
            ->columns(1)
            ->schema([
                WorkflowValueInput::make('name')
                    ->label('Название сделки')
                    ->placeholder('Название')
                    ->columnSpanFull()
                    ->required(),

                WorkflowValueInput::make('price')->label('Бюджет')->placeholder('0'),

                Grid::make(1)
                    ->columnSpanFull()
                    ->schema(static::pipelineFields()),

                WorkflowValueInput::make('responsible_user_id')
                    ->label('Ответственный')
                    ->options(fn(): array => static::amoResponsibleOptions())
                    ->placeholder('Выберите ответственного'),

                WorkflowValueInput::make('tags')
                    ->label('Теги')
                    ->suggestions(fn () => \App\Services\Workflows\WorkflowNodeReferences::options('tags:leads'))
                    ->placeholder('Новый, VIP, {{tag}}'),
            ]);
    }

    protected static function createLinkedEntitySection(string $label, string $entity, array $linkEntities): Section
    {
        return Section::make($label)
            ->compact()
            ->schema([
                Grid::make(2)->schema([
                    VariableTextInput::make('name')
                        ->label('Название')
                        ->placeholder($entity === 'contact' ? 'Имя контакта' : 'Название')
                        ->required(),

                    Select::make('responsible_user_id')
                        ->label('Ответственный')
                        ->options(fn(): array => static::amoResponsibleOptions())
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->placeholder('Выберите ответственного'),

                    VariableTextInput::make('tags')
                        ->label('Теги')
                        ->placeholder('Новый, VIP, {{tag}}')
                        ->columnSpanFull(),
                ]),

                Section::make('Связать с сущностью')
                    ->description('Новая сущность будет сразу прикреплена к выбранной сущности amoCRM.')
                    ->compact()
                    ->columns(2)
                    ->schema(static::targetEntityFields($linkEntities))
                    ->extraAttributes(['class' => 'workflow-entity-link-section']),
            ])
            ->extraAttributes(['class' => 'workflow-entity-create-section']);
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
                (string)$staff->staff_id => (string)($staff->name ?: 'Сотрудник ' . $staff->staff_id),
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
        if (!is_numeric($pipelineId)) {
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

    /**
     * @return array<int|string, string>
     */
    protected static function workflowOptions(): array
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
        $modelClass = config('filament-workflows.models.workflow', \Leek\FilamentWorkflows\Models\Workflow::class);

        return $modelClass::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(static fn(\Illuminate\Database\Eloquent\Model $workflow): array => [
                (string)$workflow->getKey() => (string)($workflow->getAttribute(
                    'name'
                ) ?: 'Процесс #' . $workflow->getKey()),
            ])
            ->all();
    }
}

class AmoCrmCreateLeadAction extends WorkflowAmoCrmAction
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
            static::createLeadSection(),
            static::fieldMappingsSection('Дополнительные поля сделки', 'lead'),
            static::delaySection(),
        ];
    }
}

class AmoCrmCreateContactAction extends WorkflowAmoCrmAction
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
            static::createLinkedEntitySection('Основное', 'contact', ['lead', 'company']),
            static::fieldMappingsSection('Дополнительные поля контакта', 'contact'),
            static::delaySection(),
        ];
    }
}

class AmoCrmCreateCompanyAction extends WorkflowAmoCrmAction
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
            static::createLinkedEntitySection('Основное', 'company', ['lead', 'contact']),
            static::fieldMappingsSection('Дополнительные поля компании', 'company'),
            static::delaySection(),
        ];
    }
}

class AmoCrmCopyLeadAction extends WorkflowAmoCrmAction
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
            Section::make('Новая сделка')->schema(array_merge(
                static::fixedTargetEntityFields('lead'), [
                VariableTextInput::make('name')->label('Название новой сделки')->placeholder('{{lead.name}} (копия)'),
                VariableTextInput::make('tags')->label('Теги')->placeholder('Копия, {{tag}}'),
                VariableTextInput::make('responsible_user_id')->label('Ответственный')->placeholder(
                    'ID пользователя или переменная'
                ),
            ], static::pipelineFields())),
            static::delaySection(),
        ];
    }
}

class AmoCrmUpdateFieldsAction extends WorkflowAmoCrmAction
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
            Section::make('Сущность')->schema(
                static::targetEntityFields(['lead', 'contact', 'company', 'customer'],
                    fn(Set $set): mixed => $set('fields', []))
            ),
            static::bodyMode(),
            static::amoFieldMappingsSection('Изменяемые поля')->visible(fn(Get $get): bool => $get('body_mode') !== 'json'),
            static::jsonBody(),
            static::delaySection(),
        ];
    }
}

abstract class AmoCrmUpdateEntityFieldsAction extends WorkflowAmoCrmAction
{
    abstract protected static function entity(): string;

    public static function workflowDescription(): string
    {
        return 'Изменяет одно или несколько полей.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-pencil-square';
    }

    public static function workflowColor(): string
    {
        return '#2563EB';
    }

    protected static function defaults(): array
    {
        return [
            'target_entity' => static::entity(),
            'target_entity_locked' => true,
            'target_entity_id' => null,
        ];
    }

    protected static function schema(): array
    {
        $entity = static::entity();

        return [
            Section::make('Сущность')->schema(static::fixedTargetEntityFields($entity)),
            static::bodyMode(),
            ...static::standardUpdateFields($entity),
            static::fieldMappingsSection('Дополнительные поля', $entity)->visible(fn(Get $get): bool => $get('body_mode') !== 'json'),
            static::jsonBody(),
            static::delaySection(),
        ];
    }
}

class AmoCrmUpdateLeadFieldsAction extends AmoCrmUpdateEntityFieldsAction
{
    public static function workflowType(): string
    {
        return 'amocrm_update_lead_fields';
    }

    public static function workflowName(): string
    {
        return 'Изменить сделку';
    }

    protected static function entity(): string
    {
        return 'lead';
    }
}

class AmoCrmUpdateContactFieldsAction extends AmoCrmUpdateEntityFieldsAction
{
    public static function workflowType(): string
    {
        return 'amocrm_update_contact_fields';
    }

    public static function workflowName(): string
    {
        return 'Обновить контакт';
    }

    protected static function entity(): string
    {
        return 'contact';
    }
}

class AmoCrmUpdateCompanyFieldsAction extends AmoCrmUpdateEntityFieldsAction
{
    public static function workflowType(): string
    {
        return 'amocrm_update_company_fields';
    }

    public static function workflowName(): string
    {
        return 'Изменить компанию';
    }

    protected static function entity(): string
    {
        return 'company';
    }
}

class AmoCrmCreateTaskAction extends WorkflowAmoCrmAction
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
        return '#D97706';
    }

    protected static function schema(): array
    {
        return [
            static::bodyMode(),
            Section::make('Задача')->schema(
                array_merge(static::targetEntityFields(['lead', 'contact', 'company', 'customer']), [
                    WorkflowValueInput::make('responsible_user_id')
                        ->label('Ответственный')
                        ->options(fn(): array => static::amoResponsibleOptions()),
                    WorkflowValueInput::make('task_type_id')
                        ->label('Тип задачи')
                        ->options(fn () => \App\Services\Workflows\WorkflowNodeReferences::options('task_types') ?: ['1'=>'Звонок', '2'=>'Встреча'])
                        ->default('1')
                        ->required(),
                    WorkflowValueInput::make('complete_till')
                        ->label('Срок выполнения')
                        ->options([
                            '+5 minutes' => '5 минут',
                            '+10 minutes' => '10 минут',
                            '+15 minutes' => '15 минут',
                            '+30 minutes' => '30 минут',
                            '+1 hour' => '1 час',
                            '+1 day' => '1 день',
                        ])
                        ->default('+1 hour')
                        ->required(),
                    WorkflowValueInput::make('text')->label('Текст задачи')->multiline(3)->required(),
                ])
            )->visible(fn(Get $get): bool => $get('body_mode') !== 'json'),
            static::jsonBody('{"entity_id": "{{ $json.id }}", "entity_type": "leads", "task_type_id": 1, "text": "Позвонить", "complete_till": "{{now:add(1 day):timestamp}}"}'),
            static::delaySection(),
        ];
    }
}

class AmoCrmAddNoteAction extends WorkflowAmoCrmAction
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
            Section::make('Примечание')->schema(
                array_merge(static::targetEntityFields(['lead', 'contact', 'company', 'customer']), [
                    WorkflowValueInput::make('is_system')->label('Вид примечания')->options(['0' => 'Обычное', '1' => 'Системное'])->default('0'),
                    WorkflowValueInput::make('text')->label('Текст примечания')->multiline(5)->required(),
                ])
            ),
            static::delaySection(),
        ];
    }
}

class AmoCrmChangeTagsAction extends WorkflowAmoCrmAction
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
            Section::make('Теги')->schema(
                array_merge(static::targetEntityFields(['lead', 'contact', 'company', 'customer']), [
                    VariableTextInput::make('tags_to_add')->label('Добавить теги')->placeholder('VIP, Новый'),
                    VariableTextInput::make('tags_to_remove')->label('Удалить теги')->placeholder('Старый, Ошибка'),
                    Toggle::make('remove_all')->label('Удалить все теги')->default(false),
                ])
            ),
            static::delaySection(),
        ];
    }
}

class AmoCrmChangeLeadStatusAction extends WorkflowAmoCrmAction
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
            Section::make('Статус сделки')->schema(array_merge(
                static::fixedTargetEntityFields('lead'),
                static::pipelineFields()
            )),
            static::delaySection(),
        ];
    }
}

class AmoCrmDistributionQueueAction extends WorkflowAmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_distribution_queue';
    }

    public static function workflowName(): string
    {
        return 'Распределить сделку';
    }

    public static function workflowDescription(): string
    {
        return 'Передает сделку в выбранную очередь распределения и переводит ее в этап воронки.';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-users';
    }

    public static function workflowColor(): string
    {
        return '#EA580C';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Распределение сделки')
                ->columns(2)
                ->schema([
                    ...static::fixedTargetEntityFields('lead'),
                    Select::make('distribution_queue_uuid')
                        ->label('Очередь распределения')
                        ->options(fn(): array => static::distributionQueueOptions())
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required()
                        ->placeholder('Выберите очередь'),

                    Grid::make(2)
                        ->columnSpanFull()
                        ->schema(static::pipelineFields(true)),
                ]),
            static::delaySection(),
        ];
    }
}

class AmoCrmStartSalesBotAction extends WorkflowAmoCrmAction
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

    public static function workflowDefaultConfig(): array
    {
        return array_merge(parent::workflowDefaultConfig(), ['target_entity' => 'leads', 'target_entity_id' => null]);
    }

    protected static function schema(): array
    {
        return [
            Section::make()->columns(1)->schema([
                WorkflowValueInput::make('bot_id')
                    ->label('SalesBot')
                    ->options(fn(): array => app(WorkflowAmoCrmSalesBotService::class)->options())
                    ->placeholder('Выберите SalesBot')
                    ->required(),
                ...static::targetEntityFields(['leads', 'contacts', 'customers']),
            ]),
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

class AmoCrmManageSubscriptionAction extends WorkflowAmoCrmAction
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

class AmoCrmUpdateTaskAction extends WorkflowAmoCrmAction
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
        return '#D97706';
    }

    protected static function schema(): array
    {
        return [
            Section::make('Поиск задач')->schema(
                array_merge(static::targetEntityFields(['lead', 'contact', 'company', 'customer']), [
                    VariableTextInput::make('task_type_id')->label('Найти задачи по типу')->placeholder(
                        'ID типа задачи'
                    ),
                    Select::make('scope')->label('Применить для')->options(
                        ['all' => 'Все найденные', 'last' => 'Последняя']
                    )->default('all')->native(false),
                ])
            ),
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

class AmoCrmCancelDelayedAction extends WorkflowAmoCrmAction
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

class AmoCrmSetGlobalVariableAction extends WorkflowAmoCrmAction
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

class AmoCrmNormalizeContactDataAction extends WorkflowAmoCrmAction
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
                ...static::targetEntityFields(['contact', 'company']),
                Toggle::make('normalize_phone')->label('Телефон')->default(true),
                Toggle::make('normalize_email')->label('E-mail')->default(true),
                Toggle::make('remove_invalid')->label('Удалять некорректные значения')->default(false),
            ]),
            static::delaySection(),
        ];
    }
}

class AmoCrmAddProductsAction extends WorkflowAmoCrmAction
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
                static::targetEntityIdInput('lead', 'ID сделки'),
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

class AmoCrmRemoveProductsAction extends WorkflowAmoCrmAction
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
                static::targetEntityIdInput('lead', 'ID сделки'),
                VariableTextInput::make('catalog_id')->label('Каталог товаров')->placeholder('ID каталога')->required(),
                Toggle::make('remove_all')->label('Удалить все товары списка')->default(true),
            ]),
            static::delaySection(),
        ];
    }
}

class AmoCrmFindEntityAction extends WorkflowAmoCrmAction
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
                    ->live()
                    ->afterStateHydrated(function (?string $state, Set $set, Get $get): void {
                        if (trim((string)$get('context_key')) === '') {
                            $set('context_key', static::findResultKey($state ?: 'lead'));
                        }

                    })
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                        $set('conditions', []);
                        $key = static::findResultKey($state ?: 'lead', (string)$get('context_key'));
                        $set('context_key', $key);
                    }),
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
                Hidden::make('context_key')
                    ->default('found_lead_1')
                    ->required(),
            ]),
        ];
    }

    private static function findResultMask(string $key): string
    {
        return '{{' . (trim($key) !== '' ? trim($key) : 'found_lead_1') . '.id}}';
    }

    private static function findResultKey(string $entity, string $current = ''): string
    {
        $entity = in_array($entity, ['lead', 'contact', 'company', 'customer'], true) ? $entity : 'lead';
        $current = trim($current);

        if (preg_match('/^found_(lead|contact|company|customer)_(?<index>\d+)$/', $current, $matches)) {
            return 'found_' . $entity . '_' . (int)$matches['index'];
        }

        return 'found_' . $entity . '_1';
    }
}

class AmoCrmLinkEntityAction extends WorkflowAmoCrmAction
{
    public static function workflowType(): string
    {
        return 'amocrm_link_entity';
    }

    public static function workflowName(): string
    {
        return 'Связать сущности';
    }

    public static function workflowDescription(): string
    {
        return 'Связывает сделку, контакт, компанию или покупателя между собой.';
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
            Grid::make(['default' => 1, 'xl' => 2])
                ->schema([
                    Section::make('1. Основная сущность')
                        ->description('Что уже есть в потоке')
                        ->compact()
                        ->schema(static::targetEntityFields(['lead', 'contact', 'company', 'customer']))
                        ->extraAttributes(['class' => 'workflow-entity-link-card']),
                    Section::make('2. Связать с ней')
                        ->description('Что нужно прикрепить')
                        ->compact()
                        ->schema([
                        Select::make('linked_entity')->label('Сущность')->options([
                            'lead' => 'Сделку',
                            'contact' => 'Контакт',
                            'company' => 'Компанию',
                            'customer' => 'Покупателя',
                        ])->required()->native(false),
                        VariableTextInput::make('linked_entity_id')->label('ID или переменная')->required(),
                    ])->extraAttributes(['class' => 'workflow-entity-link-card']),
                ])
                ->extraAttributes(['class' => 'workflow-entity-link-action']),
            static::delaySection(),
        ];
    }
}

class AmoCrmContactLeadsAction extends WorkflowAmoCrmAction
{
    public static function workflowType(): string { return 'amocrm_contact_leads'; }
    public static function workflowName(): string { return 'Сделки контакта'; }
    public static function workflowDescription(): string { return 'Все доступные сделки контакта или основного контакта сделки'; }
    public static function workflowCategory(): string { return 'Запросы'; }
    public static function workflowIcon(): string { return 'heroicon-o-circle-stack'; }
    protected static function defaults(): array { return ['source'=>'lead', 'lead_id'=>null]; }
    protected static function schema(): array
    {
        return [
            Select::make('source')->label('Контакт')->options(['lead'=>'Основной контакт сделки','contact'=>'Указать ID контакта'])->default('lead')->live()->required(),
            WorkflowValueInput::make('lead_id')->label('ID исходной сделки')->default(null)->placeholder('ID или переменная')->visible(fn (Get $get) => $get('source') !== 'contact')->required(),
            WorkflowValueInput::make('contact_id')->label('ID контакта')->visible(fn (Get $get) => $get('source') === 'contact')->required(),
            WorkflowValueInput::make('exclude_lead_id')->label('Исключить сделку · ID')->placeholder('Например, {{lead.id}}'),
        ];
    }
}

class AmoCrmQueryLeadsAction extends WorkflowAmoCrmAction
{
    public static function workflowType(): string { return 'amocrm_query_leads'; }
    public static function workflowName(): string { return 'Получить сделки'; }
    public static function workflowDescription(): string { return 'Получает список сделок amoCRM по фильтрам, без изменений данных.'; }
    public static function workflowCategory(): string { return 'Запросы'; }
    public static function workflowIcon(): string { return 'heroicon-o-circle-stack'; }
    protected static function defaults(): array { return ['limit' => 50, 'page' => 1, 'sort' => 'created_at', 'direction' => 'desc', 'filters' => []]; }

    protected static function schema(): array
    {
        return [
            VariableTextInput::make('query')->label('Поиск')->placeholder('Текст или выражение'),
            Grid::make(2)->schema(static::pipelineFields()),
            Select::make('responsible_user_ids')->label('Ответственные')->multiple()->searchable()->options(fn () => static::amoResponsibleOptions()),
            Repeater::make('filters')->label('Фильтры')->addActionLabel('Добавить фильтр')->defaultItems(0)->columns(3)->schema([
                Select::make('field')->label('Поле')->searchable()->live()->required()->options(fn () => [
                    'id' => 'ID сделки', 'name' => 'Название', 'price' => 'Бюджет', 'created_at' => 'Дата создания',
                    'updated_at' => 'Дата изменения', 'closed_at' => 'Дата закрытия', 'closest_task_at' => 'Дата ближайшей задачи',
                ] + collect(static::amoFieldOptions('lead'))->filter(fn ($label, $key) => is_numeric($key))->mapWithKeys(fn ($label, $key) => ['custom:' . $key => $label])->all()),
                Select::make('operator')->label('Сравнение')->default('eq')->required()->options(fn (Get $get) => in_array($get('field'), ['id', 'name'], true) ? ['eq' => 'Равно'] : ['eq' => 'Равно', 'from' => 'От', 'to' => 'До']),
                VariableTextInput::make('value')->label('Значение')->required(),
            ])->helperText('Разные поля — И, несколько значений одного списочного поля — ИЛИ. Даты: YYYY-MM-DD или Unix-время. Для списочных полей — ID варианта. API-фильтры должны быть доступны в вашем amoCRM.'),
            Grid::make(2)->schema([
                WorkflowValueInput::make('limit')->label('Лимит')->default(50)->required(),
                WorkflowValueInput::make('page')->label('Страница')->default(1)->required(),
                Select::make('sort')->label('Сортировка')->options(['created_at' => 'Дата создания', 'updated_at' => 'Дата изменения', 'id' => 'ID'])->default('created_at'),
                Select::make('direction')->label('Порядок')->options(['desc' => 'Сначала новые', 'asc' => 'Сначала старые'])->default('desc'),
            ]),
        ];
    }
}

class AmoCrmGetContactAction extends WorkflowAmoCrmAction
{
    public static function workflowType(): string { return 'amocrm_get_contact'; }
    public static function workflowName(): string { return 'Получить контакт'; }
    public static function workflowDescription(): string { return 'Получает контакт amoCRM по ID.'; }
    public static function workflowCategory(): string { return 'Запросы'; }
    public static function workflowIcon(): string { return 'heroicon-o-user'; }

    protected static function defaults(): array
    {
        return [
            'target_entity' => 'contact',
            'target_entity_locked' => true,
        ];
    }

    protected static function schema(): array
    {
        return [
            Section::make('Контакт')->schema(static::fixedTargetEntityFields('contact')),
        ];
    }
}

class AmoCrmReadAction extends WorkflowAmoCrmAction
{
    public static function workflowType(): string { return 'amocrm_read'; }
    public static function workflowName(): string { return 'Запрос amoCRM'; }
    public static function workflowDescription(): string { return 'Чтение данных amoCRM'; }
    public static function workflowCategory(): string { return 'Запросы'; }
    public static function workflowIcon(): string { return 'heroicon-o-circle-stack'; }
    protected static function defaults(): array { return ['operation' => 'contacts.list', 'parameters' => [], 'body_mode' => 'fields', 'filters' => [], 'limit' => 50, 'page' => 1, 'direction' => 'asc']; }
    protected static function schema(): array
    {
        $fields = [
            Hidden::make('operation')->required()->live(),
            Select::make('operation_variant')->label('Режим запроса')->native(false)->live()->dehydrated(false)
                ->afterStateHydrated(function(Set $set, Get $get): void {
                    $set('operation_variant', $get('operation'));
                })
                ->options(fn(Get $get): array => \App\Services\Workflows\WorkflowAmoReadCatalog::variantOptions((string)$get('operation')))
                ->visible(fn(Get $get): bool => count(\App\Services\Workflows\WorkflowAmoReadCatalog::variantOptions((string)$get('operation'))) > 1)
                ->afterStateUpdated(function(mixed $state, Set $set): void {
                    $set('operation', $state);
                    $set('body_mode', WorkflowEntityQuery::supports((string)$state) ? 'builder' : 'fields');
                }),
        ];
        foreach (['id' => 'ID', 'entity_id' => 'ID сущности', 'pipeline_id' => 'Воронка', 'catalog_id' => 'ID списка'] as $key => $label) {
            $field = WorkflowValueInput::make($key)->label($label)->required()
                ->visible(fn(Get $get) => str_contains(\App\Services\Workflows\WorkflowAmoReadCatalog::operations()[$get('operation')]['path'] ?? '', '{'.$key.'}'));
            if ($key === 'pipeline_id') $field->options(fn() => static::amoPipelineOptions());
            $fields[] = $field;
        }
        return [...$fields,
            WorkflowValueInput::make('request_path')->label('Путь')->placeholder('/api/v4/contacts')->required()->visible(fn(Get $get) => $get('operation') === 'custom'),
            ToggleButtons::make('body_mode')->hiddenLabel()->inline()->live()->default('fields')
                ->options(fn(Get $get) => (WorkflowEntityQuery::supports($get('operation')) ? ['builder' => 'Конструктор'] : []) + ['fields' => 'Параметры']
                    + ($get('body_mode') === 'json' ? ['json' => 'Сохранённый запрос'] : []))
                ->afterStateUpdated(function(Get $get, Set $set) {
                    if ($get('body_mode') !== 'builder') return;
                    foreach (['limit'=>50, 'page'=>1, 'direction'=>'asc'] as $key=>$value) if (blank($get($key))) $set($key, $value);
                }),
            ...static::queryBuilderFields(),
            Repeater::make('parameters')->label('Параметры запроса')->columns(1)->defaultItems(0)->reorderable(false)->addActionLabel('Добавить параметр')
                ->schema([TextInput::make('name')->label('Параметр')->placeholder('filter[id][]')->required(), WorkflowValueInput::make('value')->label('Значение')])
                ->visible(fn(Get $get) => !in_array($get('body_mode'), ['json', 'builder'], true)),
            static::jsonBody()->label('Сохранённые параметры запроса')
                ->helperText('Прежний запрос с динамическими параметрами. Передаётся в URL, без тела. Для нового запроса выберите конструктор или параметры.'),
        ];
    }

    private static function queryBuilderFields(): array
    {
        $metadata = fn(Get $get) => WorkflowEntityQuery::fields((string)$get('../../operation'), Auth::id())[$get('field')] ?? [];
        return [Grid::make(1)->visible(fn(Get $get) => $get('body_mode') === 'builder')->schema([
            WorkflowValueInput::make('query')->label('Поиск')->placeholder('Текст или переменная')
                ->visible(fn(Get $get) => $get('operation') !== 'tasks.list'),
            Repeater::make('filters')->label('Фильтры')->defaultItems(0)->maxItems(50)->reorderable(false)
                ->addActionLabel('Добавить фильтр')->columns(2)->extraAttributes(['class' => 'workflow-query-filters'])
                ->schema([
                    Select::make('field')->label('Поле')->searchable()->live()->required()
                        ->options(fn(Get $get) => array_map(fn($field) => $field['label'], WorkflowEntityQuery::fields((string)$get('../../operation'), Auth::id())))
                        ->afterStateUpdated(function(Set $set) { $set('operator', 'eq'); $set('value', null); $set('pipeline_id', null); }),
                    Select::make('operator')->label('Условие')->options(fn(Get $get) => WorkflowEntityQuery::operators($metadata($get)))->default('eq')->required(),
                    WorkflowValueInput::make('pipeline_id')->label('Воронка')->options(fn() => static::amoPipelineOptions())
                        ->live()->afterStateUpdated(fn(Set $set) => $set('value', null))->required()->columnSpanFull()
                        ->visible(fn(Get $get) => $get('field') === 'statuses'),
                    WorkflowValueInput::make('value')->label('Значение')->required()->columnSpanFull()
                        ->placeholder(fn(Get $get) => ($metadata($get)['type'] ?? '') === 'date' ? 'YYYY-MM-DD или Unix-время' : 'Значение или переменная')
                        ->options(fn(Get $get) => match ($get('field')) {
                            'responsible_user_id', 'created_by', 'updated_by' => static::amoResponsibleOptions(),
                            'pipeline_id' => static::amoPipelineOptions(),
                            'statuses' => static::amoStatusOptions($get('pipeline_id')),
                            'task_type' => \App\Services\Workflows\WorkflowNodeReferences::options('task_types') ?: [1 => 'Звонок', 2 => 'Встреча'],
                            default => $metadata($get)['options'] ?? [],
                        }),
                ])->helperText(fn(Get $get) => 'Разные поля — И. Повторные значения списочного поля — ИЛИ.' . ($get('operation') === 'tasks.list'
                    ? ' Для ID сущности укажите её тип.' : ' Расширенные фильтры требуют доступной API-фильтрации amoCRM.')),
            Grid::make(2)->schema([
                WorkflowValueInput::make('limit')->label('На странице')->default(50)->required(),
                WorkflowValueInput::make('page')->label('Страница')->default(1)->required(),
                WorkflowValueInput::make('sort')->label('Сортировать по')->options(fn(Get $get) => WorkflowEntityQuery::sorts((string)$get('operation')))->placeholder('Без сортировки'),
                WorkflowValueInput::make('direction')->label('Порядок')->options(['asc' => 'По возрастанию', 'desc' => 'По убыванию'])->default('asc'),
            ]),
        ])];
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
