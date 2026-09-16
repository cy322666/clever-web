<?php

namespace App\Workflows\Engine;

use App\Services\Core\AlertService;
use App\Services\Workflows\WorkflowGraph;
use App\Services\Workflows\WorkflowSubscriptionAccess;
use App\Workflows\Context\WorkflowContext as AppWorkflowContext;
use App\Workflows\FailureStrategies;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Actions\FlowControl\ConditionAction;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use Leek\FilamentWorkflows\Engine\WorkflowExecutor as BaseWorkflowExecutor;
use Leek\FilamentWorkflows\Enums\TriggerType;
use Leek\FilamentWorkflows\Enums\StepStatus;
use Leek\FilamentWorkflows\Exceptions\NonRetryableWorkflowException;
use Leek\FilamentWorkflows\Models\WorkflowRun;
use Leek\FilamentWorkflows\Models\WorkflowRunStep;
use Leek\FilamentWorkflows\Models\Workflow as BaseWorkflow;
use Throwable;

class WorkflowExecutor extends BaseWorkflowExecutor
{
    public function __construct(ActionRegistry $actionRegistry)
    {
        parent::__construct($actionRegistry);
    }

    public function getLastException(): ?Throwable
    {
        $error = parent::getLastException();
        // Also covers already serialized jobs with the old three-attempt setting.
        return $error === null || $error instanceof NonRetryableWorkflowException
            ? $error : new NonRetryableWorkflowException($error->getMessage(), 0, $error);
    }

    public function start(
        BaseWorkflow $workflow,
        ?Model $triggerModel = null,
        ?TriggerType $triggerSource = null,
        ?int $triggeredBy = null,
    ): WorkflowRun {
        app(WorkflowSubscriptionAccess::class)->assertCanExecute($this->workflowUserId($workflow));

        return parent::start($workflow, $triggerModel, $triggerSource, $triggeredBy);
    }

    public function execute(WorkflowRun $run): WorkflowRun
    {
        if (! $run->isTerminal()) {
            try {
                app(WorkflowSubscriptionAccess::class)->assertCanExecute(
                    $this->workflowUserId($run->workflow),
                );
            } catch (NonRetryableWorkflowException $exception) {
                $this->lastException = $exception;
                $run->markFailed($exception->getMessage());

                Log::warning('Workflow execution blocked by subscription', [
                    'workflow_id' => $run->workflow_id,
                    'run_ulid' => $run->ulid,
                ]);

                return $run->fresh() ?? $run;
            }
        }

        return parent::execute($run);
    }

    private function workflowUserId(BaseWorkflow $workflow): int
    {
        return (int) ($workflow->{config('filament-workflows.tenancy.column', 'user_id')} ?? 0);
    }

    protected function executeStep(array $step, WorkflowContext $context, WorkflowRun $run): array
    {
        $logs = $run->steps()->where('step_id', $step['id']);
        // Protect against duplicate delivery; never repeat an already completed write.
        $completed = (clone $logs)->where('action_type', $step['type'] ?? null)->where('status', StepStatus::COMPLETED)->latest('id')->first();
        if ($completed) {
            $context->setStepOutput($step['id'], $completed->output_data ?? []);
            return ['success' => true, 'output' => $completed->output_data ?? [], 'reused' => true];
        }
        $attempt = (clone $logs)->count() + 1;
        $context->forgetVariable('_resolved_inputs.'.$step['id']);
        try {
            $result = parent::executeStep($step, $context, $run);
            $last = (clone $logs)->latest('id')->first();
            if ($last) {
                $fields = ['attempt_number' => $attempt];
                // The base executor otherwise discards the HTTP exchange on a failed request.
                if (isset($result['output'])) $fields['output_data'] = is_array($result['output']) ? $result['output'] : ['value' => $result['output']];
                $resolvedInput = $context->getVariable('_resolved_inputs.'.$step['id']);
                if (is_array($resolvedInput)) $fields['input_data'] = array_merge($last->input_data ?? [], ['_resolved_input' => $resolvedInput]);
                $last->update($fields);
            }
            if (!($result['success'] ?? false)) {
                throw new NonRetryableWorkflowException($result['error'] ?? 'Ошибка выполнения ноды.');
            }
            return $result;
        } finally {
            // Keep resolved inputs and completed outputs even when a later node fails.
            $run->update(['context_data' => $context->toArray()]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     *
     * @throws Exception
     */
    protected function executeSteps(array $steps, WorkflowContext $context, WorkflowRun $run): void
    {
        $issues = \App\Services\Workflows\WorkflowDefinitionValidator::issues($run->workflow->definition ?? []);
        if ($issues !== []) throw new \Leek\FilamentWorkflows\Exceptions\NonRetryableWorkflowException(implode("\n", $issues));
        if (array_key_exists('connections', $run->workflow->definition ?? [])) {
            $this->executeGraph($context, $run);
            return;
        }
        foreach ($steps as $index => $step) {
            $run->update(['current_step_index' => $index]);

            $type = $step['type'] ?? 'task';
            $isCondition = $type === 'condition' || $type === 'control-condition' || ($step['componentType'] ?? 'task') === 'control-condition';

            if ($step['disabled'] ?? false) {
                $this->logSkippedStep($step, $run);

                // Bypass a disabled condition through its first (true) output, without evaluating it.
                $config = $step['config'] ?? $step['properties'] ?? [];
                if ($isCondition && ($config['has_true_branch'] ?? true)) {
                    $this->executeSteps(ConditionAction::getBranchActions($config, true), $context, $run);
                }

                continue;
            }

            if ($isCondition) {
                $this->executeConditionStep($step, $context, $run);

                continue;
            }

            $result = $this->executeStep($step, $context, $run);

            if (!$result['success']) {
                if ($this->shouldStopOnFailure($run)) {
                    throw new Exception(
                        $result['error'] ?? __(
                        'filament-workflows::workflows.executor.errors.step_failed_without_message'
                    )
                    );
                }

                Log::warning('Workflow step failed with CONTINUE strategy', [
                    'step_id' => $step['id'] ?? 'unknown',
                    'error' => $result['error'] ?? __('filament-workflows::workflows.executor.errors.unknown_error'),
                ]);
            }

            if (isset($step['id'], $result['output'])) {
                $context->setStepOutput($step['id'], $result['output']);
            }
        }

        $run->update(['context_data' => $context->toArray()]);
    }

    protected function executeGraph(WorkflowContext $context, WorkflowRun $run): void
    {
        $definition = $run->workflow->definition;
        $connections = WorkflowGraph::connections($definition);
        $start = \App\Services\Workflows\WorkflowStartNodes::selected($definition, $context->getTriggerData());
        $active = array_fill_keys(WorkflowGraph::targets($connections, $start), true);
        $index = 0;
        foreach (WorkflowGraph::ordered($definition) as $id => $entry) {
            if (!isset($active[$id])) continue;
            $step = $entry['step'];
            unset($step['config']['true_actions'], $step['config']['false_actions']);
            $run->update(['current_step_index' => $index++]);
            if ($step['disabled'] ?? false) {
                $this->logSkippedStep($step, $run);
                $result = ['success' => true, 'output' => ['passed' => true]];
            } else {
                $result = $this->executeStep($step, $context, $run);
                if (!$result['success'] && $this->shouldStopOnFailure($run)) {
                    throw new Exception($result['error'] ?? 'Не удалось выполнить ноду.');
                }
                if (isset($result['output'])) $context->setStepOutput($step['id'], $result['output']);
            }
            if (WorkflowGraph::condition($step) && !$result['success']) continue;
            $port = WorkflowGraph::condition($step) ? (($result['output']['passed'] ?? false) ? 'yes' : 'no') : 'output';
            foreach (WorkflowGraph::targets($connections, $id, $port) as $target) $active[$target] = true;
        }
        $run->update(['context_data' => $context->toArray()]);
    }

    /** @param array<string, mixed> $step */
    protected function logSkippedStep(array $step, WorkflowRun $run): void
    {
        $stepModelClass = config('filament-workflows.models.workflow_run_step', WorkflowRunStep::class);
        $stepModelClass::create([
            'workflow_run_id' => $run->id,
            'step_id' => $step['id'] ?? 'step_' . Str::ulid(),
            'step_type' => $step['componentType'] ?? 'task',
            'action_type' => $step['type'] ?? null,
            'status' => StepStatus::SKIPPED,
            'attempt_number' => 1,
            'input_data' => $step['config'] ?? $step['properties'] ?? [],
            'output_data' => ['reason' => 'node_disabled'],
            'completed_at' => now(),
            'duration_ms' => 0,
        ]);
    }

    /**
     * @param array<string, mixed> $step
     *
     * @throws Exception
     */
    protected function executeConditionStep(array $step, WorkflowContext $context, WorkflowRun $run): void
    {
        $result = $this->executeStep($step, $context, $run);

        if (!$result['success']) {
            if ($this->shouldStopOnFailure($run)) {
                throw new Exception(
                    $result['error'] ?? __('filament-workflows::workflows.executor.errors.condition_evaluation_failed')
                );
            }

            return;
        }

        $output = $result['output'] ?? [];
        $passed = $output['passed'] ?? false;
        $config = $step['config'] ?? $step['properties'] ?? [];
        $branchEnabled = (bool)$passed
            ? (bool)($config['has_true_branch'] ?? true)
            : (bool)($config['has_false_branch'] ?? false);
        $branchActions = $branchEnabled ? ConditionAction::getBranchActions($config, (bool)$passed) : [];

        if (isset($step['id'])) {
            $context->setStepOutput($step['id'], $output);
        }

        if ($branchActions !== []) {
            Log::info('Executing condition branch', [
                'branch' => $passed ? 'true' : 'false',
                'action_count' => count($branchActions),
            ]);

            $this->executeSteps($branchActions, $context, $run);
        }
    }

    /**
     * @param array<string, mixed> $step
     * @return array{success: bool, output?: mixed, error?: string}
     */
    protected function runActionStep(array $step, WorkflowContext $context): array
    {
        $actionType = $step['type'] ?? null;

        if (!$actionType) {
            return [
                'success' => false,
                'error' => __('filament-workflows::workflows.executor.errors.missing_action_type'),
            ];
        }

        if (!$this->actionRegistry->has($actionType)) {
            return [
                'success' => false,
                'error' => __('filament-workflows::workflows.executor.errors.unknown_action_type', ['type' => $actionType]),
            ];
        }

        $action = $this->actionRegistry->resolve($actionType);
        $rawConfig = $step['config'] ?? $step['properties'] ?? [];

        /** @var array<string, mixed> $config */
        $config = $context->resolve($rawConfig);
        $config = $this->normalizeStepDelayConfig($config);
        if (isset($step['id'])) {
            $context->setVariable('_resolved_inputs.' . $step['id'], array_diff_key($config, array_flip(['true_actions', 'false_actions'])));
        }

        $validation = $action instanceof \App\Workflows\Actions\ControlConditionAction
            ? $action->validateResolvedConfig($rawConfig, $config)
            : $this->validateActionConfig($action, $config);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'retryable' => false,
                'error' => __('filament-workflows::workflows.executor.errors.config_validation_failed', ['errors' => implode(', ', $validation['errors'])]),
            ];
        }

        $this->sleepBeforeStep($config);

        return $this->executeAction($action, $config, $context);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function normalizeStepDelayConfig(array $config): array
    {
        $delay = is_array($config['delay'] ?? null) ? $config['delay'] : [];
        $mode = (string)($delay['mode'] ?? 'immediate');

        if ($mode !== 'after_seconds') {
            $config['delay'] = ['mode' => 'immediate'];

            return $config;
        }

        $seconds = min(30, max(1, (int)($delay['seconds'] ?? 0)));
        $config['delay'] = [
            'mode' => 'after_seconds',
            'seconds' => $seconds,
        ];

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function sleepBeforeStep(array $config): void
    {
        $delay = is_array($config['delay'] ?? null) ? $config['delay'] : [];

        if (($delay['mode'] ?? 'immediate') !== 'after_seconds') {
            return;
        }

        $seconds = min(30, max(1, (int)($delay['seconds'] ?? 0)));

        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    protected function handleException(WorkflowRun $run, Throwable $exception): void
    {
        parent::handleException($run, $exception);

        if ($this->failureStrategy($run) !== FailureStrategies::TELEGRAM_REPORT) {
            return;
        }

        $run = $run->fresh(['workflow']) ?? $run;

        AlertService::critical(
            title: 'Процесс: ошибка выполнения',
            message: implode("\n", [
                'Процесс упал и остановлен.',
                'Название: ' . ($run->workflow?->name ?? '-'),
                'Run: ' . ($run->ulid ?? $run->id),
            ]),
            context: [
                'workflow_id' => $run->workflow_id,
                'run_id' => $run->id,
                'run_ulid' => $run->ulid,
                'current_step_index' => $run->current_step_index,
                'trigger_source' => $this->scalarValue($run->trigger_source),
                'error' => Str::limit($exception->getMessage(), 1000, '...'),
            ],
            dedupeKey: 'workflow:telegram-report:run:' . $run->id,
            ttlSeconds: 86400,
        );
    }

    protected function shouldStopOnFailure(WorkflowRun $run): bool
    {
        return true;
    }

    protected function failureStrategy(WorkflowRun $run): string
    {
        $strategy = $run->workflow->failure_strategy ?? FailureStrategies::STOP;

        if ($strategy instanceof \BackedEnum) {
            return (string)$strategy->value;
        }

        return (string)$strategy;
    }

    protected function scalarValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string)$value->value;
        }

        return (string)$value;
    }

    protected function buildContext(WorkflowRun $run): WorkflowContext
    {
        $triggerModel = null;

        if ($run->triggerable_type && $run->triggerable_id) {
            $triggerModel = $run->triggerable;
        }

        if ($run->context_data) {
            $context = AppWorkflowContext::fromArray($run->context_data, $triggerModel);
        } else {
            $context = (new AppWorkflowContext)
                ->setWorkflowId($run->workflow_id)
                ->setWorkflowRunId($run->id)
                ->setTriggerSource($this->scalarValue($run->trigger_source ?? TriggerType::MANUAL))
                ->setTriggeredBy($run->triggered_by)
                ->setTriggerModel($triggerModel);
        }

        if ((int) $context->getVariable('_snapshot_workflow_id') !== (int) $run->workflow_id) {
            $context->setVariable('_definition_snapshot', $run->workflow->definition);
            $context->setVariable('_snapshot_workflow_id', $run->workflow_id);
            $context->setVariable('_node_names', \App\Services\Workflows\WorkflowExpressionCatalog::nodeNames($run->workflow->definition['actions'] ?? [], $run->workflow->definition));
            $run->update(['context_data' => $context->toArray()]);
        }

        $triggerData = $context->getTriggerData();
        if (($triggerData['source'] ?? '') === 'amocrm-button' && !($context->getVariable('_dry_run') || $context->getVariable('_test_mode'))) {
            $hydrated = app(\App\Services\Workflows\WorkflowButtonEntitySnapshot::class)->hydrate($triggerData, (int) $run->workflow->user_id);
            if ($hydrated !== $triggerData) {
                $context->setTriggerData($hydrated);
                $run->update(['context_data' => $context->toArray()]);
            }
        }

        // A retry belongs to the same execution, not to a later edit of the workflow.
        $snapshot = $context->getVariable('_definition_snapshot');
        if (is_array($snapshot)) {
            $workflow = clone $run->workflow;
            $workflow->definition = $snapshot;
            $run->setRelation('workflow', $workflow);
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $childContextData
     */
    protected function buildChildContext(WorkflowRun $childRun, array $childContextData): WorkflowContext
    {
        $triggerModel = null;

        if ($childRun->triggerable_type && $childRun->triggerable_id) {
            $triggerModel = $childRun->triggerable;
        }

        $triggerData = (array)($childContextData['trigger_data'] ?? []);
        $triggerData['event'] = 'workflow-called';
        $triggerData['source_workflow_id'] = $childContextData['source_workflow_id'] ?? null;
        $triggerData['source_workflow_run_id'] = $childContextData['source_workflow_run_id'] ?? null;

        $context = (new AppWorkflowContext($triggerData))
            ->setWorkflowId($childRun->workflow_id)
            ->setWorkflowRunId($childRun->id)
            ->setTriggerSource('workflow-called')
            ->setTriggeredBy($childRun->triggered_by)
            ->setTriggerModel($triggerModel);

        $context->setVariable('_chain_depth', $childContextData['_chain_depth'] ?? 1);
        $context->setVariable('_chain_id', $childContextData['_chain_id'] ?? null);
        $context->setVariable('_workflow_chain_ids', $childContextData['_workflow_chain_ids'] ?? []);
        $context->setVariable('source_workflow_id', $childContextData['source_workflow_id'] ?? null);
        $context->setVariable('source_workflow_run_id', $childContextData['source_workflow_run_id'] ?? null);

        foreach (($childContextData['variables'] ?? []) as $key => $value) {
            $context->setVariable((string)$key, $value);
        }

        foreach (($childContextData['step_outputs'] ?? []) as $stepId => $output) {
            $context->setStepOutput((string)$stepId, $output);
        }

        return $context;
    }
}
