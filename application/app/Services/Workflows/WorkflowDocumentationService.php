<?php

namespace App\Services\Workflows;

use App\Models\amoCRM\Field as AmoCrmField;
use App\Models\amoCRM\Staff as AmoCrmStaff;
use App\Models\amoCRM\Status as AmoCrmStatus;
use App\Models\Workflows\Workflow;
use App\Workflows\Actions\WorkflowTriggerConditionVariableCatalog;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Triggers\TriggerRegistry;

class WorkflowDocumentationService
{
    private const MAX_CHILD_DEPTH = 2;

    /** @var array<int, Workflow> */
    private array $workflowCache = [];

    public function __construct(
        private readonly ActionRegistry $actions,
        private readonly TriggerRegistry $triggers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function accountDocument(int $userId): array
    {
        $workflows = Workflow::query()
            ->where('user_id', $userId)
            ->orderBy('group_name')
            ->orderBy('name')
            ->get();

        $this->primeCache($workflows);

        return [
            'title' => 'Документация сценариев',
            'scope' => 'Все сценарии аккаунта',
            'generated_at' => now(),
            'workflows_count' => $workflows->count(),
            'workflows' => $workflows
                ->map(fn(Workflow $workflow): array => $this->workflowDocument($workflow, includeChildren: false))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function singleDocument(Workflow $workflow): array
    {
        $this->workflowCache = [(int)$workflow->getKey() => $workflow];

        return [
            'title' => 'Документация сценария',
            'scope' => (string)($workflow->name ?: 'Сценарий #' . $workflow->getKey()),
            'generated_at' => now(),
            'workflows_count' => 1,
            'workflows' => [
                $this->workflowDocument($workflow, includeChildren: true),
            ],
        ];
    }

    /**
     * @param EloquentCollection<int, Workflow> $workflows
     */
    private function primeCache(EloquentCollection $workflows): void
    {
        $this->workflowCache = [];

        foreach ($workflows as $workflow) {
            $this->workflowCache[(int)$workflow->getKey()] = $workflow;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowDocument(Workflow $workflow, bool $includeChildren, int $depth = 0): array
    {
        $definition = (array)($workflow->definition ?? []);
        $actions = (array)Arr::get($definition, 'actions', []);
        $steps = $this->documentActions($workflow, $actions, $includeChildren, $depth);
        $variables = $this->variablesFrom([$definition]);

        return [
            'id' => (int)$workflow->getKey(),
            'name' => (string)($workflow->name ?: 'Сценарий #' . $workflow->getKey()),
            'description' => (string)($workflow->description ?? ''),
            'group' => (string)($workflow->group_name ?: 'Без группы'),
            'active' => (bool)$workflow->is_active,
            'trigger' => $this->triggerDocument((array)Arr::get($definition, 'trigger', [])),
            'steps_count' => count($steps),
            'steps' => $steps,
            'variables' => $variables,
            'children' => $this->childWorkflowDocuments($workflow, $actions, $includeChildren, $depth),
            'warnings' => $this->warnings($workflow, $definition, $actions),
        ];
    }

    /**
     * @param array<int, mixed> $actions
     * @return array<int, array<string, mixed>>
     */
    private function documentActions(
        Workflow $workflow,
        array $actions,
        bool $includeChildren,
        int $depth,
        string $prefix = '',
        ?string $branch = null
    ): array {
        $documented = [];

        foreach (array_values($actions) as $index => $action) {
            if (!is_array($action)) {
                continue;
            }

            $number = $prefix === '' ? (string)($index + 1) : $prefix . '.' . ($index + 1);
            $type = (string)($action['type'] ?? '');
            $config = (array)($action['config'] ?? []);
            $isCondition = $this->isCondition($action);
            $childWorkflow = $type === 'run_workflow'
                ? $this->findWorkflow($workflow, (int)($config['workflow_id'] ?? 0))
                : null;

            $step = [
                'number' => $number,
                'branch' => $branch,
                'type' => $type,
                'type_label' => $this->actionTypeLabel($type, $action),
                'name' => $this->actionTitle($action, $type, $config),
                'description' => $this->actionDescription($type, $config),
                'settings' => $this->settings($config, (int)$workflow->user_id, $type),
                'conditions' => $isCondition ? $this->conditions($config, (int)$workflow->user_id) : [],
                'child_workflow' => $childWorkflow ? [
                    'id' => (int)$childWorkflow->getKey(),
                    'name' => (string)($childWorkflow->name ?: 'Сценарий #' . $childWorkflow->getKey()),
                    'active' => (bool)$childWorkflow->is_active,
                ] : null,
            ];

            $documented[] = $step;

            if ($isCondition) {
                foreach ([
                    'true_actions' => 'Да',
                    'false_actions' => 'Нет',
                ] as $branchKey => $branchLabel) {
                    $branchActions = (array)Arr::get($config, $branchKey, []);

                    $documented = array_merge(
                        $documented,
                        $this->documentActions(
                            $workflow,
                            $branchActions,
                            $includeChildren,
                            $depth,
                            $number,
                            $branchLabel
                        )
                    );
                }
            }

            if (
                $includeChildren
                && $childWorkflow instanceof Workflow
                && $depth < self::MAX_CHILD_DEPTH
            ) {
                $documented = array_merge(
                    $documented,
                    $this->documentActions(
                        $childWorkflow,
                        (array)Arr::get($childWorkflow->definition, 'actions', []),
                        true,
                        $depth + 1,
                        $number . '.д',
                        'Дочерний сценарий: ' . (string)$childWorkflow->name
                    )
                );
            }
        }

        return $documented;
    }

    /**
     * @param array<string, mixed> $trigger
     * @return array<string, mixed>
     */
    private function triggerDocument(array $trigger): array
    {
        $type = (string)($trigger['type'] ?? '');
        $config = (array)($trigger['config'] ?? []);
        $class = $this->triggers->get($type);

        $name = $class && method_exists($class, 'name')
            ? (string)$class::name()
            : $this->fallbackTriggerName($type, $config);

        $description = $class && method_exists($class, 'getConfiguredDescription')
            ? trim(strip_tags((string)$class::getConfiguredDescription($config)))
            : $this->fallbackTriggerDescription($type, $config);

        return [
            'type' => $type,
            'name' => $name !== '' ? $name : 'Не выбран',
            'description' => $description,
            'settings' => $this->settings($config, 0, 'trigger'),
        ];
    }

    /**
     * @param array<int, mixed> $actions
     * @return array<int, array<string, mixed>>
     */
    private function childWorkflowDocuments(Workflow $workflow, array $actions, bool $includeChildren, int $depth): array
    {
        if (!$includeChildren || $depth >= self::MAX_CHILD_DEPTH) {
            return [];
        }

        return collect($this->childWorkflowIds($actions))
            ->unique()
            ->map(fn(int $workflowId): ?Workflow => $this->findWorkflow($workflow, $workflowId))
            ->filter()
            ->map(fn(Workflow $child): array => $this->workflowDocument($child, true, $depth + 1))
            ->values()
            ->all();
    }

    /**
     * @param array<int, mixed> $actions
     * @return array<int, int>
     */
    private function childWorkflowIds(array $actions): array
    {
        $ids = [];

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $config = (array)($action['config'] ?? []);

            if (($action['type'] ?? null) === 'run_workflow' && (int)($config['workflow_id'] ?? 0) > 0) {
                $ids[] = (int)$config['workflow_id'];
            }

            foreach (['true_actions', 'false_actions'] as $branchKey) {
                $ids = array_merge($ids, $this->childWorkflowIds((array)Arr::get($config, $branchKey, [])));
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, array{left: string, operator: string, right: string, logic: string}>
     */
    private function conditions(array $config, int $userId): array
    {
        $logic = ((string)($config['logic'] ?? 'and')) === 'or' ? 'ИЛИ' : 'И';

        return collect((array)($config['conditions'] ?? []))
            ->filter(fn(mixed $condition): bool => is_array($condition))
            ->map(fn(array $condition): array => [
                'left' => $this->humanValue($condition['left'] ?? '', $userId),
                'operator' => $this->operator((string)($condition['operator'] ?? 'equals')),
                'right' => $this->conditionRightValue($condition, $userId),
                'logic' => $logic,
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, array{label: string, value: string}>
     */
    private function settings(array $config, int $userId, string $type): array
    {
        $rows = [];

        foreach ($config as $key => $value) {
            if (in_array($key, ['true_actions', 'false_actions', 'conditions'], true)) {
                continue;
            }

            if ($this->isSecretKey((string)$key)) {
                continue;
            }

            if ($key === 'delay') {
                $delay = $this->delay($value);

                if ($delay !== null) {
                    $rows[] = ['label' => 'Когда выполнить', 'value' => $delay];
                }

                continue;
            }

            if ($key === 'fields' && is_array($value)) {
                foreach ($this->fieldMappings($value, $userId) as $mapping) {
                    $rows[] = $mapping;
                }

                continue;
            }

            if (is_array($value)) {
                $value = $this->arraySummary($value, $userId);
            }

            $settingValue = $key === 'status_id'
                ? $this->statusName($value, $config['pipeline_id'] ?? null, $userId)
                : $this->settingValue((string)$key, $value, $userId);

            $rows[] = [
                'label' => $this->settingLabel((string)$key, $type),
                'value' => $settingValue,
            ];
        }

        return array_values(array_filter(
            $rows,
            fn(array $row): bool => trim((string)$row['value']) !== ''
        ));
    }

    /**
     * @param array<int, mixed> $fields
     * @return array<int, array{label: string, value: string}>
     */
    private function fieldMappings(array $fields, int $userId): array
    {
        return collect($fields)
            ->filter(fn(mixed $field): bool => is_array($field))
            ->map(function (array $field) use ($userId): array {
                $fieldId = $field['field_id'] ?? $field['field'] ?? null;
                $fieldName = $this->fieldName($fieldId, $userId);

                return [
                    'label' => 'Поле: ' . $fieldName,
                    'value' => $this->humanValue($field['value'] ?? '', $userId),
                ];
            })
            ->values()
            ->all();
    }

    private function actionTitle(array $action, string $type, array $config): string
    {
        $customName = trim((string)($action['name'] ?? ''));

        if ($customName !== '') {
            return $customName;
        }

        $class = $this->actions->get($type);

        if ($class && method_exists($class, 'workflowName')) {
            return str((string)$class::workflowName())
                ->replaceStart('amoCRM: ', '')
                ->toString();
        }

        if ($type === 'run_workflow' && (int)($config['workflow_id'] ?? 0) > 0) {
            return 'Запустить процесс';
        }

        return match ($type) {
            'control-condition', 'condition' => 'Условие',
            'send_notification' => 'Уведомление',
            'send_email' => 'Отправить email',
            default => $type !== '' ? $type : 'Действие',
        };
    }

    private function actionTypeLabel(string $type, array $action): string
    {
        if ($this->isCondition($action)) {
            return 'Условие';
        }

        return match (true) {
            $type === 'run_workflow' => 'Дочерний процесс',
            str_starts_with($type, 'amocrm_') => 'amoCRM',
            in_array($type, ['send_notification', 'send_email', 'multi_channel_notification'], true) => 'Уведомление',
            default => 'Действие',
        };
    }

    private function actionDescription(string $type, array $config): string
    {
        $class = $this->actions->get($type);

        if ($class && method_exists($class, 'getConfiguredDescription')) {
            return trim(strip_tags((string)$class::getConfiguredDescription($config)));
        }

        return '';
    }

    private function fallbackTriggerName(string $type, array $config): string
    {
        if (($config['source'] ?? null) === 'amocrm') {
            return 'amoCRM: ' . $this->eventLabel((string)($config['event'] ?? $type));
        }

        return match ($type) {
            'manual' => 'Ручной запуск',
            'schedule' => 'По расписанию',
            'date-condition' => 'Относительно даты',
            'workflow-completed' => 'Запуск из другого процесса',
            'generic-webhook' => 'Вебхук',
            default => $type,
        };
    }

    private function fallbackTriggerDescription(string $type, array $config): string
    {
        if (($config['source'] ?? null) === 'amocrm') {
            return $this->eventLabel((string)($config['event'] ?? $type));
        }

        return '';
    }

    private function eventLabel(string $event): string
    {
        return match ($event) {
            'add_lead' => 'добавлена сделка',
            'update_lead' => 'сделка изменена',
            'status_lead' => 'сменился статус сделки',
            'note_lead' => 'добавлено примечание в сделке',
            'add_contact' => 'добавлен контакт',
            'update_contact' => 'контакт изменён',
            'add_company' => 'добавлена компания',
            'update_company' => 'компания изменена',
            'add_task' => 'добавлена задача',
            'update_task' => 'задача изменена',
            default => str_replace('_', ' ', $event),
        };
    }

    private function settingLabel(string $key, string $type): string
    {
        return match ($key) {
            'source' => 'Источник',
            'event' => 'Событие',
            'entity' => 'Сущность',
            'action' => 'Действие',
            'target_entity' => 'Применить к',
            'target_entity_id', 'entity_id' => 'ID сущности',
            'linked_entity' => 'Что прикрепить',
            'linked_entity_id' => 'ID прикрепляемой сущности',
            'pipeline_id' => 'Воронка',
            'status_id' => 'Статус',
            'responsible_user_id' => 'Ответственный',
            'task_type_id', 'new_task_type_id' => 'Тип задачи',
            'complete_till' => 'Срок выполнения',
            'text' => str_contains($type, 'note') ? 'Текст примечания' : 'Текст',
            'name' => 'Название',
            'tags', 'tags_to_add' => 'Теги добавить',
            'tags_to_remove' => 'Теги удалить',
            'remove_all' => 'Удалить всё',
            'bot_id' => 'SalesBot',
            'workflow_id' => 'Процесс',
            'pass_context' => 'Передать контекст',
            'pass_trigger_model' => 'Модель триггера',
            'pass_step_outputs' => 'Результаты шагов',
            'pass_variables' => 'Переменные',
            'wait_for_completion' => 'Дождаться завершения',
            'fail_on_child_failure' => 'Остановить при ошибке дочернего процесса',
            'store_result' => 'Сохранить результат',
            'context_key' => 'Ключ результата',
            default => str($key)->replace('_', ' ')->ucfirst()->toString(),
        };
    }

    private function settingValue(string $key, mixed $value, int $userId): string
    {
        if (is_bool($value)) {
            return $value ? 'Да' : 'Нет';
        }

        if ($value === null) {
            return '';
        }

        return match ($key) {
            'pipeline_id' => $this->pipelineName($value, $userId),
            'status_id' => $this->statusName($value, null, $userId),
            'target_entity', 'entity', 'linked_entity' => $this->entityLabel((string)$value),
            'responsible_user_id' => $this->staffName($value, $userId),
            'workflow_id' => $this->workflowName((int)$value),
            default => $this->humanValue($value, $userId),
        };
    }

    private function humanValue(mixed $value, int $userId): string
    {
        if (is_bool($value)) {
            return $value ? 'Да' : 'Нет';
        }

        if (is_array($value)) {
            return $this->arraySummary($value, $userId);
        }

        $value = trim((string)$value);

        if ($value === '') {
            return '—';
        }

        $label = WorkflowTriggerConditionVariableCatalog::label($value, true);

        return $label !== null ? $label : $value;
    }

    private function arraySummary(array $value, int $userId): string
    {
        if ($value === []) {
            return '—';
        }

        return collect($value)
            ->map(function (mixed $item, int|string $key) use ($userId): string {
                if (is_array($item)) {
                    return $this->arraySummary($item, $userId);
                }

                return is_string($key)
                    ? $this->settingLabel($key, '') . ': ' . $this->humanValue($item, $userId)
                    : $this->humanValue($item, $userId);
            })
            ->filter()
            ->implode('; ');
    }

    private function delay(mixed $delay): ?string
    {
        if (!is_array($delay)) {
            return null;
        }

        $mode = (string)($delay['mode'] ?? 'immediate');

        if ($mode === 'immediate') {
            return 'Сразу';
        }

        if (in_array($mode, ['seconds', 'after_seconds'], true)) {
            return min(30, max(1, (int)($delay['seconds'] ?? 0))) . ' сек.';
        }

        return 'Сразу';
    }

    private function operator(string $operator): string
    {
        return match ($operator) {
            'equals', '==' => 'равно',
            'not_equals', '!=' => 'не равно',
            'contains' => 'содержит',
            'not_contains' => 'не содержит',
            'greater_than', '>' => 'больше',
            'less_than', '<' => 'меньше',
            'empty' => 'пусто',
            'not_empty' => 'заполнено',
            'matches_regex' => 'по регулярному выражению',
            default => $operator,
        };
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function conditionRightValue(array $condition, int $userId): string
    {
        $left = (string)($condition['left'] ?? '');
        $right = $condition['right'] ?? '';

        if (str_contains($left, 'pipeline_id')) {
            return $this->pipelineName($right, $userId);
        }

        if (str_contains($left, 'status_id')) {
            return $this->statusName($right, null, $userId);
        }

        return $this->humanValue($right, $userId);
    }

    private function pipelineName(mixed $pipelineId, int $userId): string
    {
        $pipelineId = (string)$pipelineId;

        if ($pipelineId === '') {
            return '—';
        }

        $name = AmoCrmStatus::query()
            ->where('user_id', $userId)
            ->where('pipeline_id', $pipelineId)
            ->value('pipeline_name');

        return $name ? $name . ' · ID ' . $pipelineId : 'ID ' . $pipelineId;
    }

    private function statusName(mixed $statusId, mixed $pipelineId, int $userId): string
    {
        $statusId = (string)$statusId;

        if ($statusId === '') {
            return '—';
        }

        $query = AmoCrmStatus::query()
            ->where('user_id', $userId)
            ->where('status_id', $statusId);

        if ($pipelineId) {
            $query->where('pipeline_id', $pipelineId);
        }

        $status = $query->first(['name', 'pipeline_name']);

        return $status
            ? $status->name . ($status->pipeline_name ? ' · ' . $status->pipeline_name : '') . ' · ID ' . $statusId
            : 'ID ' . $statusId;
    }

    private function fieldName(mixed $fieldId, int $userId): string
    {
        $fieldId = trim((string)$fieldId);

        if ($fieldId === '') {
            return 'не выбрано';
        }

        if (str_starts_with($fieldId, 'system:')) {
            return $this->systemFieldName($fieldId);
        }

        if (!is_numeric($fieldId)) {
            return $fieldId;
        }

        $field = AmoCrmField::query()
            ->where('user_id', $userId)
            ->where('field_id', (int)$fieldId)
            ->first(['name']);

        return $field ? $field->name . ' · ID ' . $fieldId : 'ID ' . $fieldId;
    }

    private function systemFieldName(string $field): string
    {
        return [
            'system:name' => 'Название / имя',
            'system:first_name' => 'Имя',
            'system:last_name' => 'Фамилия',
            'system:price' => 'Бюджет',
            'system:responsible_user_id' => 'Ответственный',
            'system:pipeline_id' => 'Воронка',
            'system:status_id' => 'Статус',
            'system:closed_at' => 'Дата закрытия',
            'system:loss_reason_id' => 'Причина отказа',
            'system:next_price' => 'Ожидаемая сумма',
        ][$field] ?? $field;
    }

    private function staffName(mixed $staffId, int $userId): string
    {
        $staffId = trim((string)$staffId);

        if ($staffId === '') {
            return '—';
        }

        $staff = AmoCrmStaff::query()
            ->where('user_id', $userId)
            ->where('staff_id', $staffId)
            ->first(['name']);

        return $staff ? $staff->name . ' · ID ' . $staffId : 'ID ' . $staffId;
    }

    private function workflowName(int $workflowId): string
    {
        if ($workflowId <= 0) {
            return '—';
        }

        $workflow = $this->workflowCache[$workflowId] ?? Workflow::query()->find($workflowId);

        return $workflow
            ? (string)($workflow->name ?: 'Сценарий #' . $workflowId) . ' · ID ' . $workflowId
            : 'ID ' . $workflowId;
    }

    private function entityLabel(string $entity): string
    {
        return match ($entity) {
            'lead', 'leads' => 'Сделка',
            'contact', 'contacts' => 'Контакт',
            'company', 'companies' => 'Компания',
            'customer', 'customers' => 'Покупатель',
            'task', 'tasks' => 'Задача',
            default => $entity,
        };
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, mixed> $actions
     * @return array<int, string>
     */
    private function warnings(Workflow $workflow, array $definition, array $actions): array
    {
        $warnings = [];

        if (!(bool)$workflow->is_active) {
            $warnings[] = 'Сценарий выключен.';
        }

        if (!Arr::get($definition, 'trigger.type')) {
            $warnings[] = 'Не выбран триггер.';
        }

        if ($actions === []) {
            $warnings[] = 'Нет настроенных действий.';
        }

        foreach ($this->childWorkflowIds($actions) as $workflowId) {
            if (!$this->findWorkflow($workflow, $workflowId)) {
                $warnings[] = 'Дочерний сценарий #' . $workflowId . ' не найден.';
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param array<int, mixed> $sources
     * @return array<int, string>
     */
    private function variablesFrom(array $sources): array
    {
        preg_match_all('/\{\{\s*([^}]+?)\s*\}\}/', json_encode($sources, JSON_UNESCAPED_UNICODE) ?: '', $matches);

        return collect($matches[1] ?? [])
            ->map(fn(string $value): string => '{{' . trim($value) . '}}')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function isSecretKey(string $key): bool
    {
        return str_contains($key, 'token')
            || str_contains($key, 'secret')
            || str_contains($key, 'password')
            || in_array($key, ['api_key', 'secret_key', 'access_key', 'private_key'], true);
    }

    private function isCondition(array $action): bool
    {
        return in_array((string)($action['type'] ?? ''), ['condition', 'control-condition'], true)
            || ($action['componentType'] ?? null) === 'control-condition';
    }

    private function findWorkflow(Workflow $rootWorkflow, int $workflowId): ?Workflow
    {
        if ($workflowId <= 0) {
            return null;
        }

        if (isset($this->workflowCache[$workflowId])) {
            return $this->workflowCache[$workflowId];
        }

        $workflow = Workflow::query()
            ->where('user_id', $rootWorkflow->user_id)
            ->find($workflowId);

        if ($workflow) {
            $this->workflowCache[$workflowId] = $workflow;
        }

        return $workflow;
    }
}
