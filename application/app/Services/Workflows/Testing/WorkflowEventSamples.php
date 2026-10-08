<?php

declare(strict_types=1);

namespace App\Services\Workflows\Testing;

use App\Models\User;
use Leek\FilamentWorkflows\Triggers\TriggerRegistry;
use LogicException;

/**
 * Reviewed synthetic contracts, not recordings from a customer account.
 * Expected payloads are deliberately built without calling the production normalizer.
 */
final class WorkflowEventSamples
{
    public const CLOCK = '2026-09-19T09:00:00+00:00';

    /** Independent event inventory: payload key, entity, action. */
    private const WEBHOOKS = [
        'responsible_lead' => ['leads', 'lead', 'responsible'],
        'responsible_contact' => ['contacts', 'contact', 'responsible'],
        'responsible_company' => ['contacts', 'company', 'responsible'],
        'responsible_customer' => ['customers', 'customer', 'responsible'],
        'responsible_task' => ['tasks', 'task', 'responsible'],
        'restore_lead' => ['leads', 'lead', 'restore'],
        'restore_contact' => ['contacts', 'contact', 'restore'],
        'restore_company' => ['contacts', 'company', 'restore'],
        'add_lead' => ['leads', 'lead', 'add'],
        'add_contact' => ['contacts', 'contact', 'add'],
        'add_company' => ['contacts', 'company', 'add'],
        'add_customer' => ['customers', 'customer', 'add'],
        'add_talk' => ['talks', 'talk', 'add'],
        'add_task' => ['tasks', 'task', 'add'],
        'update_lead' => ['leads', 'lead', 'update'],
        'update_contact' => ['contacts', 'contact', 'update'],
        'update_company' => ['contacts', 'company', 'update'],
        'update_customer' => ['customers', 'customer', 'update'],
        'update_talk' => ['talks', 'talk', 'update'],
        'update_task' => ['tasks', 'task', 'update'],
        'delete_lead' => ['leads', 'lead', 'delete'],
        'delete_contact' => ['contacts', 'contact', 'delete'],
        'delete_company' => ['contacts', 'company', 'delete'],
        'delete_customer' => ['customers', 'customer', 'delete'],
        'delete_task' => ['tasks', 'task', 'delete'],
        'status_lead' => ['leads', 'lead', 'status'],
        'note_lead' => ['leads', 'lead', 'note'],
        'note_contact' => ['contacts', 'contact', 'note'],
        'note_company' => ['contacts', 'company', 'note'],
        'note_customer' => ['customers', 'customer', 'note'],
        'add_message' => ['message', 'message', 'add'],
        'add_outgoing_message' => ['outgoing_message', 'outgoing_message', 'add'],
        'add_unsorted' => ['unsorted', 'unsorted', 'add'],
        'update_unsorted' => ['unsorted', 'unsorted', 'update'],
        'delete_unsorted' => ['unsorted', 'unsorted', 'delete'],
        'add_chat_template_review' => ['chat_template_reviews', 'chat_template_review', 'add'],
    ];

    /** @return array<string, array<string, mixed>> */
    public static function samples(): array
    {
        $webhooks = [];
        foreach (self::WEBHOOKS as $event => $spec) {
            $webhooks['amocrm-'.str_replace('_', '-', $event)] = self::webhook($event, ...$spec);
        }
        $definitions = $webhooks + self::otherStarts();
        $samples = [];
        foreach (app(TriggerRegistry::class)->all() as $type => $class) {
            if (! isset($definitions[$type])) {
                throw new LogicException("No reviewed event contract for registered trigger: {$type}");
            }
            $samples[$type] = [
                'id' => 'event.'.$type,
                'type' => $type,
                'label' => $class::name(),
                'trigger_class' => $class,
                'provenance' => 'synthetic_contract',
                'captured_from_account' => false,
                'clock' => self::CLOCK,
                ...$definitions[$type],
            ];
        }

        return $samples;
    }

    private static function webhook(string $event, string $key, string $entity, string $action): array
    {
        $item = self::item($entity, $action);
        $account = ['id' => '900000', 'subdomain' => 'clever-qa-example'];
        $payload = ['account' => $account, $key => [$action => [$item]]];
        $canonical = $payload;

        // Deliberately exercise the wire aliases used by incoming notifications.
        if ($entity === 'company') {
            $wireItem = $item;
            unset($wireItem['type']);
            $payload = ['account' => $account, 'companies' => [$action => [$wireItem]]];
            $canonical = $payload + ['contacts' => [$action => [$item]]];
        } elseif ($event === 'update_task') {
            $payload = ['account' => $account, 'task' => ['update' => [[$item]]]];
        } elseif ($event === 'add_chat_template_review') {
            $payload = ['account' => $account, 'add' => [$item]];
            $canonical = $payload + ['chat_template_reviews' => ['add' => [$item]]];
        } elseif ($event === 'delete_lead' || $event === 'delete_task') {
            $payload[$key][$action] = $item['id'];
        }

        $normalized = [
            'event' => $event, 'entity' => $entity, 'action' => $action,
            'payload_key' => $key, 'action_key' => $action,
            'item' => $item, 'items' => [$item],
        ];
        $context = [
            'source' => 'amocrm', 'event' => $event, 'entity' => $entity, 'action' => $action,
            'payload' => $canonical, 'received_at' => self::CLOCK,
            'item' => $item, $action => $item,
        ];
        $context[$entity] = $action === 'note' ? array_replace($item, ['id' => $item['element_id']]) : $item;

        return [
            'config' => ['source' => 'amocrm', 'event' => $event, 'entity' => $entity, 'action' => $action],
            'input' => [
                'format' => 'application/x-www-form-urlencoded',
                'payload' => $payload,
                'encoded' => http_build_query($payload, '', '&', PHP_QUERY_RFC1738),
            ],
            'output' => ['normalized_event' => $normalized, 'trigger_context' => $context],
            'assertions' => [
                'should_trigger' => true,
                'event' => $event, 'entity' => $entity, 'item_count' => 1,
                'identity_field' => $entity === 'unsorted' ? 'uid' : 'id',
                'identity' => $item[$entity === 'unsorted' ? 'uid' : 'id'],
                'unknown_fields_preserved' => true,
            ],
            'notes' => 'Синтетический пример контракта обработчика. Поля конкретного живого хука могут отличаться; это не запись от amoCRM.',
        ];
    }

    private static function item(string $entity, string $action): array
    {
        if ($action === 'delete' && $entity !== 'unsorted') {
            return match ($entity) {
                'company' => ['type' => 'company', 'id' => '900001'],
                'contact' => ['id' => '900001', 'type' => 'contact'],
                default => ['id' => '900001'],
            };
        }
        if ($action === 'note') {
            $item = [
                'id' => '920001', 'element_id' => '900001', 'note_type' => '4',
                'text' => 'Clever QA: проверка примечания', 'created_at' => '1789812000',
            ];
        } else {
            $item = match ($entity) {
                'lead' => [
                    'id' => '900001', 'name' => 'Clever QA: сделка', 'price' => '0',
                    'pipeline_id' => '910001', 'status_id' => $action === 'status' ? '143' : '910002',
                    'responsible_user_id' => '900010',
                    'custom_fields' => [['id' => '910010', 'name' => 'QA Run', 'values' => [['value' => 'qa-sample']]]],
                ],
                'contact' => ['id' => '900001', 'name' => 'Clever QA: существующий контакт', 'responsible_user_id' => '900010'],
                'company' => ['id' => '900001', 'name' => 'Clever QA: существующая компания', 'responsible_user_id' => '900010'],
                'customer' => ['id' => '900001', 'name' => 'Clever QA: покупатель', 'next_price' => '0', 'responsible_user_id' => '900010'],
                'task' => [
                    'id' => '900001', 'text' => 'Clever QA: проверить поток', 'task_type' => '1',
                    'element_id' => '900020', 'element_type' => '2', 'responsible_user_id' => '900010',
                    'is_completed' => $action === 'update' ? '1' : '0',
                    'result' => ['id' => '920001', 'text' => 'Clever QA: выполнено'],
                ],
                'talk' => ['id' => '900001', 'entity_id' => '900020', 'entity_type' => 'lead', 'contact_id' => '900030', 'is_in_work' => '1'],
                'message', 'outgoing_message' => [
                    'id' => '00000000-0000-4000-8000-000000000001', 'chat_id' => 'qa-chat-0001',
                    'text' => 'Clever QA: сообщение + символы & =', 'contact_id' => '900030',
                    'entity_id' => '900020', 'entity_type' => 'lead', 'origin' => 'telegram',
                    'type' => $entity === 'message' ? 'incoming' : 'outgoing',
                    'author' => ['id' => 'qa-author', 'type' => $entity === 'message' ? 'external' : 'internal', 'name' => 'QA'],
                    'attachment' => ['type' => 'picture', 'link' => 'https://example.test/qa-image.png'],
                ],
                'unsorted' => [
                    'uid' => 'qa-unsorted-0001', 'category' => 'forms', 'pipeline_id' => '910001',
                    ...($action === 'delete' ? ['action' => 'accept', 'accept_result' => ['leads' => ['900020']]] : ['source_name' => 'Clever QA']),
                ],
                'chat_template_review' => [
                    'id' => '900001', 'type' => 'waba', 'is_on_review' => '1',
                    'reviews' => [['status' => 'review', 'source_id' => '900040']],
                ],
                default => throw new LogicException("No entity fixture for {$entity}"),
            };
        }
        if (in_array($entity, ['contact', 'company'], true)) {
            $item = $entity === 'company' ? ['type' => $entity, ...$item] : [...$item, 'type' => $entity];
        }
        if ($action === 'responsible') {
            $item['responsible_user_id'] = '900011';
            $item['old_responsible_user_id'] = '900010';
        }
        if ($action === 'status') {
            $item['old_status_id'] = '910002';
            $item['old_pipeline_id'] = '910001';
        }
        // Exercises forward-compatible retention, including string zero and nested values.
        $item['qa_extension'] = ['enabled' => '0', 'trace' => 'qa-sample'];

        return $item;
    }

    private static function otherStarts(): array
    {
        $manualInput = ['lead' => ['id' => 900001, 'name' => 'Clever QA: существующая сделка']];
        $manual = [
            'config' => [],
            'input' => ['format' => 'internal_context', 'subject' => null, 'context' => ['is_manual' => true, 'triggered_by' => 900010, 'input_data' => $manualInput]],
            'output' => ['trigger_context' => ['event' => 'manual', 'triggered_by' => 900010, 'triggered_at' => self::CLOCK, 'input' => $manualInput]],
            'assertions' => ['should_trigger' => true],
            'notes' => 'Синтетический контракт класса триггера; действия amoCRM не выполняются.',
        ];
        $button = $manual;
        $button['assertions']['should_trigger'] = false;
        $button['notes'] = 'Кнопку запускает отдельный авторизованный callback. shouldTrigger=false защищает от обычной рассылки событий. Здесь показан базовый контракт класса, не полное тело callback.';
        $pipeline = $button;
        $pipeline['notes'] = 'Digital Pipeline запускает отдельный callback amoCRM. Здесь показан базовый контракт класса, не полный контекст живого запуска.';
        $subject = ['body' => ['order_id' => 'QA-001', 'paid' => false, 'amount' => 0], 'headers' => ['content-type' => 'application/json']];
        $dateSubject = ['id' => 900001, 'birthday' => '2026-09-19'];

        return [
            'manual' => $manual,
            'amo-button' => $button,
            'amo-bulk' => array_replace($button, ['notes' => 'Массовое действие запускает отдельный callback для каждой выбранной сделки, контакта или компании. shouldTrigger=false исключает обычную рассылку событий.']),
            'digital-pipeline' => $pipeline,
            'schedule' => [
                'config' => ['timezone' => 'UTC', 'rules' => [['frequency' => 'daily', 'time' => '09:00']]],
                'input' => ['format' => 'internal_context', 'subject' => null, 'context' => ['check_time' => self::CLOCK]],
                'output' => ['trigger_context' => ['event' => 'scheduled', 'frequency' => 'daily', 'cron_expression' => '00 09 * * *', 'triggered_at' => self::CLOCK]],
                'assertions' => ['should_trigger' => true],
            ],
            'date-condition' => [
                'config' => ['model' => User::class, 'date_field' => 'birthday', 'operator' => 'on', 'offset_days' => 0],
                'input' => ['format' => 'internal_context', 'subject_class' => User::class, 'subject' => $dateSubject, 'context' => ['check_date' => '2026-09-19']],
                'output' => ['trigger_context' => ['event' => 'date_condition', 'model_type' => User::class, 'date_field' => 'birthday', 'operator' => 'on', 'offset_days' => 0, 'model' => $dateSubject, 'model_id' => 900001, 'date_value' => '2026-09-19']],
                'assertions' => ['should_trigger' => true],
                'notes' => 'Устаревший триггер: доступен для сохранённых схем, скрыт в каталоге новых нод. Модель в тесте существует только в памяти.',
            ],
            'workflow-completed' => [
                'config' => [],
                'input' => ['format' => 'internal_context', 'subject' => ['workflow_id' => 900001, 'id' => 900002], 'context' => ['source_workflow_id' => 900001, 'source_workflow_run_id' => 900002]],
                'output' => ['trigger_context' => ['event' => 'workflow-completed', 'source_workflow_id' => 900001, 'source_workflow_run_id' => 900002, 'triggered_at' => self::CLOCK]],
                'assertions' => ['should_trigger' => true],
            ],
            'generic-webhook' => [
                'config' => ['source' => 'webhook'],
                'input' => ['format' => 'application/json', 'subject' => $subject, 'context' => []],
                'output' => ['trigger_context' => $subject],
                'assertions' => ['should_trigger' => true],
                'notes' => 'Произвольный JSON отправителя, единой схемы содержимого body нет.',
            ],
        ];
    }
}
