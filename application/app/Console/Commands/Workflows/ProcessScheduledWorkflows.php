<?php

namespace App\Console\Commands\Workflows;

use App\Services\Workflows\WorkflowStartNodes;
use App\Workflows\Context\WorkflowContext;
use App\Workflows\Triggers\ScheduleTrigger;
use Illuminate\Support\Facades\Cache;
use Leek\FilamentWorkflows\Engine\WorkflowExecutor;
use Leek\FilamentWorkflows\Enums\TriggerType;
use Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob;
use Leek\FilamentWorkflows\Services\WorkflowRateLimiter;

class ProcessScheduledWorkflows extends \Leek\FilamentWorkflows\Commands\ProcessScheduledWorkflowsCommand
{
    public function handle(WorkflowExecutor $executor): int
    {
        $trigger = new ScheduleTrigger;
        $now = now();
        $model = config('filament-workflows.models.workflow');
        foreach ($model::query()->active()->cursor() as $workflow) {
            foreach (WorkflowStartNodes::ofType($workflow->definition ?? [], 'schedule') as $id => $start) {
                try {
                    if (! $trigger->shouldTrigger($start['config'] ?? [], null, ['check_time' => $now])) {
                        continue;
                    }
                    if ($this->option('dry-run')) {
                        $this->line("{$workflow->id}: {$id}");

                        continue;
                    }
                    $lock = Cache::lock("workflow:schedule:{$workflow->id}:{$id}:{$now->format('YmdHi')}", 90);
                    if (! $lock->get()) {
                        continue;
                    }
                    try {
                        if (! app(WorkflowRateLimiter::class)->canExecute($workflow)['allowed']) {
                            $lock->release();

                            continue;
                        }
                        $run = $executor->start($workflow, null, TriggerType::SCHEDULE);
                        $context = WorkflowContext::fromArray($run->context_data ?? []);
                        $context->setTriggerData(array_merge($context->getTriggerData(), ['_workflow_start_node_id' => $id, 'scheduled_at' => $now->toIso8601String()]));
                        $run->update(['context_data' => $context->toArray()]);
                        if ($this->option('sync')) {
                            $executor->execute($run);
                        } else {
                            ExecuteWorkflowJob::dispatch($run->id);
                        }
                    } catch (\Throwable $e) {
                        $lock->release();
                        throw $e;
                    }
                } catch (\Throwable $e) {
                    $this->error("Сценарий {$workflow->id}: {$e->getMessage()}");
                }
            }
        }

        return self::SUCCESS;
    }
}
