<?php

namespace App\Workflows\Engine;

use App\Services\Workflows\WorkflowGraph;
use App\Services\Workflows\WorkflowSubscriptionAccess;
use App\Workflows\Context\WorkflowContext;
use InvalidArgumentException;
use Throwable;

/** Executes exactly one queued node per call; branch children are queued only after evaluation. */
class WorkflowDebugger extends WorkflowTestRunner
{
    public function executeNode(array $step, array $contextData, array $input, ?int $workflowId, ?int $userId, bool $real = false, array $definition = []): array
    {
        $session = $this->start(['trigger' => ['type' => 'manual'], 'actions' => [$step], 'connections' => [['sourceId' => 'trigger', 'sourcePort' => 'output', 'targetId' => 'action:'.$step['id']]]], array_diff_key($input, ['_workflow_start_node_id' => true]), $workflowId, $userId, $real);
        // Reuse data, not an old run identity or its test source/mode.
        $context = WorkflowContext::fromArray($contextData ?: $session['context'])
            ->setWorkflowId($workflowId)->setWorkflowRunId(null)->setTriggeredBy($userId)->setTriggerSource('debug')
            ->setVariable('_capture_amo_exchange', true);
        if ($definition !== []) {
            $context->setVariable('_node_names', \App\Services\Workflows\WorkflowExpressionCatalog::nodeNames($definition['actions'] ?? [], $definition));
        }
        $session['context'] = $context->toArray();
        return $this->advance($session);
    }

    public function start(array $definition, array $input, ?int $workflowId, ?int $userId, bool $real = false): array
    {
        if ($real) {
            app(WorkflowSubscriptionAccess::class)->assertCanExecute((int) $userId);
        }

        if (empty($definition['trigger']) || empty($definition['actions'])) {
            throw new InvalidArgumentException('Добавьте запуск и хотя бы одну ноду.');
        }
        $context = (new WorkflowContext($input))->setTriggerData($input)->setTriggerSource('debug')
            ->setWorkflowId($workflowId)->setTriggeredBy($userId);
        $context->setVariable('_dry_run', ! $real)->setVariable('_test_mode', ! $real)->setVariable('_capture_amo_exchange', true);
        $context->setVariable('_node_names', \App\Services\Workflows\WorkflowExpressionCatalog::nodeNames($definition['actions'], $definition));

        $explicit = array_key_exists('connections', $definition);
        $pending = $explicit ? array_values(WorkflowGraph::ordered($definition)) : $this->queue($definition['actions']);
        $active = $explicit ? WorkflowGraph::targets($definition['connections'], \App\Services\Workflows\WorkflowStartNodes::selected($definition, $input)) : [];
        if ($explicit) $pending = $this->skipInactive($pending, $active);
        return ['status' => $pending === [] ? 'completed' : 'ready', 'real' => $real, 'definition' => $definition,
            'graph_active' => $active,
            'context' => $context->toArray(), 'pending' => $pending,
            'results' => [], 'started_at' => now()->toIso8601String(), 'error' => null];
    }

    public function advance(array $session): array
    {
        if (($session['status'] ?? '') !== 'ready' || empty($session['pending'])) {
            return $session;
        }
        $context = WorkflowContext::fromArray($session['context']);
        // These flags belong to the session, never to the supplied trigger JSON.
        $context->setVariable('_dry_run', ! $session['real'])->setVariable('_test_mode', ! $session['real']);
        $this->sideEffectActions = $session['real'] ? [] : [
            'send_email', 'send_notification', 'create_record', 'update_records',
            'delete_record', 'assign_record', 'clone_record', 'http_request', 'run_workflow',
        ];
        $entry = array_shift($session['pending']);
        $step = $entry['step'];
        $isCondition = in_array($step['type'] ?? '', ['condition', 'control-condition'], true);
        $config = $step['config'] ?? [];
        $one = $step;
        unset($one['config']['true_actions'], $one['config']['false_actions']);
        $started = microtime(true);
        try {
            $result = $this->executeTestStep($one, $context, $entry['path']);
        } catch (Throwable $exception) {
            $result = ['id' => $step['id'], 'type' => $step['type'], 'path' => $entry['path'], 'status' => 'error', 'error' => $exception->getMessage(), 'input' => $one['config'], 'output' => []];
        }
        $result['name'] = $step['name'] ?? $this->getActionName($step['type']);
        $result['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
        $result['sequence'] = count($session['results']) + 1;
        $result['completed_at'] = now()->toIso8601String();
        $failed = in_array($result['status'], ['error', 'validation_error', 'failed'], true);
        if (array_key_exists('connections', $session['definition'])) {
            $port = $isCondition ? ((($step['disabled'] ?? false) || ($result['output']['passed'] ?? false)) ? 'yes' : 'no') : 'output';
            if ($isCondition && !$failed) {
                $result['condition_result'] = ($step['disabled'] ?? false) ? null : ($port === 'yes');
                $result['executed_branch'] = $port === 'yes' ? 'true' : 'false';
            }
            if (!$failed) $session['graph_active'] = array_values(array_unique(array_merge($session['graph_active'], WorkflowGraph::targets($session['definition']['connections'], 'action:'.$step['id'], $port))));
            $session['pending'] = $this->skipInactive($session['pending'], $session['graph_active']);
        } elseif ($isCondition && ! $failed) {
            $passed = ($step['disabled'] ?? false) || (bool) ($result['output']['passed'] ?? false);
            $branch = $passed ? 'true_actions' : 'false_actions';
            $enabled = $config[$passed ? 'has_true_branch' : 'has_false_branch'] ?? $passed;
            $result['condition_result'] = ($step['disabled'] ?? false) ? null : $passed;
            $result['executed_branch'] = $passed ? 'true' : 'false';
            if ($enabled) {
                $session['pending'] = array_merge($this->queue($config[$branch] ?? [], $entry['path'].'.config.'.$branch), $session['pending']);
            }
        }
        if (! $session['real'] && ($result['output']['dry_run'] ?? false)) {
            $result['status'] = 'simulated';
        }
        $session['results'][] = $result;
        $session['context'] = $context->toArray();
        $session['error'] = $result['error'] ?? null;
        $session['status'] = $failed ? 'failed' : ($session['pending'] === [] ? 'completed' : 'ready');

        return $session;
    }

    private function queue(array $steps, string $parent = ''): array
    {
        $queue = [];
        foreach (array_values($steps) as $index => $step) {
            $path = $parent === '' ? (string) $index : $parent.'.'.$index;
            $step['id'] ??= 'step_'.$path;
            $queue[] = ['step' => $step, 'path' => $path];
        }

        return $queue;
    }

    private function skipInactive(array $queue, array $active): array
    {
        while ($queue !== [] && !in_array('action:'.$queue[0]['step']['id'], $active, true)) array_shift($queue);
        return $queue;
    }
}
