<?php

namespace App\Services\Workflows;

use Leek\FilamentWorkflows\Actions\ActionRegistry;

final class WorkflowExpressionCatalog
{
    public static function nodeNames(array $actions, array $definition = []): array
    {
        $names = [];
        foreach (self::referenceNames($actions, $definition) as $id => $name) $names[$name] = [$id];
        return $names;
    }

    public static function referenceNames(array $actions, array $definition = []): array
    {
        $metadata = collect(app(ActionRegistry::class)->getAllWithMetadata())->keyBy('type');
        $triggers = collect(app(\Leek\FilamentWorkflows\Triggers\TriggerRegistry::class)->getAllWithMetadata())->keyBy('type');
        $names = [];
        $used = [];
        $add = function (string $id, string $name) use (&$names, &$used): void {
            $base = trim($name) ?: 'Нода';
            $name = $base;
            for ($n = 2; isset($used[$name]); $n++) $name = $base.' ('.$n.')';
            $used[$name] = true;
            $names[$id] = $name;
        };
        foreach (WorkflowStartNodes::all($definition) ?: ['trigger' => []] as $id => $start) {
            $add($id, $start['name'] ?? $triggers[$start['type'] ?? '']['name'] ?? 'Данные запуска');
        }
        foreach (self::flatten($actions) as $id => $action) {
            $add($id, $action['name'] ?? $metadata[$action['type']]['name'] ?? 'Нода');
        }

        return $names;
    }

    public static function sources(array $actions, ?string $editingId, array $context, ?array $connections = null, array $definition = []): array
    {
        $metadata = collect(app(ActionRegistry::class)->getAllWithMetadata())->keyBy('type');
        $names = self::referenceNames($actions, $definition);
        $startId = $context['trigger_data']['_workflow_start_node_id'] ?? 'trigger';
        if (!isset($names[$startId])) $startId = 'trigger';
        $input = $context['trigger_data'] ?? [];
        $start = WorkflowStartNodes::all($definition)[$startId] ?? [];
        $webhookBody = array_key_exists('body', $input) && (($start['type'] ?? '') === 'generic-webhook' || array_key_exists('headers', $input));
        $sources = [self::source($startId, $names[$startId] ?? 'Данные запуска', $webhookBody ? $input['body'] : $input, array_key_exists('trigger_data', $context), $webhookBody ? '.body' : '')];
        $upstream = $connections !== null && $editingId !== null
            ? array_map(fn ($id) => substr($id, 7), WorkflowGraph::ancestors(['actions' => $actions, 'connections' => $connections], 'action:'.$editingId))
            : (self::before($actions, $editingId) ?? []);
        $flat = self::flatten($actions);
        foreach ($upstream as $id) {
            $action = $flat[$id];
            $available = array_key_exists($id, $context['step_outputs'] ?? []);
            $output = $context['step_outputs'][$id] ?? match ($action['type']) {
                'amocrm_query_leads' => ['items' => [['id' => null, 'name' => null, 'price' => null, 'pipeline_id' => null, 'status_id' => null]], 'count' => null, 'has_more' => null, 'next_page' => null],
                'amocrm_get_contact' => ['id' => null, 'name' => null, 'first_name' => null, 'last_name' => null, 'responsible_user_id' => null, 'contact' => [], 'data' => [], 'entity_id' => null, 'entity_type' => 'contact'],
                'amocrm_contact_leads' => ['items'=>[['id'=>null,'pipeline_id'=>null,'status_id'=>null]],'count'=>null,'contact_id'=>null,'has_more'=>false],
                'workflow_filter_list' => ['items'=>[],'count'=>null,'has_matches'=>null,'input_count'=>null],
                'http_request' => ['status'=>null,'body'=>[],'success'=>null],
                'amocrm_start_salesbot' => ['bot_id'=>null,'entity_id'=>null,'entity_type'=>null,'status'=>null],
                'amocrm_read' => ['data' => [], 'items' => [['id' => null]], 'count' => null, 'has_more' => null],
                default => [],
            };
            $exchanges = is_array($output) ? ($output['amo_exchange'] ?? []) : [];
            $last = array_key_last($exchanges);
            if ($last !== null && array_key_exists('body', $exchanges[$last]['response'] ?? [])) {
                $sources[] = self::source($id, $names[$id], $exchanges[$last]['response']['body'], $available, '.amo_exchange['.$last.'].response.body');
            } else {
                if (is_array($output)) unset($output['amo_exchange'], $output['amo_exchange_truncated']);
                $sources[] = self::source($id, $names[$id], $output, $available);
            }
        }

        return $sources;
    }

    public static function remapReferences(mixed $value, array $before, array $after): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ($key !== 'javascript_code') $value[$key] = self::remapReferences($item, $before, $after);
            }
            return $value;
        }
        if (!is_string($value) || !str_contains($value, '{{')) return $value;
        $replacements = [];
        foreach ($before as $id => $name) {
            if (($after[$id] ?? $id) === $name) continue;
            $old = json_encode($name, JSON_UNESCAPED_UNICODE);
            $new = json_encode($after[$id] ?? $id, JSON_UNESCAPED_UNICODE);
            $replacements['$node['.$old.'].json'] = '$node['.$new.'].json';
        }
        return preg_replace_callback('/\{\{(.*?)\}\}/su', fn ($match) => '{{'.strtr($match[1], $replacements).'}}', $value);
    }

    private static function source(string $id, string $name, mixed $output, bool $available, string $rootPath = ''): array
    {
        $fields = [];
        $truncated = false;
        $visit = function (mixed $value, string $path = '', int $depth = 0, ?string $parent = null, string $label = 'Результат') use (&$visit, &$fields, &$truncated, $name, $available): void {
            if (count($fields) >= 150 || $depth > 6) {
                $truncated = true;
                return;
            }
            $fields[] = [
                'path' => $path ?: 'Весь результат',
                'key' => $path, 'parent' => $parent, 'label' => $label, 'depth' => $depth,
                'type' => is_array($value) ? (array_is_list($value) ? 'array' : 'object') : get_debug_type($value),
                'count' => is_array($value) && $available ? count($value) : null,
                'expression' => '{{ $node['.json_encode($name, JSON_UNESCAPED_UNICODE).'].json'.$path.' }}',
                'value' => $available ? $value : null, 'available' => $available,
            ];
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if ($depth === 0 && $key === 'headers') continue;
                if (preg_match('/token|password|authorization|secret/i', (string) $key)) {
                    continue;
                }
                $part = is_int($key) ? '['.$key.']' : (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $key) ? '.'.$key : '['.json_encode($key, JSON_UNESCAPED_UNICODE).']');
                $visit($child, $path.$part, $depth + 1, $path, is_int($key) ? '['.$key.']' : (string) $key);
            }
        };
        $visit($output, $rootPath);

        return ['id' => $id, 'name' => $name, 'available' => $available, 'fields' => $fields, 'truncated' => $truncated];
    }

    private static function before(array $actions, ?string $target, array $before = []): ?array
    {
        foreach ($actions as $action) {
            if (($action['id'] ?? null) === $target) {
                return array_values(array_unique($before));
            }
            $before[] = $action['id'];
            foreach (['true_actions', 'false_actions'] as $branch) {
                $found = self::before($action['config'][$branch] ?? [], $target, $before);
                if ($found !== null) {
                    return $found;
                }
            }
            foreach (['true_actions', 'false_actions'] as $branch) {
                $before = array_merge($before, array_keys(self::flatten($action['config'][$branch] ?? [])));
            }
        }

        return null;
    }

    private static function flatten(array $actions): array
    {
        $flat = [];
        foreach ($actions as $action) {
            $flat[$action['id']] = $action;
            foreach (['true_actions', 'false_actions'] as $branch) {
                $flat += self::flatten($action['config'][$branch] ?? []);
            }
        }

        return $flat;
    }
}
