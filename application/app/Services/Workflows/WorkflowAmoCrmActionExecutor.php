<?php

namespace App\Services\Workflows;

use App\Jobs\Distribution\ResponsibleSend as DistributionResponsibleSend;
use App\Models\amoCRM\Field as AmoCrmField;
use App\Models\Core\Account;
use App\Models\Integrations\Distribution\Setting as DistributionSetting;
use App\Models\Integrations\Distribution\Transaction as DistributionTransaction;
use App\Models\User;
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
        $this->captureAmoExchange = true;
        $this->capturedAmoExchange = [];

        try {
            $config = $this->normalizeEntitySource($config);

            if (($context?->getVariable('_dry_run') || $context?->getVariable('_test_mode')) && !in_array($actionType, ['amocrm_query_leads', 'amocrm_get_contact', 'amocrm_read', 'amocrm_contact_leads'], true)) {
                return $this->dryRun($actionType, $config, $context);
            }

            $config = $this->resolveConfig($config, $context);
            $account = $this->resolveAccount($context);

            if (!$account instanceof Account) {
                return $this->failure('Не найден подключенный аккаунт amoCRM для процесса.');
            }

            // Client refreshes OAuth data in the existing storage. Workflow actions below use v4 HTTP endpoints directly.
            $client = new Client($account);
            $client->startWorkflowQueryCapture();
            $account->refresh();

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
                'amocrm_distribution_queue' => $this->distributeLead($client, $account, $config, $context),
                'amocrm_find_entity' => $this->findEntity($client, $account, $config, $context),
                'amocrm_query_leads' => $this->queryLeads($account, $config),
                'amocrm_get_contact' => $this->getContact($account, $config, $context),
                'amocrm_contact_leads' => $this->contactLeads($account, $config),
                'amocrm_read' => $this->readAmo($account, $config),
                'amocrm_link_entity' => $this->linkEntity($client, $account, $config, $context),
                'amocrm_unlink_entity' => $this->unlinkEntity($client, $account, $config, $context),
                'amocrm_start_salesbot' => $this->startSalesbot($account, $config, $context),
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
            if ($e instanceof \InvalidArgumentException) $result['retryable'] = false;

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
            if (isset($config['price']) && $config['price'] !== '') {
                if (!is_numeric($config['price']) || (float) $config['price'] < 0) {
                    throw new \InvalidArgumentException('Бюджет сделки должен быть неотрицательным числом.');
                }
                $payload['price'] = (float) $config['price'];
            }
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

        if ($entity === 'contact') {
            $existingContact = $this->findExistingContactBeforeCreate($account, $payload, $config);

            if ($existingContact !== null) {
                $entityId = (int)($existingContact['id'] ?? 0);

                $this->linkCreatedEntityToTarget($account, $entity, $entityId, $config, $context);

                return $this->successById('found_existing', $entity, $entityId, $account, [
                    'deduplicated' => true,
                    'search' => $existingContact['search'] ?? null,
                    'create_request' => ['method' => 'POST', 'path' => '/api/v4/contacts', 'body' => [$payload], 'sent' => false],
                ]);
            }
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
        $entity = $this->currentTargetEntity(
            (string)($config['target_entity'] ?? $config['entity'] ?? 'lead'),
            ['lead', 'contact', 'company', 'customer'],
            $context,
            $config,
        );
        $entityId = $this->currentEntityId($entity, $context, $config);

        if ($entityId <= 0) {
            return $this->failure('Не найдена текущая сущность amoCRM: ' . $entity);
        }

        if (($config['body_mode'] ?? '') === 'json') {
            $payload = WorkflowJsonBody::parse($config['json_body'] ?? null);
            unset($payload['id']);
        } else {
            $fields = $config['fields'] ?? [];
            foreach (($config['standard_fields'] ?? []) as $name => $value) {
                if ($value !== null && $value !== '') $fields[] = ['field' => 'system:'.$name, 'value' => $value];
            }
            $payload = $this->systemFieldsPayload($entity, $fields);
            $customFields = $this->customFieldsPayload($account, $entity, $fields);
            if ($customFields !== []) {
                $payload['custom_fields_values'] = $customFields;
            }
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
        $json = ($config['body_mode'] ?? '') === 'json' ? WorkflowJsonBody::parse($config['json_body'] ?? null) : null;
        if ($json !== null) {
            $entityTypes = ['leads' => 'lead', 'contacts' => 'contact', 'companies' => 'company', 'customers' => 'customer'];
            if (!isset($entityTypes[$json['entity_type'] ?? ''])) return $this->failure('Укажите entity_type: leads, contacts, companies или customers.');
            $config = $json + [
                'entity_source' => 'manual',
                'target_entity' => $entityTypes[$json['entity_type']],
                'target_entity_id' => $json['entity_id'] ?? null,
            ];
        }
        $entity = (string)($config['target_entity'] ?? 'lead');
        $entityId = $this->currentEntityId($entity, $context, $config);

        if ($entityId <= 0) {
            return $this->failure('Не найдена сущность для постановки задачи: ' . $entity);
        }

        if (!in_array($entity, ['lead', 'contact', 'company', 'customer'], true)) return $this->failure('Неизвестный тип сущности для задачи.');
        $taskType = (int)($config['task_type_id'] ?? 1);
        if ($taskType < 1 || trim((string)($config['text'] ?? '')) === '') return $this->failure('Укажите тип и текст задачи.');
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

        if ($json !== null) $payload = array_replace($json, $payload);

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
        $entity = $this->currentTargetEntity(
            (string)($config['target_entity'] ?? 'lead'),
            ['lead', 'contact', 'company', 'customer'],
            $context,
            $config,
        );
        $entityId = $this->currentEntityId($entity, $context, $config);

        if ($entityId <= 0) {
            return $this->failure('Не найдена сущность для примечания: ' . $entity);
        }

        $text = trim((string)($config['text'] ?? ''));

        if ($text === '') {
            return $this->failure('Не заполнен текст примечания.');
        }

        $params = ['text' => $text];

        if ((bool)($config['is_system'] ?? false)) {
            $params['service'] = 'Clever';
        }

        $payload = [
            [
                'note_type' => (bool)($config['is_system'] ?? false) ? 'service_message' : 'common',
                'params' => $params,
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
        $entity = $this->currentTargetEntity(
            (string)($config['target_entity'] ?? 'lead'),
            ['lead', 'contact', 'company', 'customer'],
            $context,
            $config,
        );
        $entityId = $this->currentEntityId($entity, $context, $config);

        if ($entityId <= 0) {
            return $this->failure('Не найдена сущность для изменения тегов: ' . $entity);
        }

        $removeTags = $this->tags($config['tags_to_remove'] ?? null);
        $addTags = $this->tags($config['tags_to_add'] ?? null);
        $addLookup = array_flip(array_map('mb_strtolower', $addTags));
        $removeTags = array_values(array_filter(
            $removeTags,
            static fn(string $tag): bool => !isset($addLookup[mb_strtolower($tag)])
        ));

        if ((bool)($config['remove_all'] ?? false)) {
            $payload = ['_embedded' => ['tags' => $addTags === [] ? null : $this->tagModels($addTags)]];
        } else {
            $payload = [];
            if ($addTags !== []) $payload['tags_to_add'] = $this->tagModels($addTags);
            if ($removeTags !== []) $payload['tags_to_delete'] = $this->tagModels($removeTags);
        }

        if ($payload !== []) {
            $this->amoRequest($account, 'PATCH', '/api/v4/' . $this->entityPlural($entity) . '/' . $entityId, $payload);
        }
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

        foreach (['pipeline_id', 'status_id'] as $field) {
            if (filled($config[$field] ?? null) && (!is_numeric($config[$field]) || (int)$config[$field] <= 0)) {
                return $this->failure('Воронка и статус должны содержать ID из списка или результат выражения.');
            }
        }

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
    private function distributeLead(Client $client, Account $account, array $config, ?WorkflowContext $context): array
    {
        $leadId = $this->currentEntityId('lead', $context, $config);

        if ($leadId <= 0) {
            return $this->failure('Не найдена текущая сделка для распределения.');
        }

        $queueKey = trim((string)($config['distribution_queue_uuid'] ?? $config['queue_uuid'] ?? ''));

        if ($queueKey === '') {
            return $this->failure('Не выбрана очередь распределения.');
        }

        if (empty($config['pipeline_id']) || empty($config['status_id'])) {
            return $this->failure('Выберите воронку и статус для сделки.');
        }

        $resolvedQueue = $this->resolveDistributionQueue($account, $queueKey);

        if ($resolvedQueue === null) {
            return $this->failure('Очередь распределения не найдена.');
        }

        [$setting, $queue, $templateIndex, $queueUuid] = $resolvedQueue;

        $payload = [
            'pipeline_id' => (int)$config['pipeline_id'],
            'status_id' => (int)$config['status_id'],
        ];

        $this->amoRequest($account, 'PATCH', '/api/v4/leads/' . $leadId, $payload);
        $this->rememberAmoMutation($account, $context, 'amocrm_distribution_queue', 'lead', $leadId, [
            'status_lead',
            'update_lead',
        ]);

        $body = [
            'leads' => [
                'status' => [[
                    'id' => $leadId,
                    'pipeline_id' => $payload['pipeline_id'],
                    'status_id' => $payload['status_id'],
                ]],
            ],
            'workflow' => [
                'id' => $context?->getWorkflowId(),
                'action' => 'amocrm_distribution_queue',
            ],
        ];
        $bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $eventKey = hash('sha256', implode('|', [
            (string)$account->user_id,
            (string)$setting->id,
            (string)($queueUuid ?? $templateIndex ?? $queueKey),
            (string)$leadId,
            (string)$bodyJson,
        ]));

        $transaction = DistributionTransaction::query()->firstOrCreate([
            'user_id' => $account->user_id,
            'event_key' => $eventKey,
        ], [
            'lead_id' => $leadId,
            'body' => $bodyJson,
            'type' => $queue['strategy'] ?? null,
            'template' => $templateIndex,
            'queue_uuid' => $queueUuid,
            'distribution_setting_id' => $setting->id,
            'schedule' => ($queue['schedule'] ?? 'schedule_no') === 'schedule_yes',
            'status' => false,
        ]);

        if ($transaction->wasRecentlyCreated) {
            DistributionResponsibleSend::dispatch($transaction->id, $setting->id, $account->user_id);
        }

        return $this->successById('distributed', 'lead', $leadId, $account, [
            'pipeline_id' => $payload['pipeline_id'],
            'status_id' => $payload['status_id'],
            'queue_uuid' => $queueUuid,
            'template' => $templateIndex,
            'transaction_id' => $transaction->id,
            'queued' => $transaction->wasRecentlyCreated,
            'duplicate' => !$transaction->wasRecentlyCreated,
        ]);
    }

    /**
     * @return array{DistributionSetting, array<string, mixed>, int, string|null}|null
     */
    private function resolveDistributionQueue(Account $account, string $queueKey): ?array
    {
        $setting = DistributionSetting::query()
            ->where('user_id', $account->user_id)
            ->latest('id')
            ->first();

        if (!$setting instanceof DistributionSetting) {
            return null;
        }

        $settings = json_decode($setting->settings ?? '[]', true);

        if (!is_array($settings)) {
            return null;
        }

        if (ctype_digit($queueKey)) {
            $index = (int)$queueKey;

            if (array_key_exists($index, $settings) && is_array($settings[$index])) {
                $queue = $settings[$index];

                return [$setting, $queue, $index, $queue['queue_uuid'] ?? null];
            }
        }

        foreach ($settings as $index => $queue) {
            if (!is_array($queue)) {
                continue;
            }

            if (($queue['queue_uuid'] ?? null) === $queueKey) {
                return [$setting, $queue, (int)$index, $queueKey];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, output?: array<string, mixed>, error?: string}
     */
    private function findEntity(Client $client, Account $account, array $config, ?WorkflowContext $context): array
    {
        $entity = (string)($config['target_entity'] ?? 'lead');
        $search = $this->firstSearchCondition($config['conditions'] ?? []);
        $query = $search['value'] ?? null;

        if ($query === null) {
            return $this->failure('Для поиска нужно указать значение условия.');
        }

        $model = $this->findEntityByQuery($account, $entity, $query);
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
                'search' => [
                    'entity' => $entity,
                    'entity_label' => $this->entityLabel($entity),
                    'field' => $search['field'] ?? null,
                    'field_label' => $this->amoSearchFieldLabel($account, $entity, $search['field'] ?? null),
                    'operator' => $search['operator'] ?? null,
                    'value' => $query,
                ],
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
        $entity = $this->currentTargetEntity(
            (string)($config['target_entity'] ?? 'lead'),
            ['lead', 'contact', 'company', 'customer'],
            $context,
            $config,
        );
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
        $entity = $this->currentTargetEntity(
            (string)($config['target_entity'] ?? 'lead'),
            ['lead', 'contact', 'company', 'customer'],
            $context,
            $config,
        );
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

    private function startSalesbot(Account $account, array $config, ?WorkflowContext $context = null): array
    {
        $botId = filter_var($config['bot_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $entity = $this->currentTargetEntity(
            (string)($config['target_entity'] ?? 'leads'),
            ['lead', 'contact', 'customer'],
            $context,
            $config,
        );
        $entityId = $this->currentEntityId($entity, $context, $config);
        $entity = $this->entityPlural($entity);
        if (!$botId || !$entityId) throw new \InvalidArgumentException('Укажите корректные ID Salesbot и сущности.');
        if (!in_array($entity, ['leads', 'contacts', 'customers'], true)) {
            throw new \InvalidArgumentException('Salesbot можно запустить для сделки, контакта или покупателя.');
        }

        $this->amoRequest($account, 'POST', '/api/v4/bots/'.$botId.'/run', [
            'entity_id' => $entityId, 'entity_type' => $entity,
        ], expectedStatus: 202);

        return ['success' => true, 'output' => [
            'bot_id' => $botId, 'entity_id' => $entityId, 'entity_type' => $entity, 'status' => 'accepted',
        ]];
    }

    private function contactLeads(Account $account, array $config): array
    {
        $id = fn ($value) => filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
        $contactId = $id($config['contact_id'] ?? null);
        if (($config['source'] ?? 'lead') === 'lead') {
            $leadId = $id($config['lead_id'] ?? null);
            if (!$leadId) throw new RuntimeException('Укажите ID исходной сделки.');
            $lead = $this->amoRequest($account, 'GET', '/api/v4/leads/'.$leadId, query:['with'=>'contacts']);
            $contacts = $lead['_embedded']['contacts'] ?? [];
            $main = collect($contacts)->firstWhere('is_main', true) ?? ($contacts[0] ?? null);
            $contactId = $id($main['id'] ?? null);
            if (!$contactId) return ['success'=>true,'output'=>['items'=>[],'count'=>0,'contact_id'=>null,'has_more'=>false,'message'=>'У исходной сделки нет доступного контакта.']];
        }
        if (!$contactId) throw new RuntimeException('Укажите ID контакта.');
        $contact = $this->amoRequest($account, 'GET', '/api/v4/contacts/'.$contactId, query:['with'=>'leads']);
        $ids = array_values(array_unique(array_filter(array_map(fn ($lead) => $id($lead['id'] ?? null), $contact['_embedded']['leads'] ?? []))));
        if (count($ids) > 5000) throw new RuntimeException('У контакта более 5000 сделок. Нужна обработка частями; неполный список не возвращён.');
        $exclude = $id($config['exclude_lead_id'] ?? null);
        if (filled($config['exclude_lead_id'] ?? null) && !$exclude) throw new RuntimeException('Некорректный ID исключаемой сделки.');
        $ids = array_values(array_filter($ids, fn ($leadId) => $leadId !== $exclude));
        $items = [];
        foreach (array_chunk($ids, 250) as $batch) {
            $body = $this->amoRequest($account, 'GET', '/api/v4/leads', query:['filter'=>['id'=>$batch], 'limit'=>250, 'page'=>1]);
            if (filled($body['_links']['next']['href'] ?? null)) throw new RuntimeException('amoCRM вернула неполную страницу сделок. Список не обработан.');
            foreach ($body['_embedded']['leads'] ?? [] as $lead) {
                if (in_array((int)($lead['id'] ?? 0), $batch, true)) $items[$lead['id']] = $lead;
            }
        }
        return ['success'=>true,'output'=>['items'=>array_values($items),'count'=>count($items),'contact_id'=>$contactId,'has_more'=>false]];
    }

    private function queryLeads(Account $account, array $config): array
    {
        $query = WorkflowLeadQuery::build($config);
        $body = $this->amoRequest($account, 'GET', '/api/v4/leads', query: $query);
        $items = array_values($body['_embedded']['leads'] ?? []);
        $hasMore = filled($body['_links']['next']['href'] ?? null);

        return ['success' => true, 'output' => [
            'items' => $items, 'count' => count($items), 'page' => $query['page'],
            'has_more' => $hasMore, 'next_page' => $hasMore ? $query['page'] + 1 : null,
            'request' => ['method' => 'GET', 'path' => '/api/v4/leads', 'query' => $query],
        ]];
    }

    private function getContact(Account $account, array $config, ?WorkflowContext $context): array
    {
        $contactId = $this->currentEntityId('contact', $context, $config);

        if ($contactId <= 0) {
            return $this->failure('Не найден ID контакта amoCRM.');
        }

        $query = ['with' => 'leads'];
        $contact = $this->amoRequest($account, 'GET', '/api/v4/contacts/' . $contactId, query: $query);

        return ['success' => true, 'output' => array_merge($contact, [
            'contact' => $contact,
            'data' => $contact,
            'entity_id' => $contactId,
            'entity_type' => 'contact',
            'request' => [
                'method' => 'GET',
                'path' => '/api/v4/contacts/' . $contactId,
                'query' => $query,
            ],
        ])];
    }

    private function resolveAccount(?WorkflowContext $context): ?Account
    {
        $triggerAccountId = (int)Arr::get($context?->getTriggerData() ?? [], 'account.id');
        $workflow = $context?->getWorkflowId() ? Workflow::query()->find($context->getWorkflowId()) : null;
        $workflowUserId = (int)($workflow?->{config('filament-workflows.tenancy.column', 'user_id')} ?? 0);
        $userId = $workflowUserId ?: (int)($context?->getTriggeredBy() ?? 0) ?: (int)Auth::id();

        if ($userId <= 0) return null;

        if ($triggerAccountId > 0) {
            $query = Account::query()->whereKey($triggerAccountId)->where('active', true)->where('user_id', $userId);

            return $query->first();
        }

        $account = User::query()->find($userId)?->resolveAmoAccountForWidget('workflows');

        return $account instanceof Account
            && (bool) $account->active
            && filled($account->refresh_token)
            ? $account
            : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function normalizeEntitySource(array $config): array
    {
        if (in_array($config['entity_source'] ?? null, ['context', 'manual'], true)) {
            return $config;
        }

        $configuredId = $config['target_entity_id'] ?? $config['entity_id'] ?? null;
        $config['entity_source'] = filled($configuredId) ? 'manual' : 'context';

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function entitySource(array $config): string
    {
        if (in_array($config['entity_source'] ?? null, ['context', 'manual'], true)) {
            return $config['entity_source'];
        }

        return filled($config['target_entity_id'] ?? $config['entity_id'] ?? null) ? 'manual' : 'context';
    }

    /**
     * @param array<int, string> $allowed
     * @param array<string, mixed> $config
     */
    private function currentTargetEntity(
        string $configured,
        array $allowed,
        ?WorkflowContext $context,
        array $config = []
    ): string {
        $configured = $this->singularEntity($configured);
        $allowed = array_values(array_unique(array_map(fn(string $entity): string => $this->singularEntity($entity), $allowed)));

        if ($this->entitySource($config) === 'manual') {
            return $configured;
        }

        if ((bool)($config['target_entity_locked'] ?? false)) {
            return $configured;
        }

        $data = $context?->getTriggerData() ?? [];
        $candidate = $this->singularEntity((string)(
            Arr::get($data, 'entity')
            ?: Arr::get($data, 'item.entity_type')
            ?: Arr::get($data, 'item.element_type')
            ?: ''
        ));

        if (in_array($candidate, $allowed, true) && $this->currentEntityId($candidate, $context, $config) > 0) {
            return $candidate;
        }

        foreach ($allowed as $entity) {
            if ($this->numericId(Arr::get($data, $entity . '.id')) > 0) {
                return $entity;
            }
        }

        if ($context && method_exists($context, 'getStepOutputs')) {
            foreach (array_reverse($context->getStepOutputs(), true) as $output) {
                foreach ($allowed as $entity) {
                    if ($this->entityIdFromStepOutput($entity, $output) > 0) {
                        return $entity;
                    }
                }
            }
        }

        return in_array($configured, $allowed, true) ? $configured : ($allowed[0] ?? $configured);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function currentEntityId(string $entity, ?WorkflowContext $context, array $config = []): int
    {
        $entity = $this->singularEntity($entity);

        if ($this->entitySource($config) === 'manual') {
            foreach (['target_entity_id', 'entity_id'] as $key) {
                if (array_key_exists($key, $config)) {
                    return $this->numericId($config[$key]);
                }
            }

            return 0;
        }

        $data = $context?->getTriggerData() ?? [];
        $triggerEntity = $this->singularEntity((string)(Arr::get($data, 'entity') ?: Arr::get($data, 'item.entity_type') ?: ''));
        $triggerId = $this->numericId(Arr::get($data, $entity . '.id'))
            ?: ($triggerEntity === $entity
                ? ($this->numericId(Arr::get($data, 'item.id'))
                    ?: $this->numericId(Arr::get($data, 'item.element_id'))
                    ?: $this->numericId(Arr::get($data, 'id')))
                : 0);

        if ($triggerId > 0) {
            return $triggerId;
        }

        return $this->previousStepEntityId($entity, $context);
    }

    private function singularEntity(string $entity): string
    {
        return match ($entity) {
            'leads' => 'lead',
            'contacts' => 'contact',
            'companies' => 'company',
            'customers' => 'customer',
            'tasks' => 'task',
            default => $entity,
        };
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
        $target = $this->currentTargetEntity(
            (string)($config['target_entity'] ?? ''),
            ['lead', 'contact', 'company', 'customer'],
            $context,
            $config,
        );

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
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    private function findExistingContactBeforeCreate(Account $account, array $payload, array $config): ?array
    {
        foreach ($this->contactSearchCandidates($account, $payload, $config) as $candidate) {
            $contact = $this->findEntityByQuery($account, 'contact', $candidate['query'], $candidate);

            if ($contact !== null) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $config
     * @return array<int, array{type: string, query: string, field?: string|null, field_label?: string|null}>
     */
    private function contactSearchCandidates(Account $account, array $payload, array $config): array
    {
        $candidates = [];

        foreach ((array)($config['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $fieldKey = (string)($field['field'] ?? '');
            $value = $field['value'] ?? null;

            if ($fieldKey === '' || $value === null || $value === '') {
                continue;
            }

            $fieldModel = str_starts_with($fieldKey, 'system:')
                ? null
                : $this->field($account, 'contact', $fieldKey);
            $fieldType = $this->contactSearchFieldType($fieldKey, $fieldModel);

            if ($fieldType === null) {
                continue;
            }

            foreach ($this->scalarValues($value) as $itemValue) {
                $this->addContactSearchCandidate(
                    $candidates,
                    $fieldType,
                    $itemValue,
                    $fieldKey,
                    $fieldModel?->name ?: $this->amoSystemFieldLabel('contact', $fieldKey)
                );
            }
        }

        foreach ((array)($payload['custom_fields_values'] ?? []) as $fieldPayload) {
            if (!is_array($fieldPayload)) {
                continue;
            }

            $fieldKey = (string)($fieldPayload['field_id'] ?? $fieldPayload['field_code'] ?? '');

            if ($fieldKey === '') {
                continue;
            }

            $fieldModel = $this->field($account, 'contact', $fieldKey);
            $fieldType = $this->contactSearchFieldType($fieldKey, $fieldModel);

            if ($fieldType === null) {
                continue;
            }

            foreach ((array)($fieldPayload['values'] ?? []) as $valuePayload) {
                $this->addContactSearchCandidate(
                    $candidates,
                    $fieldType,
                    is_array($valuePayload) ? ($valuePayload['value'] ?? null) : $valuePayload,
                    $fieldKey,
                    $fieldModel?->name
                );
            }
        }

        $this->addContactSearchCandidate($candidates, 'name', $payload['name'] ?? null, 'system:name', 'Имя контакта');

        return array_values($candidates);
    }

    private function contactSearchFieldType(string $fieldKey, ?AmoCrmField $field): ?string
    {
        $key = mb_strtolower(trim($fieldKey));
        $name = mb_strtolower(trim((string)$field?->name));
        $code = mb_strtolower(trim((string)$field?->code));
        $type = mb_strtolower(trim((string)$field?->type));

        if (str_starts_with($key, 'system:')) {
            return in_array(substr($key, 7), ['name', 'first_name', 'last_name'], true) ? 'name' : null;
        }

        if (
            $code === 'phone'
            || str_contains($name, 'телефон')
            || str_contains($name, 'phone')
            || str_contains($type, 'phone')
        ) {
            return 'phone';
        }

        if (
            $code === 'email'
            || str_contains($name, 'почта')
            || str_contains($name, 'email')
            || str_contains($type, 'email')
        ) {
            return 'email';
        }

        return null;
    }

    /**
     * @param array<string, array{type: string, query: string, field?: string|null, field_label?: string|null}> $candidates
     */
    private function addContactSearchCandidate(
        array &$candidates,
        string $type,
        mixed $value,
        ?string $field = null,
        ?string $fieldLabel = null
    ): void {
        $value = trim((string)$value);

        if ($value === '') {
            return;
        }

        $queries = match ($type) {
            'phone' => $this->phoneSearchQueries($value),
            'email' => [mb_strtolower($value)],
            default => [$value],
        };

        foreach ($queries as $query) {
            $query = trim((string)$query);

            if ($query === '') {
                continue;
            }

            $key = $type . ':' . mb_strtolower($query);
            $candidates[$key] = [
                'type' => $type,
                'query' => $query,
                'field' => $field,
                'field_label' => $fieldLabel,
            ];
        }
    }

    /**
     * @return array<int, string>
     */
    private function phoneSearchQueries(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        $queries = [$phone];

        if ($digits !== '') {
            $queries[] = $digits;

            if (strlen($digits) >= 10) {
                $queries[] = substr($digits, -10);
                $queries[] = '7' . substr($digits, -10);
                $queries[] = '+7' . substr($digits, -10);
            }
        }

        return array_values(array_unique(array_filter($queries)));
    }

    /**
     * @return array<int, mixed>
     */
    private function scalarValues(mixed $value): array
    {
        if (!is_array($value)) {
            return [$value];
        }

        $values = [];

        foreach ($value as $item) {
            $values[] = is_array($item) ? ($item['value'] ?? null) : $item;
        }

        return $values;
    }

    /**
     * @param array{type?: string, query?: string, field?: string|null, field_label?: string|null}|null $search
     * @return array<string, mixed>|null
     */
    private function findEntityByQuery(Account $account, string $entity, string $query, ?array $search = null): ?array
    {
        $queries = $entity === 'contact' ? $this->phoneSearchQueries($query) : [$query];

        foreach ($queries as $candidateQuery) {
            $body = $this->amoRequest($account, 'GET', '/api/v4/' . $this->entityPlural($entity), query: [
                'query' => $candidateQuery,
                'limit' => $entity === 'contact' ? 10 : 1,
            ]);

            foreach ((array)Arr::get($body, '_embedded.' . $this->entityPlural($entity), []) as $model) {
                if (!is_array($model)) {
                    continue;
                }

                $model = $entity === 'contact'
                    ? $this->fullContactForSearch($account, $model)
                    : $model;

                if ($entity !== 'contact' || $this->contactMatchesSearch($model, $search ?? ['query' => $query])) {
                    $model['search'] = array_merge($search ?? [], ['query' => $candidateQuery]);

                    return $model;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $contact
     * @return array<string, mixed>
     */
    private function fullContactForSearch(Account $account, array $contact): array
    {
        $contactId = (int)($contact['id'] ?? 0);

        if ($contactId <= 0 || array_key_exists('custom_fields_values', $contact)) {
            return $contact;
        }

        try {
            return $this->amoRequest($account, 'GET', '/api/v4/contacts/' . $contactId);
        } catch (Throwable) {
            return $contact;
        }
    }

    /**
     * @param array<string, mixed> $contact
     * @param array<string, mixed> $search
     */
    private function contactMatchesSearch(array $contact, array $search): bool
    {
        $query = trim((string)($search['query'] ?? $search['value'] ?? ''));

        if ($query === '') {
            return true;
        }

        $type = (string)($search['type'] ?? '');

        if ($type === 'phone' || ($type === '' && preg_match('/\d{7,}/', $query))) {
            $needle = preg_replace('/\D+/', '', $query) ?: '';

            if ($needle === '') {
                return false;
            }

            foreach ($this->contactCustomFieldValues($contact) as $value) {
                $digits = preg_replace('/\D+/', '', (string)$value) ?: '';

                if ($digits !== '' && substr($digits, -10) === substr($needle, -10)) {
                    return true;
                }
            }

            return false;
        }

        if ($type === 'email' || filter_var($query, FILTER_VALIDATE_EMAIL)) {
            $needle = mb_strtolower($query);

            foreach ($this->contactCustomFieldValues($contact) as $value) {
                if (mb_strtolower(trim((string)$value)) === $needle) {
                    return true;
                }
            }

            return false;
        }

        return mb_strtolower(trim((string)($contact['name'] ?? ''))) === mb_strtolower($query);
    }

    /**
     * @param array<string, mixed> $contact
     * @return array<int, mixed>
     */
    private function contactCustomFieldValues(array $contact): array
    {
        $values = [];

        foreach ((array)($contact['custom_fields_values'] ?? []) as $field) {
            foreach ((array)($field['values'] ?? []) as $value) {
                $values[] = is_array($value) ? ($value['value'] ?? null) : $value;
            }
        }

        return $values;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tagModels(mixed $tags): array
    {
        return array_map(static fn(string $tag): array => ['name' => $tag], $this->tags($tags));
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
    private function readAmo(Account $account, array $config): array
    {
        $request = WorkflowAmoReadCatalog::build($config, $account->user_id);
        $body = $this->amoRequest($account, 'GET', $request['path'], null, $request['query']);
        $collections = array_filter($body['_embedded'] ?? [], fn($items) => is_array($items) && array_is_list($items));
        $items = $collections ? reset($collections) : (isset($body['id']) ? [$body] : []);
        return ['success' => true, 'output' => ['data' => $body, 'items' => $items, 'count' => count($items), 'has_more' => filled(data_get($body, '_links.next.href'))]];
    }

    private function amoRequest(
        Account $account,
        string $method,
        string $path,
        ?array $payload = null,
        array $query = [],
        ?int $expectedStatus = null
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

        try {
            $response = Http::withToken((string)$account->access_token)
                ->withoutRedirecting()
                ->acceptJson()
                ->asJson()
                ->timeout(30)
                ->send($method, $url, $options);
        } catch (\Illuminate\Http\Client\ConnectionException $error) {
            $this->captureAmoRequest($method, $url, $query, $payload, null, null);
            throw $error;
        }

        $body = $this->responseBody($response);
        $this->captureAmoRequest($method, $url, $query, $payload, $response, $body);

        if ($response->failed() || ($expectedStatus !== null && $response->status() !== $expectedStatus)) {
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
        ?Response $response,
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
                'code' => $response?->status(),
                'body' => $body,
                'error' => $response === null ? 'HTTP-ответ не получен: ошибка соединения.' : ($response->failed() ? $response->reason() : null),
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
        $detail = $this->amoErrorDetail($body);

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

    private function amoErrorDetail(mixed $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }

        $detail = Arr::get($body, 'detail') ?: Arr::get($body, 'title') ?: Arr::get($body, 'message');

        if (is_string($detail) && trim($detail) !== '') {
            return trim($detail);
        }

        $validationErrors = Arr::get($body, 'validation-errors');

        if (is_array($validationErrors)) {
            foreach ($validationErrors as $validationError) {
                $message = Arr::get((array)$validationError, 'errors.0.detail')
                    ?: Arr::get((array)$validationError, 'errors.0.message')
                    ?: Arr::get((array)$validationError, 'errors.0.code');

                if (is_string($message) && trim($message) !== '') {
                    return trim($message);
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function tags(mixed $value): array
    {
        $tags = is_array($value) ? $value : explode(',', (string)$value);
        $unique = [];
        foreach (array_filter(array_map('trim', $tags)) as $tag) {
            $unique[mb_strtolower($tag)] ??= $tag;
        }
        return array_values($unique);
    }

    /**
     * @param array<int, array<string, mixed>>|mixed $conditions
     * @return array{field?: string|null, operator?: string|null, value: string}|null
     */
    private function firstSearchCondition(mixed $conditions): ?array
    {
        foreach ((array)$conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $value = trim((string)($condition['value'] ?? ''));

            if ($value !== '') {
                return [
                    'field' => filled($condition['field'] ?? null) ? (string)$condition['field'] : null,
                    'operator' => filled($condition['operator'] ?? null) ? (string)$condition['operator'] : null,
                    'value' => $value,
                ];
            }
        }

        return null;
    }

    private function entityLabel(string $entity): string
    {
        return match ($entity) {
            'lead' => 'Сделка',
            'contact' => 'Контакт',
            'company' => 'Компания',
            'customer' => 'Покупатель',
            'task' => 'Задача',
            default => $entity,
        };
    }

    private function amoSearchFieldLabel(Account $account, string $entity, mixed $field): ?string
    {
        $field = trim((string)$field);

        if ($field === '') {
            return null;
        }

        $systemLabel = $this->amoSystemFieldLabel($entity, $field);

        if ($systemLabel !== null) {
            return $systemLabel;
        }

        if (!is_numeric($field)) {
            return $field;
        }

        $customField = AmoCrmField::query()
            ->where('user_id', $account->user_id)
            ->where('field_id', (int)$field)
            ->first(['field_id', 'name', 'code']);

        if (!$customField) {
            return '[' . $field . ']';
        }

        $label = trim((string)($customField->name ?: $customField->code));

        if ($label === '') {
            return '[' . $field . ']';
        }

        return $label . ' [' . $field . ']';
    }

    private function amoSystemFieldLabel(string $entity, string $field): ?string
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
            ][$field] ?? null,
            'contact' => [
                'system:name' => 'Имя контакта',
                'system:first_name' => 'Имя',
                'system:last_name' => 'Фамилия',
                'system:responsible_user_id' => 'Ответственный',
            ][$field] ?? null,
            'company' => [
                'system:name' => 'Название компании',
                'system:responsible_user_id' => 'Ответственный',
            ][$field] ?? null,
            'customer' => [
                'system:name' => 'Название покупателя',
                'system:next_price' => 'Ожидаемая сумма',
                'system:responsible_user_id' => 'Ответственный',
            ][$field] ?? null,
            default => null,
        };
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
        $result['output']['amo_exchange'] = $this->safeExchangeValue(array_slice($queries, 0, 20));
        if (count($queries) > 20) $result['output']['amo_exchange_truncated'] = true;

        return $result;
    }

    private function safeExchangeValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 16) return '[Сокращено]';
        if (is_string($value)) return mb_strlen($value) > 8000 ? mb_substr($value, 0, 8000).'… [Сокращено]' : $value;
        if (!is_array($value)) return $value;
        $safe = [];
        foreach (array_slice($value, 0, 250, true) as $key => $item) {
            $safe[$key] = preg_match('/authorization|password|secret|token|api[_-]?key/i', (string)$key)
                ? '[Скрыто]' : ($key === 'url' && is_string($item) ? $this->safeAmoUrl($item) : $this->safeExchangeValue($item, $depth + 1));
        }
        if (count($value) > 250) $safe['_truncated'] = true;
        return $safe;
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
