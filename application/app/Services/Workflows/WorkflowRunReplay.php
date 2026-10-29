<?php

namespace App\Services\Workflows;

use App\Models\Workflows\WorkflowRun;
use Illuminate\Database\Eloquent\Builder;

/** Loads a historical execution for inspection and optional restoration into its workflow. */
final class WorkflowRunReplay
{
    /** Rebuild the data before a recorded pass; never reuse a later loop item. */
    public static function contextAt(array $context, array $results, int $index, ?string $targetId = null): array
    {
        if (!isset($results[$index])) throw new \InvalidArgumentException('Выбранная запись больше недоступна. Выберите запись заново.');
        $selected = $results[$index];
        $rounds = array_column($selected['iteration'] ?? [], null, 'node');
        $context['step_outputs'] = [];
        $context['variables']['_workflow_values'] = [];
        unset($context['variables']['_resolved_inputs'], $context['variables']['condition_result']);
        $nodes = WorkflowGraph::nodes($context['variables']['_definition_snapshot']['actions'] ?? []);
        foreach ($nodes as $node) {
            if (in_array($node['step']['type'] ?? '', ['condition', 'control-condition'], true)) {
                unset($context['variables'][$node['step']['config']['context_key'] ?? 'condition_result']);
            }
        }
        foreach ($results as $result) {
            if (($result['output']['action'] ?? null) === 'found' && is_string($key = $result['output']['context_key'] ?? null)) {
                unset($context['variables'][$key], $context['variables'][$key.'_id'], $context['variables'][$key.'_exists']);
            }
        }
        $end = $index + (($targetId !== null && $targetId !== $selected['id']) ? 1 : 0);
        foreach (array_slice($results, 0, $end) as $result) {
            foreach ($result['iteration'] ?? [] as $round) {
                if (!isset($rounds[$round['node']]) || $rounds[$round['node']]['index'] !== $round['index']) continue 2;
            }
            if (($result['output_recorded'] ?? true) === false) continue;
            $context['step_outputs'][$result['id']] = $result['output'] ?? [];
            if (($result['type'] ?? '') === 'workflow_set_variable') {
                $context['variables']['_workflow_values'] = array_replace($context['variables']['_workflow_values'], $result['output'] ?? []);
            }
            $config = $nodes['action:'.$result['id']]['step']['config'] ?? [];
            if (in_array($result['type'] ?? '', ['condition', 'control-condition'], true) && ($config['store_result'] ?? true)) {
                $context['variables'][$config['context_key'] ?? 'condition_result'] = array_intersect_key($result['output'] ?? [], array_flip(['passed', 'branch', 'condition_results']));
            }
            if (($result['output']['action'] ?? null) === 'found' && is_string($key = $result['output']['context_key'] ?? null)) {
                $output = $result['output'];
                $context['variables'][$key] = ['exists' => $output['found'] ?? false, 'id' => $output['entity_id'] ?? null, 'type' => $output['entity_type'] ?? null, 'data' => null];
                $context['variables'][$key.'_id'] = $output['entity_id'] ?? null;
                $context['variables'][$key.'_exists'] = $output['found'] ?? false;
            }
        }
        foreach ($rounds as $node => $round) {
            if (($round['kind'] ?? '') === 'feedback') continue;
            $output = $context['step_outputs'][$node] ?? [];
            $items = $output['items'] ?? null;
            if (!is_array($items) || !array_key_exists($round['index'], $items)) {
                throw new \InvalidArgumentException('Элемент этого прохода не сохранён. Выберите другую запись или подготовьте входные данные.');
            }
            $context['step_outputs'][$node] = ['_workflow_loop' => true, 'item' => $items[$round['index']], 'index' => $round['index']];
        }
        return $context;
    }

    public static function ownedRuns(int $userId): Builder
    {
        return WorkflowRun::query()->where('user_id', $userId)
            ->whereHas('workflow', fn (Builder $query) => $query->where('user_id', $userId));
    }

    public static function load(int $runId, int $userId, ?int $workflowId = null): array
    {
        $run = self::ownedRuns($userId)->when($workflowId !== null, fn (Builder $query) => $query->where('workflow_id', $workflowId))
            ->with(['workflow', 'steps'])->findOrFail($runId);
        $definition = data_get($run->context_data, 'variables._definition_snapshot');
        abort_unless(is_array($definition) && is_array($definition['actions'] ?? null), 409,
            'У этого запуска нет сохранённой схемы. Открыть его в редакторе без подмены текущей схемой нельзя.');

        $graph = WorkflowExecutionGraph::fromRun($run);
        $results = array_map(fn (array $result) => array_merge($result, [
            'historical' => true,
            'status' => $result['status'] === 'failed' ? 'error' : $result['status'],
        ]), $graph['results']);

        return [
            'run_id' => $run->getKey(),
            'workflow_id' => $run->workflow_id,
            'name' => $run->workflow->name.' · запуск #'.$run->getKey(),
            'started_at' => ($run->started_at ?? $run->created_at)?->timezone('Europe/Moscow')->format('d.m.Y H:i:s'),
            'definition' => $definition,
            'input' => $graph['trigger_data'],
            'results' => $results,
            // For the inspector and explicit single-node execution. A new full-flow run starts clean.
            'context' => $run->context_data ?? [],
        ];
    }
}
