<?php

namespace App\Services\Workflows;

use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Models\WorkflowRun;
use Leek\FilamentWorkflows\Triggers\TriggerRegistry;

final class WorkflowExecutionGraph
{
    public static function fromRun(WorkflowRun $run): array
    {
        $results = $run->steps->sortBy('id')->values()->map(fn ($step, $index) => [
            'execution_id' => $step->getKey(),
            'id' => $step->step_id, 'type' => $step->action_type ?? $step->step_type,
            'status' => $step->status instanceof \BackedEnum ? $step->status->value : (string) $step->status,
            'input' => \Illuminate\Support\Arr::except($step->input_data ?? [], ['_resolved_input']), 'output' => $step->output_data ?? [],
            // A final context belongs to the last attempt, never substitute it into older attempts.
            'resolved_input' => data_get($step->input_data, '_resolved_input'),
            'error' => $step->error_message, 'duration_ms' => $step->duration_ms,
            'attempt' => $step->attempt_number, 'sequence' => $index + 1,
            'started_at' => $step->started_at?->toIso8601String(),
        ])->all();
        $definition = data_get($run->context_data, 'variables._definition_snapshot');
        $fallback = ! is_array($definition);
        if ($fallback) {
            $definition = ['trigger' => ['type' => 'execution'], 'actions' => []];
            foreach ($results as $result) {
                $definition['actions'][$result['id']] = ['id' => $result['id'], 'type' => $result['type'], 'config' => []];
            }
            $definition['actions'] = array_values($definition['actions']);
        }
        $graph = self::build($definition, $results, data_get($run->context_data, 'trigger_data._workflow_start_node_id', 'trigger'));
        $graph['historical_fallback'] = $fallback;
        if ($fallback) {
            // Without a saved definition, display only the known chronological path, not today's workflow.
            $graph['edges'] = [];
            $source = 'trigger';
            $previous = null;
            foreach ($graph['nodes'] as $index => &$node) {
                $node['x'] = $index * 224;
                $node['y'] = 96;
                if ($node['id'] === 'trigger') {
                    continue;
                }
                $port = $previous && self::condition($previous['type'])
                    ? ((bool) ($previous['output']['passed'] ?? false) ? 'yes' : 'no') : 'output';
                $graph['edges'][] = ['sourceId' => $source, 'sourcePort' => $port, 'targetId' => $node['id'], 'active' => true];
                $source = $node['id'];
                $previous = $node['result'];
            }
            unset($node);
        }
        $graph['trigger_data'] = data_get($run->context_data, 'trigger_data', []);

        return $graph;
    }

    public static function build(array $definition, array $results = [], string $startId = 'trigger'): array
    {
        $metadata = collect(app(ActionRegistry::class)->getAllWithMetadata())->keyBy('type')->all();
        $resultsById = collect($results)->keyBy('id')->all();
        $attemptCounts = collect($results)->countBy('id')->all();
        $itemCounts = collect($resultsById)->map(fn ($result) => self::itemCount($result))->all();
        $actions = $definition['actions'] ?? [];
        $nodes = [];
        foreach (WorkflowStartNodes::all($definition) ?: ['trigger' => ['type' => 'manual']] as $id => $trigger) {
            $triggerClass = app(TriggerRegistry::class)->has($trigger['type'] ?? '') ? app(TriggerRegistry::class)->get($trigger['type']) : null;
            $nodes[] = ['id' => $id, 'type' => $trigger['type'] ?? 'manual', 'name' => $trigger['name'] ?? ($triggerClass ? $triggerClass::name() : 'Запуск'),
                'icon' => $triggerClass ? $triggerClass::icon() : 'heroicon-o-play', 'x' => 0, 'y' => count($nodes) * 208 + self::span($actions) * 100 - 48,
                'status' => $results === [] || $id !== $startId ? 'pending' : 'completed', 'result' => null, 'action' => $trigger];
        }
        $place = function (array $steps, int $column, int $top, int $span) use (&$place, &$nodes, $metadata, $resultsById, $itemCounts, $attemptCounts): int {
            foreach ($steps as $step) {
                $type = $step['type'] ?? '';
                $id = (string) $step['id'];
                $result = $resultsById[$id] ?? null;
                $nodes[] = ['id' => 'action:'.$id, 'type' => $type,
                    'name' => $step['name'] ?? $metadata[$type]['name'] ?? 'Нода',
                    'icon' => $metadata[$type]['icon'] ?? 'heroicon-o-cube',
                    'x' => $column * 224, 'y' => ($top + $span / 2) * 200 - 48,
                    'status' => $result['status'] ?? 'pending', 'item_count' => $itemCounts[$id] ?? 0,
                    'execution_count' => $attemptCounts[$id] ?? 0, 'result' => $result, 'action' => $step];
                $column++;
                if (self::condition($type)) {
                    $yes = $step['config']['true_actions'] ?? [];
                    $no = $step['config']['false_actions'] ?? [];
                    $yesSpan = self::span($yes);
                    $column = max($place($yes, $column, $top, $yesSpan), $place($no, $column, $top + $yesSpan, self::span($no)));
                }
            }

            return $column;
        };
        $place($actions, 1, 0, self::span($actions));
        $edges = array_values(array_filter(WorkflowCanvasGraph::edges($actions, $definition['connections'] ?? null), fn ($edge) => $edge['targetId'] !== null));
        foreach ($edges as &$edge) {
            $source = $resultsById[substr($edge['sourceId'], 7)] ?? null;
            $target = $resultsById[substr($edge['targetId'], 7)] ?? null;
            $edge['active'] = $target && ($edge['sourceId'] === $startId || $source);
            if ($source && in_array($edge['sourcePort'], ['yes', 'no'], true)) {
                $passed = ($source['status'] === 'skipped') || (bool) ($source['output']['passed'] ?? $source['condition_result'] ?? false);
                $edge['active'] = $edge['active'] && $edge['sourcePort'] === ($passed ? 'yes' : 'no');
            }
        }
        unset($edge);
        $names = array_column($nodes, 'name', 'id');
        $occurrences = [];
        foreach ($results as &$result) {
            $occurrences[$result['id']] = ($occurrences[$result['id']] ?? 0) + 1;
            $result['occurrence'] = $occurrences[$result['id']];
            $result['occurrence_count'] = $attemptCounts[$result['id']] ?? 1;
            $result['name'] ??= $names['action:'.$result['id']] ?? $metadata[$result['type']]['name'] ?? 'Нода';
            $result['explanation'] = WorkflowExecutionExplanation::forResult($result);
        }
        unset($result);

        return ['nodes' => $nodes, 'edges' => $edges, 'results' => $results, 'historical_fallback' => false];
    }

    private static function itemCount(array $result): int
    {
        if (($result['status'] ?? '') !== 'completed') return 0;
        $output = $result['output'] ?? [];
        if (is_array($output['items'] ?? null)) return count($output['items']);
        if (is_array($output) && array_is_list($output)) return count($output);
        return 1;
    }

    private static function span(array $steps): int
    {
        $span = 1;
        foreach ($steps as $step) {
            if (self::condition($step['type'] ?? '')) {
                $span = max($span, self::span($step['config']['true_actions'] ?? []) + self::span($step['config']['false_actions'] ?? []));
            }
        }

        return $span;
    }

    private static function condition(string $type): bool
    {
        return in_array($type, ['condition', 'control-condition'], true);
    }
}
