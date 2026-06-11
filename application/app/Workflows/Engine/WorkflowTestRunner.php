<?php

namespace App\Workflows\Engine;

use App\Models\Core\Account;
use App\Workflows\Context\WorkflowContext;
use App\Workflows\Actions\WorkflowTriggerConditionVariableCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Engine\WorkflowTestRunner as BaseWorkflowTestRunner;

class WorkflowTestRunner extends BaseWorkflowTestRunner
{
    private ?int $currentWorkflowId = null;

    public function __construct(ActionRegistry $actionRegistry)
    {
        parent::__construct($actionRegistry);

        $this->sideEffectActions = [];
    }

    /**
     * @param array<string, mixed> $testInputs
     */
    protected function buildTestContext(array $testInputs, ?Model $testModel): WorkflowContext
    {
        $testInputs = $this->normalizeAmoCrmTestInputs($testInputs);
        $workflowId = $this->numericId(Arr::get($testInputs, '_workflow_id'));
        $this->currentWorkflowId = $workflowId;

        return (new WorkflowContext($testInputs))
            ->setWorkflowId($workflowId)
            ->setTriggerSource('test')
            ->setTriggerModel($testModel)
            ->setTriggerData($testInputs)
            ->setVariable('_capture_amo_exchange', true);
    }

    /**
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    protected function executeTestStep(
        array $step,
        \Leek\FilamentWorkflows\Context\WorkflowContext $context,
        string $path
    ): array {
        $result = parent::executeTestStep($step, $context, $path);

        if (str_starts_with((string)($result['type'] ?? ''), 'amocrm_')) {
            $result['output'] = $this->withRealAmoCrmEntities($result['output'] ?? [], $context);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $testInputs
     * @return array<string, mixed>
     */
    private function normalizeAmoCrmTestInputs(array $testInputs): array
    {
        $entity = (string)Arr::get($testInputs, 'entity', '');

        if (!in_array($entity, ['lead', 'contact', 'company', 'customer'], true)) {
            return $testInputs;
        }

        $itemId = $this->numericId(Arr::get($testInputs, 'item.id'));
        $entityId = $this->numericId(Arr::get($testInputs, $entity . '.id'));

        if ($itemId && !$entityId) {
            Arr::set($testInputs, $entity . '.id', $itemId);
        }

        if (!$itemId && $entityId) {
            Arr::set($testInputs, 'item.id', $entityId);
        }

        return $testInputs;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function evaluateCondition(array $config, \Leek\FilamentWorkflows\Context\WorkflowContext $context): bool
    {
        $conditions = $config['conditions'] ?? [];

        if ($conditions === []) {
            return true;
        }

        $results = [];

        foreach ($conditions as $condition) {
            $left = $context->resolve($condition['left'] ?? '');
            $right = $context->resolve($condition['right'] ?? '');
            $operator = (string)($condition['operator'] ?? 'equals');

            $results[] = $this->evaluateSingleCondition($left, $operator, $right);
        }

        return ($config['logic'] ?? 'and') === 'or'
            ? in_array(true, $results, true)
            : !in_array(false, $results, true);
    }

    protected function evaluateSingleCondition(mixed $fieldValue, string $operator, mixed $comparisonValue): bool
    {
        return match ($operator) {
            'equals', '=' => $fieldValue == $comparisonValue,
            'not_equals', '!=' => $fieldValue != $comparisonValue,
            'strict_equals', '===' => $fieldValue === $comparisonValue,
            'gt', 'greater_than', '>' => (float)$fieldValue > (float)$comparisonValue,
            'gte', 'greater_than_or_equals', '>=' => (float)$fieldValue >= (float)$comparisonValue,
            'lt', 'less_than', '<' => (float)$fieldValue < (float)$comparisonValue,
            'lte', 'less_than_or_equals', '<=' => (float)$fieldValue <= (float)$comparisonValue,
            'contains' => is_string($fieldValue) && str_contains($fieldValue, (string)$comparisonValue),
            'not_contains' => is_string($fieldValue) && !str_contains($fieldValue, (string)$comparisonValue),
            'starts_with' => is_string($fieldValue) && str_starts_with($fieldValue, (string)$comparisonValue),
            'ends_with' => is_string($fieldValue) && str_ends_with($fieldValue, (string)$comparisonValue),
            'in' => in_array($fieldValue, $this->listValue($comparisonValue), false),
            'not_in' => !in_array($fieldValue, $this->listValue($comparisonValue), false),
            'is_empty' => blank($fieldValue),
            'is_not_empty' => filled($fieldValue),
            'is_null' => $fieldValue === null || $fieldValue === '',
            'is_not_null' => $fieldValue !== null && $fieldValue !== '',
            'is_true' => in_array($fieldValue, [true, 1, '1', 'true', 'yes', 'on'], true),
            'is_false' => in_array($fieldValue, [false, 0, '0', 'false', 'no', 'off'], true),
            'matches' => is_string($fieldValue) && is_string($comparisonValue) && preg_match(
                    $comparisonValue,
                    $fieldValue
                ) === 1,
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function buildConditionDescription(array $config, bool $passed): string
    {
        $conditions = $config['conditions'] ?? [];

        if ($conditions === []) {
            return 'Условия не заданы';
        }

        $logic = ($config['logic'] ?? 'and') === 'or' ? ' ИЛИ ' : ' И ';
        $parts = [];

        foreach ($conditions as $condition) {
            $operator = (string)($condition['operator'] ?? 'equals');
            $left = $this->humanConditionValue($condition['left'] ?? '');
            $right = $this->humanConditionValue($condition['right'] ?? '');

            $parts[] = in_array($operator, [
                'is_empty',
                'is_not_empty',
                'is_null',
                'is_not_null',
                'is_true',
                'is_false',
            ], true)
                ? trim($left . ' ' . $this->humanOperator($operator))
                : trim($left . ' ' . $this->humanOperator($operator) . ' ' . $right);
        }

        return implode($logic, $parts) . ' => ' . ($passed ? 'условие выполнено' : 'условие не выполнено');
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function simulateSideEffectAction(
        string $type,
        array $config,
        \Leek\FilamentWorkflows\Context\WorkflowContext $context
    ): array {
        if (str_starts_with($type, 'amocrm_')) {
            return $this->simulateAmoCrmAction($type, $config, $context);
        }

        return parent::simulateSideEffectAction($type, $config, $context);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function simulateAmoCrmAction(
        string $type,
        array $config,
        \Leek\FilamentWorkflows\Context\WorkflowContext $context
    ): array {
        $triggerData = $context->getTriggerData();
        $account = $this->amoAccount($triggerData);
        $entities = [];

        match ($type) {
            'amocrm_create_lead' => $this->appendCreatedAndLinkedEntities(
                $entities,
                'lead',
                $config,
                $triggerData,
                $account
            ),
            'amocrm_create_contact' => $this->appendCreatedAndLinkedEntities(
                $entities,
                'contact',
                $config,
                $triggerData,
                $account
            ),
            'amocrm_create_company' => $this->appendCreatedAndLinkedEntities(
                $entities,
                'company',
                $config,
                $triggerData,
                $account
            ),
            'amocrm_copy_lead',
            'amocrm_change_lead_status' => $this->appendCurrentEntity(
                $entities,
                'lead',
                $config,
                $triggerData,
                $account
            ),
            'amocrm_update_fields',
            'amocrm_update_lead_fields',
            'amocrm_update_contact_fields',
            'amocrm_update_company_fields',
            'amocrm_change_tags',
            'amocrm_create_task',
            'amocrm_add_note',
            'amocrm_start_salesbot',
            'amocrm_stop_salesbot',
            'amocrm_manage_subscription',
            'amocrm_update_task',
            'amocrm_cancel_delayed_action',
            'amocrm_normalize_contact_data',
            'amocrm_add_products',
            'amocrm_remove_products' => $this->appendCurrentEntity(
                $entities,
                (string)($config['target_entity'] ?? $config['entity'] ?? 'lead'),
                $config,
                $triggerData,
                $account
            ),
            'amocrm_find_entity' => $this->appendEntity(
                $entities,
                (string)($config['target_entity'] ?? $config['entity'] ?? 'lead'),
                null,
                $account,
                'Будет искать'
            ),
            'amocrm_link_entity',
            'amocrm_unlink_entity' => $this->appendLinkedEntities($entities, $config, $triggerData, $account),
            default => null,
        };

        return [
            'simulated' => true,
            'action' => $type,
            'description' => 'Тестовый запуск: действие amoCRM не отправлялось во внешний API.',
            'affected_entities' => array_values($entities),
        ];
    }

    /**
     * @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    private function withRealAmoCrmEntities(
        array $output,
        \Leek\FilamentWorkflows\Context\WorkflowContext $context
    ): array {
        if (!empty($output['affected_entities']) || empty($output['entity_type'])) {
            return $output;
        }

        $account = $this->amoAccountFromOutput($output, $context->getTriggerData());
        $entities = [];
        $entityId = $this->numericId($output['entity_id'] ?? null);

        $this->appendEntity(
            $entities,
            (string)$output['entity_type'],
            $entityId,
            $account,
            $this->realActionRole((string)($output['action'] ?? ''))
        );

        $linkedEntity = (string)($output['linked_entity'] ?? '');
        $linkedId = $this->numericId($output['linked_entity_id'] ?? null);

        if ($linkedEntity !== '' && $linkedId) {
            $this->appendEntity($entities, $linkedEntity, $linkedId, $account, 'Связанная сущность');
        }

        $parentEntity = (string)($output['parent_entity'] ?? '');
        $parentId = $this->numericId($output['parent_id'] ?? null);

        if ($parentEntity !== '' && $parentId) {
            $this->appendEntity($entities, $parentEntity, $parentId, $account, 'Родительская сущность');
        }

        $output['affected_entities'] = array_values($entities);

        return $output;
    }

    /**
     * @param array<string, mixed> $output
     * @param array<string, mixed> $triggerData
     * @return array{subdomain: ?string, zone: ?string, account_id: ?int}
     */
    private function amoAccountFromOutput(array $output, array $triggerData): array
    {
        $accountId = $this->numericId($output['account_id'] ?? null);

        if ($accountId) {
            $account = Account::query()->find($accountId);

            if ($account instanceof Account) {
                return [
                    'account_id' => $accountId,
                    'subdomain' => $this->cleanSubdomain($account->subdomain),
                    'zone' => (string)($account->zone ?: 'ru'),
                ];
            }
        }

        return $this->amoAccount($triggerData);
    }

    private function realActionRole(string $action): string
    {
        return [
            'created' => 'Создана',
            'copied' => 'Создана копия',
            'updated' => 'Изменена',
            'tags_changed' => 'Изменены теги',
            'status_changed' => 'Изменён статус',
            'task_created' => 'Создана',
            'note_created' => 'Создано',
            'found' => 'Найдена',
            'linked' => 'Связана',
        ][$action] ?? 'Затронута';
    }

    /**
     * @param array<int, array<string, mixed>> $entities
     * @param array<string, mixed> $config
     * @param array<string, mixed> $triggerData
     * @param array{subdomain: ?string, zone: ?string, account_id: ?int} $account
     */
    private function appendCreatedAndLinkedEntities(
        array &$entities,
        string $createdEntity,
        array $config,
        array $triggerData,
        array $account
    ): void {
        $this->appendEntity($entities, $createdEntity, null, $account, 'Будет создана');

        $target = (string)($config['target_entity'] ?? '');

        if ($target !== '' && $target !== $createdEntity) {
            $this->appendCurrentEntity($entities, $target, $config, $triggerData, $account, 'Будет связана с');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $entities
     * @param array<string, mixed> $config
     * @param array<string, mixed> $triggerData
     * @param array{subdomain: ?string, zone: ?string, account_id: ?int} $account
     */
    private function appendLinkedEntities(array &$entities, array $config, array $triggerData, array $account): void
    {
        $this->appendCurrentEntity(
            $entities,
            (string)($config['target_entity'] ?? 'lead'),
            $config,
            $triggerData,
            $account,
            'Основная сущность'
        );

        $linkedEntity = (string)($config['linked_entity'] ?? '');
        $linkedId = $this->numericId($config['linked_entity_id'] ?? null);

        if ($linkedEntity !== '') {
            $this->appendEntity($entities, $linkedEntity, $linkedId, $account, 'Связанная сущность');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $entities
     * @param array<string, mixed> $config
     * @param array<string, mixed> $triggerData
     * @param array{subdomain: ?string, zone: ?string, account_id: ?int} $account
     */
    private function appendCurrentEntity(
        array &$entities,
        string $entity,
        array $config,
        array $triggerData,
        array $account,
        string $role = 'Применяется к'
    ): void {
        $this->appendEntity(
            $entities,
            $entity,
            $this->currentEntityId($entity, $config, $triggerData),
            $account,
            $role
        );
    }

    /**
     * @param array<int, array<string, mixed>> $entities
     * @param array{subdomain: ?string, zone: ?string, account_id: ?int} $account
     */
    private function appendEntity(array &$entities, string $entity, ?int $id, array $account, string $role): void
    {
        $key = $entity . ':' . ($id ?: 'new') . ':' . $role;

        if (isset($entities[$key])) {
            return;
        }

        $entities[$key] = [
            'role' => $role,
            'type' => $entity,
            'label' => $this->amoEntityLabel($entity),
            'id' => $id,
            'url' => $id ? $this->amoEntityUrl($entity, $id, $account) : null,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $triggerData
     */
    private function currentEntityId(string $entity, array $config, array $triggerData): ?int
    {
        $configuredId = $this->numericId($config['target_entity_id'] ?? $config['entity_id'] ?? null);

        if ($configuredId) {
            return $configuredId;
        }

        $triggerEntity = (string)Arr::get($triggerData, 'entity', '');

        return $this->numericId(Arr::get($triggerData, $entity . '.id'))
            ?: ($triggerEntity === $entity ? $this->numericId(Arr::get($triggerData, 'item.id')) : null);
    }

    private function numericId(mixed $value): ?int
    {
        if (is_numeric($value) && (int)$value > 0) {
            return (int)$value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $triggerData
     * @return array{subdomain: ?string, zone: ?string, account_id: ?int}
     */
    private function amoAccount(array $triggerData): array
    {
        $accountId = $this->numericId(Arr::get($triggerData, 'account.id'));
        $subdomain = $this->cleanSubdomain(
            Arr::get($triggerData, 'account.subdomain')
                ?: Arr::get($triggerData, 'account.domain')
                ?: Arr::get($triggerData, 'subdomain')
        );
        $zone = (string)(Arr::get($triggerData, 'account.zone') ?: Arr::get($triggerData, 'zone') ?: '');

        if (($subdomain === null || $zone === '') && $accountId) {
            $account = Account::query()->find($accountId);
            $subdomain ??= $this->cleanSubdomain($account?->subdomain);
            $zone = $zone !== '' ? $zone : (string)($account?->zone ?: '');
        }

        if ($subdomain === null) {
            $account = $this->workflowAmoAccount();
            $accountId ??= $account?->id;
            $subdomain = $this->cleanSubdomain($account?->subdomain);
            $zone = $zone !== '' ? $zone : (string)($account?->zone ?: '');
        }

        return [
            'account_id' => $accountId,
            'subdomain' => $subdomain,
            'zone' => $zone !== '' ? $zone : 'ru',
        ];
    }

    private function workflowAmoAccount(): ?Account
    {
        $workflowModel = config('filament-workflows.models.workflow', \Leek\FilamentWorkflows\Models\Workflow::class);
        $workflow = $this->currentWorkflowId ? $workflowModel::query()->find($this->currentWorkflowId) : null;
        $userId = $workflow ? $this->numericId(
            $workflow->getAttribute(config('filament-workflows.tenancy.column', 'user_id'))
        ) : null;

        if (!$userId) {
            return null;
        }

        return Account::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->whereNotNull('refresh_token')
            ->latest('id')
            ->first();
    }

    private function cleanSubdomain(mixed $value): ?string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('#^https?://#', '', $value) ?: $value;
        $value = explode('/', $value, 2)[0] ?? $value;

        return explode('.amocrm.', $value, 2)[0] ?: null;
    }

    /**
     * @param array{subdomain: ?string, zone: ?string, account_id: ?int} $account
     */
    private function amoEntityUrl(string $entity, int $id, array $account): ?string
    {
        if (empty($account['subdomain'])) {
            return null;
        }

        $path = [
            'lead' => 'leads/detail',
            'contact' => 'contacts/detail',
            'company' => 'companies/detail',
            'customer' => 'customers/detail',
        ][$entity] ?? null;

        if ($path === null) {
            return null;
        }

        return sprintf('https://%s.amocrm.%s/%s/%d', $account['subdomain'], $account['zone'] ?: 'com', $path, $id);
    }

    private function amoEntityLabel(string $entity): string
    {
        return [
            'lead' => 'Сделка',
            'contact' => 'Контакт',
            'company' => 'Компания',
            'customer' => 'Покупатель',
            'task' => 'Задача',
            'note' => 'Примечание',
        ][$entity] ?? $entity;
    }

    private function humanConditionValue(mixed $value): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return '-';
        }

        return WorkflowTriggerConditionVariableCatalog::label($value, true) ?? $value;
    }

    private function humanOperator(string $operator): string
    {
        return [
            'equals' => 'равно',
            'not_equals' => 'не равно',
            'strict_equals' => 'строго равно',
            'gt' => 'больше',
            'greater_than' => 'больше',
            'gte' => 'больше или равно',
            'greater_than_or_equals' => 'больше или равно',
            'lt' => 'меньше',
            'less_than' => 'меньше',
            'lte' => 'меньше или равно',
            'less_than_or_equals' => 'меньше или равно',
            'contains' => 'содержит',
            'not_contains' => 'не содержит',
            'starts_with' => 'начинается с',
            'ends_with' => 'заканчивается на',
            'in' => 'в списке',
            'not_in' => 'не в списке',
            'is_empty' => 'пусто',
            'is_not_empty' => 'не пусто',
            'is_null' => 'не заполнено',
            'is_not_null' => 'заполнено',
            'is_true' => 'истина',
            'is_false' => 'ложь',
            'matches' => 'соответствует шаблону',
        ][$operator] ?? $operator;
    }

    /**
     * @return array<int, mixed>
     */
    private function listValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;

        if (is_array($decoded)) {
            return $decoded;
        }

        return array_map('trim', explode(',', (string)$value));
    }
}
