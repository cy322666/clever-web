<?php

namespace App\Services\Workflows;

use Leek\FilamentWorkflows\Actions\ActionRegistry;

final class WorkflowExpressionCatalog
{
    /** Local, tenant-scoped metadata only. Typing never calls amoCRM. */
    public static function customFieldSuggestions(): array
    {
        if (!\Illuminate\Support\Facades\Auth::id()) return [];

        return WorkflowEditorReferenceQuery::for(\App\Models\amoCRM\Field::class)->where('user_id', auth()->id())->where('active', true)
            ->whereIn('entity_type', ['leads', 'contacts', 'companies', 'customers'])
            ->orderBy('field_id')->get(['field_id', 'name', 'entity_type'])
            ->map(fn ($field) => ['id' => (string) $field->field_id, 'label' => $field->name, 'entity' => $field->entity_type])
            ->all();
    }

    public static function nodeNames(array $actions, array $definition = []): array
    {
        $names = [];
        foreach (self::referenceNames($actions, $definition) as $id => $name) $names[$name] = [$id];
        return $names;
    }

    public static function referenceNames(array $actions, array $definition = []): array
    {
        $names = [];
        foreach (self::labels($actions, $definition) as $id => $label) {
            $names[$id] = self::uniqueName($label, array_values($names));
        }
        return $names;
    }

    public static function uniqueName(string $name, array $used): string
    {
        $base = trim($name) ?: 'Нода';
        $name = $base;
        for ($n = 2; in_array($name, $used, true); $n++) $name = $base.' ('.$n.')';
        return $name;
    }

    /** Persist existing reference aliases as labels, without changing what expressions resolve to. */
    public static function normalizeNames(array $definition): array
    {
        $actions = $definition['actions'] ?? [];
        $names = self::referenceNames($actions, $definition);
        $labels = self::labels($actions, $definition);
        $nodes = WorkflowGraph::nodes($actions);
        foreach ($names as $id => $name) {
            if ($name === $labels[$id]) continue;
            if ($id === 'trigger') $definition['trigger']['name'] = $name;
            elseif (isset($nodes['action:'.$id])) data_set($definition, 'actions.'.$nodes['action:'.$id]['path'].'.name', $name);
            else foreach ($definition['additional_triggers'] ?? [] as $index => $start) {
                if ($start['id'] === $id) $definition['additional_triggers'][$index]['name'] = $name;
            }
        }
        return $definition;
    }

    private static function labels(array $actions, array $definition): array
    {
        $metadata = collect(app(ActionRegistry::class)->getAllWithMetadata())->keyBy('type');
        $triggers = collect(app(\Leek\FilamentWorkflows\Triggers\TriggerRegistry::class)->getAllWithMetadata())->keyBy('type');
        $names = [];
        foreach (WorkflowStartNodes::all($definition) ?: ['trigger' => []] as $id => $start) {
            $names[$id] = $start['name'] ?? $triggers[$start['type'] ?? '']['name'] ?? 'Данные запуска';
        }
        foreach (self::flatten($actions) as $id => $action) {
            $names[$id] = $action['name'] ?? $metadata[$action['type']]['name'] ?? 'Нода';
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
        $sources = [self::source($startId, $names[$startId] ?? 'Данные запуска', WorkflowOutputView::trigger($input), array_key_exists('trigger_data', $context))];
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
            $sources[] = self::source($id, $names[$id], WorkflowOutputView::value($output), $available);
        }

        $variables = [];
        foreach ($upstream as $id) {
            if (($flat[$id]['type'] ?? '') !== 'workflow_set_variable') continue;
            foreach ($flat[$id]['config']['assignments'] ?? [] as $row) {
                $key = $row['name'] ?? null;
                if (is_string($key) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $key)) $variables[$key] = $context['variables']['_workflow_values'][$key] ?? null;
            }
        }
        if ($variables !== []) {
            $source = self::source('$vars', 'Переменные текущего прохода', $variables, true);
            foreach ($source['fields'] as &$field) {
                if ($field['expression'] !== null) $field['expression'] = '{{ $vars'.$field['key'].' }}';
                preg_match('/^\.([A-Za-z][A-Za-z0-9_]*)/', $field['key'], $match);
                $name = $match[1] ?? '';
                $field['available'] = $field['key'] === '' || array_key_exists($name, $context['variables']['_workflow_values'] ?? []);
            }
            unset($field);
            $sources[] = $source;
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
            $replacements['$('.$old.')'] = '$('.$new.')';
            $singleOld = "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $name)."'";
            $replacements['$('.$singleOld.')'] = '$('.$new.')';
            $replacements['$node['.$singleOld.'].json'] = '$node['.$new.'].json';
        }
        return preg_replace_callback('/\{\{(.*?)\}\}/su', fn ($match) => '{{'.strtr($match[1], $replacements).'}}', $value);
    }

    /** The picker renders the bounded field tree, not a full copy at every parent path. */
    public static function forPicker(array $sources): array
    {
        foreach ($sources as &$source) {
            foreach ($source['fields'] as &$field) {
                if (is_array($field['value'])) $field['value'] = null;
            }
            unset($field);
        }
        unset($source);

        return $sources;
    }

    private static function source(string $id, string $name, mixed $output, bool $available, string $rootPath = ''): array
    {
        $fields = [];
        $truncated = false;
        $visit = function (mixed $value, string $path = '', int $depth = 0, ?string $parent = null, string $label = 'Результат', bool $addressable = true) use (&$visit, &$fields, &$truncated, $name, $available): void {
            if (count($fields) >= 150 || $depth > 6) {
                $truncated = true;
                return;
            }
            $fields[] = [
                'path' => $path ?: 'Весь результат',
                'key' => $path, 'parent' => $parent, 'label' => $label, 'depth' => $depth,
                'type' => is_array($value) ? (array_is_list($value) ? 'array' : 'object') : get_debug_type($value),
                'count' => is_array($value) && $available ? count($value) : null,
                // An array can be inserted as a whole. Its [n] children are
                // preview data, not stable collection variables.
                'expression' => $addressable ? '{{ $('.json_encode($name, JSON_UNESCAPED_UNICODE).')'.$path.' }}' : null,
                'item_fields' => is_array($value) && array_is_list($value) ? self::listItemFields($value) : [],
                'value' => $available ? $value : null, 'available' => $available,
            ];
            if (! is_array($value) || array_is_list($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if ($depth === 0 && $key === 'headers') continue;
                if (preg_match('/token|password|authorization|secret/i', (string) $key)) {
                    continue;
                }
                $part = is_int($key) ? '['.$key.']' : (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $key) ? '.'.$key : '['.json_encode($key, JSON_UNESCAPED_UNICODE).']');
                $visit($child, $path.$part, $depth + 1, $path, is_int($key) ? '['.$key.']' : (string) $key,
                    $addressable && ! array_is_list($value));
            }
        };
        $visit($output, $rootPath);

        return ['id' => $id, 'name' => $name, 'available' => $available, 'fields' => $fields, 'truncated' => $truncated];
    }

    /** Relative paths for one element, never a positional [n] expression. */
    private static function listItemFields(array $items): array
    {
        $paths = [];
        $visit = function (array $object, string $prefix = '', int $depth = 0) use (&$visit, &$paths): void {
            if ($depth > 4 || count($paths) >= 100) return;
            foreach ($object as $key => $value) {
                if (!is_string($key) || !preg_match('/^[\pL\pN_.-]+$/uD', $key)
                    || preg_match('/token|password|authorization|secret/i', $key)) continue;
                $path = $prefix === '' ? $key : $prefix.'.'.$key;
                if (is_array($value) && !array_is_list($value)) $visit($value, $path, $depth + 1);
                elseif (!is_array($value)) $paths[$path] = true;
                if (count($paths) >= 100) break;
            }
        };
        foreach (array_slice($items, 0, 20) as $item) {
            if (is_array($item) && !array_is_list($item)) $visit($item);
        }
        return array_keys($paths);
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

