<?php

namespace App\Workflows\Engine;

use App\Services\Core\AlertService;
use App\Workflows\Context\WorkflowContext as AppWorkflowContext;
use App\Workflows\FailureStrategies;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Actions\FlowControl\ConditionAction;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use Leek\FilamentWorkflows\Engine\WorkflowExecutor as BaseWorkflowExecutor;
use Leek\FilamentWorkflows\Enums\TriggerType;
use Leek\FilamentWorkflows\Models\WorkflowRun;
use Throwable;

class WorkflowExecutor extends BaseWorkflowExecutor
{
    public function __construct(ActionRegistry $actionRegistry)
    {
        parent::__construct($actionRegistry);
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     *
     * @throws Exception
     */
    protected function executeSteps(array $steps, WorkflowContext $context, WorkflowRun $run): void
    {
        foreach ($steps as $index => $step) {
            $run->update(['current_step_index' => $index]);

            $type = $step['type'] ?? 'task';

            if ($type === 'condition' || $type === 'control-condition' || ($step['componentType'] ?? 'task') === 'control-condition') {
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

        $validation = $this->validateActionConfig($action, $config);
        if (!$validation['valid']) {
            return [
                'success' => false,
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
        return $this->failureStrategy($run) !== FailureStrategies::CONTINUE;
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
            return AppWorkflowContext::fromArray($run->context_data, $triggerModel);
        }

        return (new AppWorkflowContext)
            ->setWorkflowId($run->workflow_id)
            ->setWorkflowRunId($run->id)
            ->setTriggerSource($this->scalarValue($run->trigger_source ?? TriggerType::MANUAL))
            ->setTriggeredBy($run->triggered_by)
            ->setTriggerModel($triggerModel);
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
