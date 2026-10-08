<?php

declare(strict_types=1);

namespace App\Services\Workflows\Testing;

use App\Services\Workflows\WorkflowAmoReadCatalog;
use Leek\FilamentWorkflows\Actions\ActionRegistry;

/** Synthetic examples only. No method in this class performs an external action. */
final class WorkflowNodeSamples
{
    public static function samples(): array
    {
        $samples = [];
        $input = ['account' => ['id' => 1], 'lead' => ['id' => 101], 'contact' => ['id' => 201], 'company' => ['id' => 301]];
        $add = function (string $type, array $config, array $result, ?string $handler = null, array $requests = [], ?array $trigger = null) use (&$samples, $input): void {
            $class = app(ActionRegistry::class)->get($type);
            $samples[$type] = [
                'type' => $type,
                'label' => $class ? $class::workflowName() : $type,
                'provenance' => 'synthetic_contract',
                'availability' => 'supported',
                'execution_status' => 'example_only',
                'config' => $config,
                'input' => $trigger ?? $input,
                'expected_result' => $result,
                'output' => $result['output'] ?? null,
                'handler' => $handler,
                'expected_requests' => $requests,
                'note' => 'Искусственные данные. Не результат проверки подключённого аккаунта. amo_exchange добавляется внешним исполнителем отдельно.',
            ];
        };
        $success = fn (string $action, string $entity, int $id, array $extra = []) => ['success' => true, 'output' => array_merge(['action' => $action, 'entity_type' => $entity, 'entity_id' => $id, 'account_id' => 1], $extra)];
        $target = fn (string $entity, int $id) => ['entity_source' => 'manual', 'target_entity' => $entity, 'target_entity_id' => $id];
        $request = fn (string $method, string $path, ?array $body = null, array $query = []) => compact('method', 'path', 'body', 'query');

        foreach (['lead' => 101, 'contact' => 201, 'company' => 301] as $entity => $id) {
            $plural = ['lead' => 'leads', 'contact' => 'contacts', 'company' => 'companies'][$entity];
            $config = ['name' => 'QA '.$entity];
            if ($entity === 'lead') $config['status_id'] = 143;
            $requests = $entity === 'contact' ? [$request('GET', '/api/v4/contacts', query: ['query' => 'QA contact', 'limit' => 10])] : [];
            $requests[] = $request('POST', '/api/v4/'.$plural, [$config]);
            if ($entity !== 'lead') $requests[] = $request('POST', '/api/v4/leads/101/link', [['to_entity_id' => $id, 'to_entity_type' => $plural, 'metadata' => $entity === 'contact' ? ['is_main' => true] : null]]);
            $add('amocrm_create_'.$entity, $config, $success('created', $entity, $id), 'createEntity:'.$entity, $requests);
            $update = $target($entity, $id) + ['body_mode' => 'json', 'json_body' => '{"name":"QA updated"}'];
            $add('amocrm_update_'.$entity.'_fields', $update, $success('updated', $entity, $id), 'updateFields', [$request('PATCH', '/api/v4/'.$plural.'/'.$id, ['name' => 'QA updated'])]);
        }
        $add('amocrm_copy_lead', $target('lead', 101) + ['name' => 'QA copy'], $success('copied', 'lead', 101, ['source_id' => 101]), 'copyLead', [
            $request('GET', '/api/v4/leads/101'), $request('POST', '/api/v4/leads', [['name' => 'QA copy', 'pipeline_id' => 10, 'status_id' => 143, 'responsible_user_id' => 1]]),
        ]);
        $add('amocrm_create_task', $target('lead', 101) + ['text' => 'QA task', 'task_type_id' => 1, 'complete_till' => 1800000000], $success('task_created', 'task', 401, ['parent_entity' => 'lead', 'parent_id' => 101]), 'createTask', [
            $request('POST', '/api/v4/tasks', [['entity_id' => 101, 'entity_type' => 'leads', 'task_type_id' => 1, 'text' => 'QA task', 'complete_till' => 1800000000]]),
        ]);
        $add('amocrm_add_note', $target('lead', 101) + ['text' => 'QA note', 'is_system' => false], $success('note_created', 'note', 501, ['parent_entity' => 'lead', 'parent_id' => 101]), 'addNote', [
            $request('POST', '/api/v4/leads/101/notes', [['note_type' => 'common', 'params' => ['text' => 'QA note']]]),
        ]);
        $add('amocrm_change_tags', $target('lead', 101) + ['tags_to_add' => 'QA', 'tags_to_remove' => 'Old'], $success('tags_changed', 'lead', 101, ['added' => ['QA'], 'removed' => ['Old']]), 'changeTags', [
            $request('GET', '/api/v4/leads/101'), $request('PATCH', '/api/v4/leads/101', ['_embedded' => ['tags' => [['name' => 'QA']]]]),
        ]);
        $add('amocrm_change_lead_status', $target('lead', 101) + ['status_id' => 143], $success('status_changed', 'lead', 101, ['pipeline_id' => null, 'status_id' => 143]), 'changeLeadStatus', [$request('PATCH', '/api/v4/leads/101', ['status_id' => 143])]);
        foreach (['link' => 'linked', 'unlink' => 'unlinked'] as $verb => $action) {
            $body = ['to_entity_id' => 201, 'to_entity_type' => 'contacts'];
            if ($verb === 'link') $body['metadata'] = null;
            $add('amocrm_'.$verb.'_entity', $target('lead', 101) + ['linked_entity' => 'contact', 'linked_entity_id' => 201], $success($action, 'lead', 101, ['linked_entity' => 'contact', 'linked_entity_id' => 201]), $verb.'Entity', [$request('POST', '/api/v4/leads/101/'.$verb, [$body])]);
        }
        $add('amocrm_start_salesbot', $target('lead', 101) + ['bot_id' => 601], ['success' => true, 'output' => ['bot_id' => 601, 'entity_id' => 101, 'entity_type' => 'leads', 'status' => 'accepted']], 'startSalesbot', [$request('POST', '/api/v4/bots/601/run', ['entity_id' => 101, 'entity_type' => 'leads'])]);
        $lead = ['id' => 101, 'name' => 'QA lead', 'price' => 0, 'pipeline_id' => 10, 'status_id' => 143, 'responsible_user_id' => 1];
        $contact = ['id' => 201, 'name' => 'QA contact', '_embedded' => ['leads' => [['id' => 101]]]];
        $query = ['limit' => 10, 'page' => 1, 'order' => ['created_at' => 'desc'], 'with' => 'contacts'];
        $add('amocrm_query_leads', ['limit' => 10, 'page' => 1], ['success' => true, 'output' => ['items' => [$lead], 'count' => 1, 'page' => 1, 'has_more' => false, 'next_page' => null, 'request' => ['method' => 'GET', 'path' => '/api/v4/leads', 'query' => $query]]], 'queryLeads', [$request('GET', '/api/v4/leads', query: $query)]);
        $add('amocrm_get_contact', $target('contact', 201), ['success' => true, 'output' => $contact + ['contact' => $contact, 'data' => $contact, 'entity_id' => 201, 'entity_type' => 'contact', 'request' => ['method' => 'GET', 'path' => '/api/v4/contacts/201', 'query' => ['with' => 'leads']]]], 'getContact', [$request('GET', '/api/v4/contacts/201', query: ['with' => 'leads'])]);
        $add('amocrm_contact_leads', ['source' => 'contact', 'contact_id' => 201], ['success' => true, 'output' => ['items' => [$lead], 'count' => 1, 'contact_id' => 201, 'has_more' => false]], 'contactLeads', [$request('GET', '/api/v4/contacts/201', query: ['with' => 'leads']), $request('GET', '/api/v4/leads', query: ['filter' => ['id' => [101]], 'limit' => 250, 'page' => 1])]);
        $add('amocrm_read', ['operation' => 'leads.one', 'id' => 101], ['success' => true, 'output' => ['data' => $lead, 'items' => [$lead], 'count' => 1, 'has_more' => false]], 'readAmo', [$request('GET', '/api/v4/leads/101')]);
        $samples['amocrm_read']['operations'] = self::readOperations();
        $add('amocrm_find_entity', ['target_entity' => 'lead', 'conditions' => [['field' => 'system:name', 'operator' => 'eq', 'value' => 'QA lead']]], ['success' => true, 'output' => [
            'action' => 'found', 'entity_type' => 'lead', 'entity_id' => 101, 'found' => true, 'context_key' => 'found_lead_1',
            'context_masks' => ['id' => '{{found_lead_1.id}}', 'exists' => '{{found_lead_1.exists}}', 'type' => '{{found_lead_1.type}}'],
            'account_id' => 1, 'query' => 'QA lead', 'search' => ['entity' => 'lead', 'entity_label' => 'Сделка', 'field' => 'system:name', 'field_label' => 'Название сделки', 'operator' => 'eq', 'value' => 'QA lead'],
        ]], 'findEntity', [$request('GET', '/api/v4/leads', query: ['query' => 'QA lead', 'limit' => 1])]);
        $add('amocrm_distribution_queue', $target('lead', 101) + ['distribution_queue_uuid' => 'qa-queue', 'pipeline_id' => 10, 'status_id' => 20], $success('distributed', 'lead', 101, ['pipeline_id' => 10, 'status_id' => 20, 'queue_uuid' => 'qa-queue', 'template' => 0, 'transaction_id' => 701, 'queued' => true, 'duplicate' => false]));
        $samples['amocrm_distribution_queue']['requires'] = ['Настроенная очередь распределения', 'Рабочая БД и обработчик очереди'];
        $samples['amocrm_distribution_queue']['error_examples'] = [['success' => false, 'error' => 'Не выбрана очередь распределения.']];

        $condition = ['left' => '{{ $json.price }}', 'operator' => 'gt', 'right' => 0];
        $add('control-condition', ['logic' => 'and', 'conditions' => [$condition]], ['success' => true, 'output' => ['passed' => true, 'branch' => 'true', 'condition_results' => [['passed' => true, 'left' => $condition['left'], 'left_value' => 123, 'operator' => 'gt', 'right' => 0, 'right_value' => 0]], 'true_actions' => [], 'false_actions' => []]], 'public', trigger: ['price' => 123]);
        $add('workflow_javascript', ['javascript_code' => 'return { total: $json.price * 2 };'], ['success' => true, 'output' => ['total' => 246]], 'public', trigger: ['price' => 123]);
        $add('workflow_delay', ['seconds' => 1], ['success' => true, 'delay_seconds' => 1, 'simulated' => false, 'output' => ['price' => 123]], 'public', trigger: ['price' => 123]);
        $add('workflow_loop', ['items' => '{{ $json.items }}', 'mode' => 'once'], ['success' => true, 'output' => ['_workflow_loop' => true, 'items' => [['id' => 101]], 'input_count' => 2]], 'public', trigger: ['items' => [['id' => 101], ['id' => 102]]]);
        $add('workflow_filter_list', ['items' => '{{ $json.items }}', 'match' => 'all', 'rules' => [['field' => 'status_id', 'operator' => 'eq', 'value' => 143]]], ['success' => true, 'output' => ['items' => [['id' => 101, 'status_id' => 143]], 'count' => 1, 'has_matches' => true, 'input_count' => 2]], 'public', trigger: ['items' => [['id' => 101, 'status_id' => 143], ['id' => 102, 'status_id' => 142]]]);
        $add('http_request', ['method' => 'POST', 'url' => 'https://workflow-contract.example/echo', 'body' => '{"name":"QA"}'], ['success' => true, 'output' => ['status' => 200, 'body' => ['accepted' => true], 'success' => true]], 'public', [$request('POST', '/echo', ['name' => 'QA'])]);
        $add('telegram_send_message', ['credential_id' => 901, 'chat_id' => '-100123', 'text' => 'QA message'], ['success' => true, 'output' => ['message_id' => 801, 'date' => 1800000000, 'chat' => ['id' => -100123, 'type' => 'supergroup'], 'text' => 'QA message']], 'public', [$request('POST', '/bot12345:synthetic-contract-token/sendMessage', ['chat_id' => '-100123', 'text' => 'QA message'])]);
        $samples['telegram_send_message']['error_examples'] = [
            ['success' => false, 'error' => 'Telegram отклонил сообщение (код 403): Пользователь заблокировал бота. Разблокируйте его и нажмите Start.'],
        ];
        $add('send_notification', ['title' => 'QA notification', 'body' => 'QA message', 'recipient_type' => 'users', 'user_ids' => [1]], ['success' => true, 'output' => ['sent_to' => ['qa@example.invalid'], 'recipient_count' => 1, 'email_sent_to' => ['qa@example.invalid'], 'email_recipient_count' => 1, 'telegram_sent' => false]]);
        $samples['send_notification']['requires'] = ['Тестовый пользователь и фейки Notification/Mail'];

        foreach (['run_workflow', 'amocrm_stop_salesbot', 'amocrm_manage_subscription', 'amocrm_update_task', 'amocrm_cancel_delayed_action', 'amocrm_normalize_contact_data', 'amocrm_add_products', 'amocrm_remove_products'] as $type) {
            $result = $type === 'run_workflow'
                ? ['success' => false, 'error' => 'Нельзя запускать текущий процесс из самого себя.']
                : ['success' => false, 'error' => 'Действие '.$type.' ещё не подключено к amoCRM API.'];
            $add($type, $type === 'run_workflow' ? ['workflow_id' => 15] : [], $result, 'unsupported');
            $samples[$type]['availability'] = 'unsupported';
            $samples[$type]['execution_status'] = 'unsupported';
            if ($type === 'run_workflow') $samples[$type]['note'] = 'Скрыт из каталога новых нод. Показан пример защиты от рекурсии существующего обработчика.';
        }
        ksort($samples);
        return $samples;
    }

    public static function readOperations(): array
    {
        $available = WorkflowAmoReadCatalog::availableOperations();
        $result = [];
        foreach (WorkflowAmoReadCatalog::operations() as $key => $operation) {
            preg_match_all('/\{([a-z_]+)\}/', $operation['path'], $matches);
            $result[$key] = $operation + [
                'available_in_picker' => isset($available[$key]),
                'required_identifiers' => $matches[1],
                'provenance' => 'synthetic_contract',
                'execution_status' => 'path_contract_only',
                'config' => ['operation' => $key] + array_fill_keys($matches[1], 101) + ($key === 'custom' ? ['request_path' => '/api/v4/leads'] : []),
                'output' => null,
                'note' => 'Проверяется путь и параметры; реальные ответы операции этим примером не подтверждаются.',
            ];
        }
        return $result;
    }
}
