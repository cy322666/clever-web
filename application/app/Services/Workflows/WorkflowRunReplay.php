<?php

namespace App\Services\Workflows;

use App\Models\Workflows\WorkflowRun;
use Illuminate\Database\Eloquent\Builder;

/** Loads a historical execution for inspection and optional restoration into its workflow. */
final class WorkflowRunReplay
{
    public static function ownedRuns(int $userId): Builder
    {
        return WorkflowRun::query()->where('user_id', $userId)
            ->whereHas('workflow', fn (Builder $query) => $query->where('user_id', $userId));
    }

    public static function load(int $runId, int $userId): array
    {
        $run = self::ownedRuns($userId)->with(['workflow', 'steps'])->findOrFail($runId);
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
