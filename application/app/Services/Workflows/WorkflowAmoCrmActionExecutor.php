<?php

namespace App\Services\Workflows;

use App\Models\amoCRM\Field as AmoCrmField;
use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\amoCRM\Client;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use RuntimeException;
use Throwable;

class WorkflowAmoCrmActionExecutor
{
    private bool $captureAmoExchange = false;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $capturedAmoExchange = [];

    public function __construct(
        private readonly WorkflowAmoCrmLoopGuard $loopGuard,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, output?: array<string, mixed>, error?: string}
     */
    public function execute(string $actionType, array $config, ?WorkflowContext $context = null): array
    {
        $client = null;

        try {
            if ($context?->getVariable('_dry_run') || $context?->getVariable('_test_mode')) {
                return $this->dryRun($actionType, $config, $context);
            }

            $config = $this->resolveConfig($config, $context);
            $account = $this->resolveAccount($context);

            if (!$account instanceof Account) {
                return $this->failure('Не найден подключенный аккаунт amoCRM для процесса.');
            }

            // Client refreshes OAuth data in the existing storage. Workflow actions below use v4 HTTP endpoints directly.
            $client = new Client($account);
            $account->refresh();

            $this->captureAmoExchange = (bool)$context?->getVariable('_capture_amo_exchange');
            $this->capturedAmoExchange = [];

            $result = match ($actionType) {
                'amocrm_create_lead' => $this->createEntity($client, $account, 'lead', $config, $context),
                'amocrm_create_contact' => $this->createEntity($client, $account, 'contact', $config, $context),
                'amocrm_create_company' => $this->createEntity($client, $account, 'company', $config, $context),
                'amocrm_copy_lead' => $this->copyLead($client, $account, $config, $context),
                'amocrm_update_fields',
                'amocrm_update_lead_fields',
                'amocrm_update_contact_fields',
                'amocrm_update_company_fields' => $this->updateFields($client, $account, $config, $context),
                'amocrm_create_task' => $this->createTask($client, $account, $config, $context),
                'amocrm_add_note' => $this->addNote($client, $account, $config, $context),
                'amocrm_change_tags' => $this->changeTags($client, $account, $config, $context),
                'amocrm_change_lead_status' => $this->changeLeadStatus($client, $account, $config, $context),
                'amocrm_find_entity' => $this->findEntity($client, $account, $config, $context),
                'amocrm_link_entity' => $this->linkEntity($client, $account, $config, $context),
                'amocrm_unlink_entity' => $this->unlinkEntity($client, $account, $config, $context),
                'amocrm_start_salesbot',
                'amocrm_stop_salesbot',
                'amocrm_manage_subscription',
                'amocrm_update_task',
                'amocrm_cancel_delayed_action',
                'amocrm_normalize_contact_data',
                'amocrm_add_products',
                'amocrm_remove_products' => $this->unsupported($actionType),
                default => $this->failure('Неизвестное amoCRM-действие: ' . $actionType),
            };

            return $this->captureAmoExchange ? $this->withAmoExchange($result, $client) : $result;
        } catch (Throwable $e) {
            $result = $this->failure($e->getMessage());

            return $this->captureAmoExchange ? $this->withAmoExchange($result, $client) : $result;
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, output: array<string, mixed>}
     */
    private function dryRun(string $actionType, array $config, ?WorkflowContext $context): array
    {
        return [
            'success' => true,
            'output' => [
                'dry_run' => true,
                'action' => $actionType,
                'config' => $config,
                'trigger_data' => $context?->getTriggerData() ?? [],
                'message' => 'Тестовый запуск: действие amoCRM не отправлялось во внешний API.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, output?: array<string, mixed>, error?: string}
     */
    private function createEntity(
        Client $client,
        Account $account,
        string $entity,
        array $config,
        ?WorkflowContext $context
    ): array {
        $payload = [
            'name' => (string)($config['name'] ?? $this->defaultName($entity)),
        ];

        if (!empty($config['responsible_user_id'])) {
            $payload['responsible_user_id'] = (int)$config['responsible_user_id'];
        }

        if ($entity === 'lead') {
            if (!empty($config['pipeline_id'])) {
                $payload['pipeline_id'] = (int)$config['pipeline_id'];
            }

            if (!empty($config['status_id'])) {
                $payload['status_id'] = (int)$config['status_id'];
            }
        }

        $customFields = $this->customFieldsPayload($account, $entity, $config['fields'] ?? []);
        if ($customFields !== []) {
            $payload['custom_fields_values'] = $customFields;
        }

        $tags = $this->tagModels($config['tags'] ?? null);
        if ($tags !== []) {
            $payload['_embedded']['tags'] = $tags;
        }

        $body = $this->amoRequest($account, 'POST', '/api/v4/' . $this->entityPlural($entity), [$payload]);
        $entityId = $this->extractEmbeddedEntityId($body, $entity);

        $this->linkCreatedEntityToTarget($account, $entity, $entityId, $config, $context);
        $this->rememberAmoMutation($account, $context, 'amocrm_create_' . $entity, $entity, $entityId, [
            'add_' . $entity,
        ]);

        return $this->successById($action = 'created', $entity, $entityId, $account, [
            'action' => $action,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, output?: array<string, mixed>, error?: string}
     */
    private function copyLead(Client $client, Account $account, array $config, ?WorkflowContext $context): array
    {
        $sourceId = $this->currentEntityId('lead', $context, $config);

        if ($sourceId <= 0) {
            return $this->failure('Не найдена текущая сделка для копирования.');
        }

        $source = $this->amoRequest($account, 'GET', '/api/v4/leads/' . $sourceId);
        $payload = [
            'name' => (string)($config['name'] ?? (($source['name'] ?? 'Сделка') . ' (копия)')),
            'pipeline_id' => (int)($config['pipeline_id'] ?? $source['pipeline_id'] ?? 0) ?: null,
            'status_id' => (int)($config['status_id'] ?? $source['status_id'] ?? 0) ?: null,
            'responsible_user_id' => (int)($config['responsible_user_id'] ?? $source['responsible_user_id'] ?? 0) ?: null,
        ];

        $payload = array_filter($payload, static fn(mixed $value): bool => $value !== null && $value !== '');

        $tags = $this->tagModels($config['tags'] ?? null);
        if ($tags !== []) {
            $payload['_embedded']['tags'] = $tags;
        }

        $body = $this->amoRequest($account, 'POST', '/api/v4/leads', [$payload]);
        $leadId = $this->extractEmbeddedEntityId($body, 'lead');
        $this->rememberAmoMutation($account, $context, 'amocrm_copy_lead', 'lead', $leadId, [
            'add_lead',
        ]);

        return $this->successById('copied', 'lead', $leadId, $account, [
            'source_id' => $sourceId,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, output?: array<string, mixed>, error?: string}
     */
    private function updateFields(Client $client, Account $account, array $config, ?WorkflowContext $context): array
    {
        $entity = (string)($config['target_entity'] ?? $config['entity'] ?? 'lead');
        $entityId = $this->currentEntityId($entity, $context, $config);

        if ($entityId <= 0) {
            return $this->failure('Не найдена текущая сущность amoCRM: ' . $entity);
        }

        $fields = $config['fields'] ?? [];
        $payload = $this->systemFieldsPayload($entity, $fields);
        $customFields = $this->customFieldsPayload($account, $entity, $fields);

        if ($customFields !== []) {
            $payload['custom_fields_values'] = $customFields;
        }

        if ($payload === []) {
            return $this->failure('Не указаны поля для изменения.');
        }

        $this->amoRequest($account, 'PATCH', '/api/v4/' . $this->entityPlural($entity) . '/' . $entityId, $payload);
        $this->rememberAmoMutation($account, $context, 'amocrm_update_fields', $entity, $entityId, [
            'update_' . $entity,
        ]);

        return $this->successById('updated', $entity, $entityId, $account);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, output?: array<string, mixed>, error?: string}
     */
    private function createTask(Client $client, Account $account, array $config, ?WorkflowContext $context): array
    {
        $entity = (string)($config['target_entity'] ?? 'lead');
        $entityId = $this->currentEntityId($entity, $context, $config);

        if ($entityId <= 0) {
            return $this->failure('Не найдена сущность для постановки задачи: ' . $entity);
        }

        $taskType = (int)($config['task_type_id'] ?? 1);
        $payload = [
            'entity_id' => $entityId,
            'entity_type' => $this->entityPlural($entity),
            'task_type_id' => $taskType > 0 ? $taskType : 1,
            'text' => (string)($config['text'] ?? 'Задача'),
            'complete_till' => $this->timestamp($config['complete_till'] ?? null, '+1 hour'),
        ];

        if (!empty($config['responsible_user_id'])) {
            $payload['responsible_user_id'] = (int)$config['responsible_user_id'];
        }

        $body = $this->amoRequest($account, 'POST', '/api/v4/tasks', [$payload]);
        $taskId = $this->extractEmbeddedEntityId($body, 'task');
        $this->rememberAmoMutation($account, $context, 'amocrm_create_task', 'task', $taskId, [
            'add_task',
        ]);

        return $this->successById('task_created', 'task', $taskId, $account, [
            'parent_entity' => $entity,
            'parent_id' => $entityId,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, output?: array<string, mixed>, error?: string}
     */
    private function addNote(Client $client, Account $account, array $config, ?WorkflowContext $context): array
    {
        $entity = (string)($config['target_entity'] ?? 'lead');
        $entityId = $this->currentEntityId($entity, $context, $config);

        if ($entityId <= 0) {
            return $this->failure('Не найдена сущность для примечания: ' . $entity);
        }

        $payload = [
            [
                'note_type' => (bool)($config['is_system'] ?? false) ? 'service_message' : 'common',
                'params' => [
                    'text' => (string)($config['text'] ?? ''),
                ],
            ]
        ];

        $body = $this->amoRequest(
            $account,
            'POST',
            '/api/v4/' . $this->entityPlural($entity) . '/' . $entityId . '/notes',
            $payload
        );
        $noteId = $this->extractEmbeddedEntityId($body, 'note');
        $this->rememberAmoMutation($account, $context, 'amocrm_add_note', $entity, $entityId, [
            'note_' . $entity,
        ]);

        return $this->successById('note_created', 'note', $noteId, $account, [
            'parent_entity' => $entity,
            'parent_id' => $entityId,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, output?: array<string, mixed>, error?: string}
     */
    private function changeTags(Client $client, Account $account, array $config, ?WorkflowContext $context): array
    {
        $entity = (string)($config['target_entity'] ?? 'lead');
        $entityId = $this->currentEntityId($entity, $context, $config);

        if ($entityId <= 0) {
            return $this->failure('Не найдена сущность для изменения тегов: ' . $entity);
        }

        $removeTags = $this->tags($config['tags_to_remove'] ?? null);
        $tags = [];

        if (!(bool)($config['remove_all'] ?? false)) {
            $current = $this->amoRequest($account, 'GET', '/api/v4/' . $this->entityPlural($entity) . '/' . $entityId);
            $tags = $this->extractCurrentTags($current);
        }

        if ($removeTags !== []) {
            $removeLookup = array_flip(array_map('mb_strtolower', $removeTags));
            $tags = array_values(
                array_filter(
                    $tags,
                    static fn(array $tag): bool => !isset($removeLookup[mb_strtolower((string)($tag['name'] ?? ''))])
                )
            );
        }

        $tags = array_merge($tags, $this->tagModels($config['tags_to_add'] ?? null));
        $tags = $this->uniqueTagModels($tags);

        $this->amoRequest($account, 'PATCH', '/api/v4/' . $this->entityPlural($entity) . '/' . $entityId, [
            '_embedded' => [
                'tags' => $tags === [] ? null : $tags,
            ],
        ]);
        $this->rememberAmoMutation($account, $context, 'amocrm_change_tags', $entity, $entityId, [
            'update_' . $entity,
        ]);

        return $this->successById('tags_changed', $entity, $entityId, $account, [
            'added' => $this->tags($config['tags_to_add'] ?? null),
            'removed' => $removeTags,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, output?: array<string, mixed>, error?: string}
     */
    private function changeLeadStatus(Client $client, Account $account, array $config, ?WorkflowContext $context): array
    {
        $leadId = $this->currentEntityId('lead', $context, $config);

        if ($leadId <= 0) {
            return $this->failure('Не найдена текущая сделка для смены статуса.');
        }

        $payload = [];

        if (!empty($config['pipeline_id'])) {
            $payload['pipeline_id'] = (int)$config['pipeline_id'];
        }

        if (!empty($config['status_id'])) {
            $payload['status_id'] = (int)$config['status_id'];
        }

        if ($payload === []) {
            return $this->failure('Не выбраны воронка или статус для сделки.');
        }

        $this->amoRequest($account, 'PATCH', '/api/v4/leads/' . $leadId, $payload);
        $this->rememberAmoMutation($account, $context, 'amocrm_change_lead_status', 'lead', $leadId, [
            'status_lead',
            'update_lead',
        ]);

        return $this->successById('status_changed', 'lead', $leadId, $account, [
            'pipeline_id' => $payload['pipeline_id'] ?? null,
            'status_id' => $payload['status_id'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, output?: array<string, mixed>, error?: string}
     */
    private function findEntity(Client $client, Account $account, array $config, ?WorkflowContext $context): array
    {
        $entity = (string)($config['target_entity'] ?? 'lead');
        $query = $this->firstSearchValue($config['conditions'] ?? []);

        if ($query === null) {
            return $this->failure('Для поиска нужно указать значение условия.');
        }

        $body = $this->amoRequest($account, 'GET', '/api/v4/' . $this->entityPlural($entity), query: [
            'query' => $query,
            'limit' => 1,
        ]);
        $model = Arr::get($body, '_embedded.' . $this->entityPlural($entity) . '.0');
        $found = is_array($model);
        $contextKey = $this->findContextKey($entity, $config, $context);
        $result = [
            'exists' => $found,
            'id' => $found ? ($model['id'] ?? null) : null,
            'type' => $entity,
            'data' => $found ? $model : null,
        ];

        if ($context) {
            $context->setVariable($contextKey, $result);
            $context->setVariable($contextKey . '_id', $result['id']);
            $context->setVariable($contextKey . '_exists', $result['exists']);
        }

        return [
            'success' => true,
            'output' => [
                'action' => 'found',
                'entity_type' => $entity,
                'entity_id' => $result['id'],
                'found' => $found,
                'context_key' => $contextKey,
                'context_masks' => [
                    'id' => '{{' . $contextKey . '.id}}',
                    'exists' => '{{' . $contextKey . '.exists}}',
                    'type' => '{{' . $contextKey . '.type}}',
                ],
                'account_id' => $account->id,
                'query' => $query,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function findContextKey(string $entity, array $config, ?WorkflowContext $context): string
    {
        return $this->nextFindContextKey($entity, $context);
    }

    private function nextFindContextKey(string $entity, ?WorkflowContext $context): string
    {
        $count = 0;

        if ($context && method_exists($context, 'getStepOutputs')) {
            foreach ($context->getStepOutputs() as $output) {
                if (!is_array($output)) {
                    continue;
                }

                if (($output['action'] ?? null) === 'found' && ($output['entity_type'] ?? null) === $entity) {
                    $count++;
                }
            }
        }

        return 'found_' . $this->sanitizeContextKey($entity) . '_' . ($count + 1);
    }

    private function sanitizeContextKey(string $key): string
    {
        $key = preg_replace('/[^a-zA-Z0-9_]/', '_', trim($key)) ?: '';
        $key = trim($key, '_');

        return $key !== '' ? $key : 'found_entity_1';
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, output?: array<string, mixed>, error?: string}
     */
    private function linkEntity(Client $client, Account $account, array $config, ?WorkflowContext $context): array
    {
        $entity = (string)($config['target_entity'] ?? 'lead');
        $entityId = $this->currentEntityId($entity, $context, $config);
        $linkedEntity = (string)($config['linked_entity'] ?? '');
        $linkedId = (int)($config['linked_entity_id'] ?? 0);

        if ($entityId <= 0 || $linkedEntity === '' || $linkedId <= 0) {
            return $this->failure('Для связи сущностей нужна текущая сущность и ID прикрепляемой сущности.');
        }

        $this->amoRequest($account, 'POST', '/api/v4/' . $this->entityPlural($entity) . '/' . $entityId . '/link', [
            [
                'to_entity_id' => $linkedId,
                'to_entity_type' => $this->entityPlural($linkedEntity),
                'metadata' => null,
            ]
        ]);
        $this->rememberAmoMutation($account, $context, 'amocrm_link_entity', $entity, $entityId, [
            'update_' . $entity,
        ]);
        $this->rememberAmoMutation($account, $context, 'amocrm_link_entity', $linkedEntity, $linkedId, [
            'update_' . $linkedEntity,
        ]);

        return $this->successById('linked', $entity, $entityId, $account, [
            'linked_entity' => $linkedEntity,
            'linked_entity_id' => $linkedId,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, output?: array<string, mixed>, error?: string}
     */
    private function unlinkEntity(Client $client, Account $account, array $config, ?WorkflowContext $context): array
    {
        $entity = (string)($config['target_entity'] ?? 'lead');
        $entityId = $this->currentEntityId($entity, $context, $config);
        $linkedEntity = (string)($config['linked_entity'] ?? '');
        $linkedId = (int)($config['linked_entity_id'] ?? 0);

        if ($entityId <= 0 || $linkedEntity === '' || $linkedId <= 0) {
            return $this->failure('Для открепления сущностей нужна текущая сущность и ID прикрепленной сущности.');
        }

        $this->amoRequest($account, 'POST', '/api/v4/' . $this->entityPlural($entity) . '/' . $entityId . '/unlink', [
            [
                'to_entity_id' => $linkedId,
                'to_entity_type' => $this->entityPlural($linkedEntity),
            ]
        ]);
        $this->rememberAmoMutation($account, $context, 'amocrm_unlink_entity', $entity, $entityId, [
            'update_' . $entity,
        ]);
        $this->rememberAmoMutation($account, $context, 'amocrm_unlink_entity', $linkedEntity, $linkedId, [
            'update_' . $linkedEntity,
        ]);

        return $this->successById('unlinked', $entity, $entityId, $account, [
            'linked_entity' => $linkedEntity,
            'linked_entity_id' => $linkedId,
        ]);
    }

    private function resolveAccount(?WorkflowContext $context): ?Account
    {
        $triggerAccountId = (int)Arr::get($context?->getTriggerData() ?? [], 'account.id');
        $workflow = $context?->getWorkflowId() ? Workflow::query()->find($context->getWorkflowId()) : null;
        $workflowUserId = (int)($workflow?->{config('filament-workflows.tenancy.column', 'user_id')} ?? 0);

        if ($triggerAccountId > 0) {
            $query = Account::query()->whereKey($triggerAccountId)->where('active', true);

            if ($workflowUserId > 0) {
                $query->where('user_id', $workflowUserId);
            }

            return $query->first();
        }

        $userId = $workflowUserId ?: (int)($context?->getTriggeredBy() ?? 0) ?: (int)Auth::id();

        if ($userId <= 0) {
            return null;
        }

        return Account::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->whereNotNull('refresh_token')
            ->latest('id')
            ->first();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function currentEntityId(string $entity, ?WorkflowContext $context, array $config = []): int
    {
        $configuredId = $this->numericId($config['target_entity_id'] ?? $config['entity_id'] ?? null);

        if ($configuredId > 0) {
            return $configuredId;
        }

        $data = $context?->getTriggerData() ?? [];
        $triggerEntity = (string)Arr::get($data, 'entity', '');
        $triggerId = $this->numericId(Arr::get($data, $entity . '.id'))
            ?: ($triggerEntity === $entity ? $this->numericId(Arr::get($data, 'item.id')) : 0);

        if ($triggerId > 0) {
            return $triggerId;
        }

        return $this->previousStepEntityId($entity, $context);
    }

    private function previousStepEntityId(string $entity, ?WorkflowContext $context): int
    {
        if (!$context || !method_exists($context, 'getStepOutputs')) {
            return 0;
        }

        $outputs = array_reverse($context->getStepOutputs(), true);

        foreach ($outputs as $output) {
            $entityId = $this->entityIdFromStepOutput($entity, $output);

            if ($entityId > 0) {
                return $entityId;
            }
        }

        return 0;
    }

    private function entityIdFromStepOutput(string $entity, mixed $output): int
    {
        if (!is_array($output)) {
            return 0;
        }

        $outputEntity = (string)($output['entity_type'] ?? $output['entity'] ?? '');

        if ($outputEntity === $entity) {
            return $this->numericId($output['entity_id'] ?? $output['id'] ?? null);
        }

        foreach ((array)($output['affected_entities'] ?? []) as $affectedEntity) {
            if (!is_array($affectedEntity)) {
                continue;
            }

            $affectedType = (string)($affectedEntity['entity_type'] ?? $affectedEntity['type'] ?? '');

            if ($affectedType === $entity) {
                return $this->numericId($affectedEntity['entity_id'] ?? $affectedEntity['id'] ?? null);
            }
        }

        foreach (['created_entities', 'linked_entities', 'entities'] as $key) {
            foreach ((array)($output[$key] ?? []) as $nestedEntity) {
                if (!is_array($nestedEntity)) {
                    continue;
                }

                $nestedType = (string)($nestedEntity['entity_type'] ?? $nestedEntity['type'] ?? '');

                if ($nestedType === $entity) {
                    return $this->numericId($nestedEntity['entity_id'] ?? $nestedEntity['id'] ?? null);
                }
            }
        }

        return 0;
    }

    private function numericId(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_numeric($value)) {
            return max(0, (int)$value);
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function resolveConfig(array $config, ?WorkflowContext $context): array
    {
        return $context ? $context->resolve($config) : $config;
    }

    /**
     * @param array<int, array<string, mixed>>|mixed $fields
     * @return array<int, array<string, mixed>>
     */
    private function customFieldsPayload(Account $account, string $entity, mixed $fields): array
    {
        $payload = [];

        foreach ((array)$fields as $field) {
            $fieldId = (string)($field['field'] ?? '');

            if ($fieldId === '' || str_starts_with($fieldId, 'system:')) {
                continue;
            }

            $value = $field['value'] ?? null;
            $amoField = $this->field($account, $entity, $fieldId);
            $fieldPayload = [];

            if (is_numeric($amoField?->field_id ?: $fieldId)) {
                $fieldPayload['field_id'] = (int)($amoField?->field_id ?: $fieldId);
            } elseif (filled($amoField?->code)) {
                $fieldPayload['field_code'] = (string)$amoField->code;
            } else {
                continue;
            }

            $fieldPayload['values'] = $this->customFieldValuesPayload($value);

            $payload[] = $fieldPayload;
        }

        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>>|mixed $fields
     * @return array<string, mixed>
     */
    private function systemFieldsPayload(string $entity, mixed $fields): array
    {
        $allowed = $this->systemFieldsForEntity($entity);
        $payload = [];

        foreach ((array)$fields as $field) {
            $fieldKey = (string)($field['field'] ?? '');

            if (!str_starts_with($fieldKey, 'system:')) {
                continue;
            }

            $fieldName = substr($fieldKey, 7);

            if (!in_array($fieldName, $allowed, true)) {
                continue;
            }

            $payload[$fieldName] = $this->systemFieldValue($fieldName, $field['value'] ?? null);
        }

        return $payload;
    }

    /**
     * @return array<int, string>
     */
    private function systemFieldsForEntity(string $entity): array
    {
        return match ($entity) {
            'lead' => ['name', 'price', 'responsible_user_id', 'pipeline_id', 'status_id', 'closed_at', 'loss_reason_id'],
            'contact' => ['name', 'first_name', 'last_name', 'responsible_user_id'],
            'company' => ['name', 'responsible_user_id'],
            'customer' => ['name', 'next_price', 'responsible_user_id'],
            default => [],
        };
    }

    private function systemFieldValue(string $fieldName, mixed $value): mixed
    {
        if ($fieldName === 'closed_at' && !is_numeric($value)) {
            $timestamp = strtotime((string)$value);

            return $timestamp !== false ? $timestamp : 0;
        }

        if (in_array($fieldName, [
            'price',
            'next_price',
            'responsible_user_id',
            'pipeline_id',
            'status_id',
            'closed_at',
            'loss_reason_id',
        ], true)) {
            return (int)$value;
        }

        return $value;
    }

    /**
     * @return array<int, array{value: mixed}>
     */
    private function customFieldValuesPayload(mixed $value): array
    {
        if (!is_array($value)) {
            return [['value' => $value]];
        }

        $values = [];

        foreach ($value as $item) {
            $values[] = ['value' => is_array($item) ? ($item['value'] ?? $item) : $item];
        }

        return $values;
    }

    private function field(Account $account, string $entity, string $field): ?AmoCrmField
    {
        return AmoCrmField::query()
            ->where('user_id', $account->user_id)
            ->where('active', true)
            ->where('entity_type', $this->fieldEntityType($entity))
            ->where(function ($query) use ($field): void {
                $query->where('field_id', $field)->orWhere('name', $field)->orWhere('code', $field);
            })
            ->first();
    }

    private function fieldEntityType(string $entity): string
    {
        return match ($entity) {
            'lead' => 'leads',
            'contact' => 'contacts',
            'company' => 'companies',
            'customer' => 'customers',
            default => $entity,
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function linkCreatedEntityToTarget(
        Account $account,
        string $createdEntity,
        int $createdEntityId,
        array $config,
        ?WorkflowContext $context
    ): void {
        $target = (string)($config['target_entity'] ?? '');

        if ($createdEntityId <= 0 || $target === '' || $target === $createdEntity) {
            return;
        }

        $targetId = $this->currentEntityId($target, $context, $config);

        if ($targetId <= 0) {
            return;
        }

        $this->amoRequest($account, 'POST', '/api/v4/' . $this->entityPlural($target) . '/' . $targetId . '/link', [
            [
                'to_entity_id' => $createdEntityId,
                'to_entity_type' => $this->entityPlural($createdEntity),
                'metadata' => $createdEntity === 'contact' ? ['is_main' => true] : null,
            ]
        ]);
        $this->rememberAmoMutation($account, $context, 'amocrm_create_' . $createdEntity, $target, $targetId, [
            'update_' . $target,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tagModels(mixed $tags): array
    {
        return array_map(static fn(string $tag): array => ['name' => $tag], $this->tags($tags));
    }

    /**
     * @param array<int, array<string, mixed>> $tags
     * @return array<int, array<string, mixed>>
     */
    private function uniqueTagModels(array $tags): array
    {
        $unique = [];

        foreach ($tags as $tag) {
            $name = trim((string)($tag['name'] ?? ''));
            $id = (int)($tag['id'] ?? 0);

            if ($id <= 0 && $name === '') {
                continue;
            }

            $key = $id > 0 ? 'id:' . $id : 'name:' . mb_strtolower($name);
            $unique[$key] = $id > 0 ? ['id' => $id] : ['name' => $name];
        }

        return array_values($unique);
    }

    /**
     * @param array<string, mixed> $entity
     * @return array<int, array<string, mixed>>
     */
    private function extractCurrentTags(array $entity): array
    {
        $tags = Arr::get($entity, '_embedded.tags', []);

        return is_array($tags) ? $this->uniqueTagModels($tags) : [];
    }

    private function entityPlural(string $entity): string
    {
        return match ($entity) {
            'lead' => 'leads',
            'contact' => 'contacts',
            'company' => 'companies',
            'customer' => 'customers',
            'task' => 'tasks',
            'note' => 'notes',
            default => throw new RuntimeException('Неподдержанная сущность amoCRM: ' . $entity),
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function extractEmbeddedEntityId(array $body, string $entity): int
    {
        return (int)(
        Arr::get($body, '_embedded.' . $this->entityPlural($entity) . '.0.id')
            ?: Arr::get($body, 'id')
            ?: 0
        );
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function amoRequest(
        Account $account,
        string $method,
        string $path,
        ?array $payload = null,
        array $query = []
    ): array {
        $method = strtoupper($method);
        $url = $this->amoBaseUrl($account) . $path;
        $options = [];

        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($payload !== null) {
            $options['json'] = $payload;
        }

        $response = Http::withToken((string)$account->access_token)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->send($method, $url, $options);

        $body = $this->responseBody($response);
        $this->captureAmoRequest($method, $url, $query, $payload, $response, $body);

        if ($response->failed()) {
            throw new RuntimeException($this->amoErrorMessage($method, $path, $response, $body));
        }

        return is_array($body) ? $body : [];
    }

    private function amoBaseUrl(Account $account): string
    {
        $endpoint = trim((string)($account->endpoint ?? ''));

        if ($endpoint !== '') {
            return rtrim($endpoint, '/');
        }

        $zone = trim((string)($account->zone ?: 'ru'));
        $domain = match ($zone) {
            'com' => 'amocrm.com',
            'ru' => 'amocrm.ru',
            default => 'amocrm.' . $zone,
        };

        return 'https://' . trim((string)$account->subdomain) . '.' . $domain;
    }

    private function responseBody(Response $response): mixed
    {
        try {
            $json = $response->json();

            if ($json !== null) {
                return $json;
            }
        } catch (Throwable) {
            //
        }

        return $response->body();
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $payload
     */
    private function captureAmoRequest(
        string $method,
        string $url,
        array $query,
        ?array $payload,
        Response $response,
        mixed $body
    ): void {
        if (!$this->captureAmoExchange) {
            return;
        }

        $this->capturedAmoExchange[] = [
            'request' => [
                'method' => $method,
                'url' => $this->safeAmoUrl($url),
                'query' => $query === [] ? null : $query,
                'body' => $payload,
            ],
            'response' => [
                'code' => $response->status(),
                'body' => $body,
                'error' => $response->failed() ? $response->reason() : null,
            ],
        ];
    }

    private function safeAmoUrl(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return $url;
        }

        $safe = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        $safe .= isset($parts['path']) ? $parts['path'] : '';

        return $safe;
    }

    private function amoErrorMessage(string $method, string $path, Response $response, mixed $body): string
    {
        $detail = is_array($body)
            ? (Arr::get($body, 'detail') ?: Arr::get($body, 'title') ?: Arr::get($body, 'message'))
            : null;

        return trim(
            sprintf(
                'amoCRM API v4 вернул ошибку %s на %s %s%s',
                $response->status(),
                $method,
                $path,
                $detail ? ': ' . $detail : ''
            )
        );
    }

    /**
     * @return array<int, string>
     */
    private function tags(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string)$value))));
    }

    /**
     * @param array<int, array<string, mixed>>|mixed $conditions
     */
    private function firstSearchValue(mixed $conditions): ?string
    {
        foreach ((array)$conditions as $condition) {
            $value = trim((string)($condition['value'] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function timestamp(mixed $value, string $fallback): int
    {
        $value = trim((string)$value);

        if ($value === '') {
            $value = $fallback;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        return strtotime($value) ?: strtotime($fallback);
    }

    private function defaultName(string $entity): string
    {
        return match ($entity) {
            'lead' => 'Новая сделка',
            'contact' => 'Новый контакт',
            'company' => 'Новая компания',
            default => 'Новая сущность',
        };
    }

    /**
     * @param array{success: bool, output?: array<string, mixed>, error?: string} $result
     * @return array{success: bool, output?: array<string, mixed>, error?: string}
     */
    private function withAmoExchange(array $result, ?Client $client): array
    {
        $queries = array_merge(
            $client?->getCapturedWorkflowQueries() ?? [],
            $this->capturedAmoExchange
        );

        if ($queries === []) {
            return $result;
        }

        $result['output'] ??= [];
        $result['output']['amo_exchange'] = $queries;

        return $result;
    }

    /**
     * @return array{success: bool, output: array<string, mixed>}
     */
    private function successById(
        string $action,
        string $entity,
        int $entityId,
        Account $account,
        array $extra = []
    ): array {
        return [
            'success' => true,
            'output' => array_merge([
                'action' => $action,
                'entity_type' => $entity,
                'entity_id' => $entityId > 0 ? $entityId : null,
                'account_id' => $account->id,
            ], $extra),
        ];
    }

    /**
     * @param array<int, string> $events
     */
    private function rememberAmoMutation(
        Account $account,
        ?WorkflowContext $context,
        string $actionType,
        string $entity,
        int $entityId,
        array $events
    ): void {
        $this->loopGuard->rememberMutation($account, $context, $actionType, $entity, $entityId, $events);
    }

    /**
     * @return array{success: bool, error: string}
     */
    private function unsupported(string $actionType, ?string $message = null): array
    {
        return $this->failure($message ?? 'Действие ' . $actionType . ' ещё не подключено к amoCRM API.');
    }

    /**
     * @return array{success: bool, error: string}
     */
    private function failure(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
        ];
    }
}
