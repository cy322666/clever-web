<?php

namespace App\Services\Workflows;

use App\Models\Workflows\{Workflow, WorkflowRun};
use App\Workflows\Context\WorkflowContext;
use Illuminate\Support\Facades\{Auth, DB};
use Illuminate\Validation\ValidationException;
use Leek\FilamentWorkflows\Engine\WorkflowExecutor;
use Leek\FilamentWorkflows\Enums\TriggerType;
use Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob;
use Leek\FilamentWorkflows\Services\WorkflowRateLimiter;
use Throwable;

/** A real queued run from one manual start, independent of scheduled activation. */
final class WorkflowEditorRunService
{
    public function start(int $workflowId, ?string $startId = null): WorkflowRun
    {
        $owner = (int) Auth::id();
        abort_unless($owner > 0, 403);
        $connection = config('filament-workflows.queue.connection') ?: config('queue.default');
        if (config('queue.connections.'.$connection.'.driver') === 'sync') {
            throw ValidationException::withMessages(['manualRun' => 'Для запуска потока нужна фоновая очередь.']);
        }

        [$run, $created] = DB::transaction(function () use ($workflowId, $startId, $owner): array {
            $workflow = Workflow::query()->where('user_id', $owner)->lockForUpdate()->findOrFail($workflowId);
            app(WorkflowSubscriptionAccess::class)->assertCanExecute($owner);
            $starts = WorkflowStartNodes::ofType($workflow->definition, 'manual');
            if ($startId === null && count($starts) === 1) $startId = array_key_first($starts);
            if (!$startId || !isset($starts[$startId])) {
                throw ValidationException::withMessages(['manualRun' => $starts ? 'Выберите ручной запуск.' : 'Добавьте ноду «Ручной запуск» и соедините её с потоком.']);
            }
            $issues = WorkflowDefinitionValidator::issues($workflow->definition);
            if ($issues !== []) throw ValidationException::withMessages(['manualRun' => $issues]);
            if (WorkflowGraph::targets(WorkflowGraph::connections($workflow->definition), $startId) === []) {
                throw ValidationException::withMessages(['manualRun' => 'Ручной запуск не соединён с действиями.']);
            }

            // Serialize starts for this workflow. Double clicks and a second editor
            // return the same active run instead of duplicating business writes.
            $existing = WorkflowRun::query()->where('workflow_id', $workflowId)->where('user_id', $owner)
                ->whereIn('status', ['pending', 'running', 'paused'])
                ->where('context_data->trigger_data->source', 'workflow-editor')
                ->where('context_data->trigger_data->_workflow_start_node_id', $startId)
                ->latest('id')->first();
            if ($existing) return [$existing, false];
            $limit = app(WorkflowRateLimiter::class)->canExecute($workflow);
            if (!$limit['allowed']) throw ValidationException::withMessages(['manualRun' => $limit['reason'] ?? 'Лимит запусков. Попробуйте позже.']);

            $run = app(WorkflowExecutor::class)->start($workflow, null, TriggerType::MANUAL, $owner);
            $context = (new WorkflowContext([
                'source' => 'workflow-editor', 'is_manual' => true,
                '_workflow_start_node_id' => $startId, 'triggered_at' => now()->toIso8601String(),
            ]))->setWorkflowId($workflowId)->setWorkflowRunId((int) $run->id)
                ->setTriggerSource(TriggerType::MANUAL->value)->setTriggeredBy($owner)
                ->setVariable('_snapshot_workflow_id', $workflowId)
                ->setVariable('_definition_snapshot', $workflow->definition)
                ->setVariable('_node_names', WorkflowExpressionCatalog::nodeNames($workflow->definition['actions'] ?? [], $workflow->definition));
            $run->update(['context_data' => $context->toArray()]);
            return [$run, true];
        });

        // Publish only after the row and immutable definition have committed.
        if ($created) {
            try {
                ExecuteWorkflowJob::dispatch((int) $run->id);
            } catch (Throwable $error) {
                $run->markFailed('Не удалось поставить поток в очередь.');
                throw $error;
            }
        }
        return $run;
    }
}
