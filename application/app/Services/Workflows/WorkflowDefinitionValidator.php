<?php

namespace App\Services\Workflows;

use App\Workflows\Actions\WorkflowAmoCrmActionCatalog;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Triggers\TriggerRegistry;

/** Static validation only: never resolves event variables or calls external services. */
final class WorkflowDefinitionValidator
{
    public static function issues(array $definition): array
    {
        $errors = [];
        try { $nodes = WorkflowGraph::ordered($definition); }
        catch (\InvalidArgumentException $error) { return [$error->getMessage()]; }
        $starts = WorkflowStartNodes::all($definition);
        if ($starts === []) $errors[] = 'Добавьте триггер запуска.';
        foreach ($starts as $start) {
            if (!app(TriggerRegistry::class)->has($start['type'] ?? '')) $errors[] = 'Неизвестный триггер запуска.';
        }
        $registry = app(ActionRegistry::class);
        $metadata = collect($registry->getAllWithMetadata())->keyBy('type');
        $names = WorkflowExpressionCatalog::referenceNames($definition['actions'] ?? [], $definition);
        $references = array_merge(array_keys($names), array_values($names));
        foreach ($nodes as $entry) {
            $step = $entry['step'];
            if ($step['disabled'] ?? false) continue;
            $type = $step['type'] ?? '';
            $label = $step['name'] ?? $metadata[$type]['name'] ?? $type;
            $prefix = '«'.$label.'» ['.$step['id'].']: ';
            if (!app(ActionRegistry::class)->has($type) || in_array($type, WorkflowAmoCrmActionCatalog::unsupportedWorkflowTypes(), true)) {
                $errors[] = $prefix.'действие не поддерживается.';
                continue;
            }
            $config = $step['config'] ?? [];
            foreach (self::configIssues($type, $config) as $error) $errors[] = $prefix.$error;
            unset($config['true_actions'], $config['false_actions'], $config['javascript_code']);
            array_walk_recursive($config, function ($value) use (&$errors, $prefix, $references): void {
                if (!is_string($value)) return;
                if (substr_count($value, '{{') !== substr_count($value, '}}')) $errors[] = $prefix.'незакрытая переменная {{ … }}.';
                preg_match_all('/\$node\[\s*([\'"])(.*?)\1\s*\]/u', $value, $matches);
                foreach ($matches[2] as $reference) if (!in_array($reference, $references, true)) $errors[] = $prefix.'ссылка на отсутствующую ноду «'.$reference.'».';
            });
        }

        return array_values(array_unique($errors));
    }

    public static function configIssues(string $type, array $config): array
    {
        $errors = [];
        $required = match ($type) {
            'amocrm_start_salesbot' => ['bot_id' => 'Выберите SalesBot.'],
            'amocrm_create_contact', 'amocrm_create_company', 'amocrm_create_lead' => ['name' => 'Укажите название создаваемой сущности.'],
            'amocrm_add_note' => ['text' => 'Заполните текст примечания.'],
            'amocrm_create_task' => ['text' => 'Заполните текст задачи.'],
            'amocrm_change_lead_status' => ['status_id' => 'Выберите этап сделки.'],
            'http_request' => ['url' => 'Укажите URL запроса.'],
            'workflow_javascript' => ['javascript_code' => 'Заполните JavaScript-код.'],
            'workflow_delay' => ['seconds' => 'Укажите длительность задержки.'],
            'telegram_send_message' => ['chat_id' => 'Укажите чат.', 'text' => 'Заполните сообщение.'],
            'amocrm_read' => ['operation' => 'Выберите операцию чтения.'],
            'run_workflow' => ['workflow_id' => 'Выберите запускаемый процесс.'],
            default => [],
        };
        if (($config['body_mode'] ?? null) === 'json') {
            try { $body = WorkflowJsonBody::parse($config['json_body'] ?? null); }
            catch (\InvalidArgumentException $error) { $errors[] = $error->getMessage(); $body = []; }
            if ($type === 'amocrm_create_task') {
                $config = array_merge($config, $body);
                $required += ['entity_id' => 'Укажите ID сущности в JSON задачи.', 'entity_type' => 'Укажите тип сущности в JSON задачи.'];
                if (filled($body['entity_type'] ?? null) && !self::expression($body['entity_type']) && !in_array($body['entity_type'], ['leads', 'contacts', 'companies', 'customers'], true)) $errors[] = 'Неизвестный тип сущности в JSON задачи.';
            }
        }
        foreach ($required as $key => $message) if (blank($config[$key] ?? null)) $errors[] = $message;
        if (($config['entity_source'] ?? null) === 'manual' && blank($config['target_entity_id'] ?? $config['entity_id'] ?? null)) $errors[] = 'Укажите ID сущности для ручного выбора.';
        foreach (['bot_id', 'target_entity_id', 'entity_id', 'pipeline_id', 'status_id', 'responsible_user_id', 'task_type_id', 'workflow_id'] as $key) {
            $value = $config[$key] ?? null;
            if (filled($value) && !self::expression($value) && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) $errors[] = $key.': нужен положительный целый ID или переменная.';
        }
        if ($type === 'workflow_delay' && isset($config['seconds']) && !self::expression($config['seconds'])
            && filter_var($config['seconds'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]) === false) $errors[] = 'Задержка должна быть от 1 до 30 секунд.';
        if ($type === 'http_request' && filled($config['url'] ?? null) && !self::expression($config['url'])) {
            $url = parse_url((string) $config['url']);
            if (!$url || empty($url['host']) || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || isset($url['user']) || isset($url['pass'])) $errors[] = 'Нужен корректный HTTP(S) URL без логина и пароля.';
        }
        if ($type === 'http_request') {
            if (!in_array(strtoupper((string) ($config['method'] ?? 'GET')), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) $errors[] = 'Недопустимый HTTP-метод.';
            foreach (['headers', 'body'] as $key) {
                if (is_string($config[$key] ?? null) && filled($config[$key]) && !self::expression($config[$key])) {
                    json_decode($config[$key], true);
                    if (json_last_error() !== JSON_ERROR_NONE) $errors[] = $key.': некорректный JSON.';
                }
            }
        }
        if (in_array($type, ['condition', 'control-condition'], true) && empty($config['conditions'])) $errors[] = 'Добавьте хотя бы одно условие.';
        if ($type === 'telegram_send_message') {
            if (blank($config['credential_id'] ?? null) && blank($config['bot_token'] ?? $config['bot_token_encrypted'] ?? null)) $errors[] = 'Выберите подключение Telegram-бота.';
            if (filled($config['credential_id'] ?? null) && (!is_scalar($config['credential_id']) || !preg_match('/^[1-9]\d*$/D', (string) $config['credential_id']))) $errors[] = 'Выберите подключение из списка.';
        }

        return array_values(array_unique($errors));
    }

    private static function expression(mixed $value): bool
    {
        return is_string($value) && str_contains($value, '{{') && str_contains($value, '}}');
    }
}
