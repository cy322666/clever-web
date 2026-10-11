<?php

namespace App\Services\Workflows;

use App\Workflows\Context\WorkflowContext;
use InvalidArgumentException;

/** Structured iteration; explicit return edges advance the owning loop, never restart it. */
final class WorkflowLoopRunner
{
    private array $nodes;
    private array $edges;
    private array $bodies = [];
    private array $returns;
    private array $feedback;
    private int $executed = 0;

    public function __construct(private array $definition)
    {
        $this->nodes = WorkflowGraph::ordered($definition);
        $this->edges = WorkflowGraph::forwardConnections($definition);
        $this->returns = WorkflowGraph::loopReturns($definition);
        $this->feedback = WorkflowGraph::loopFeedback($definition);
        foreach ($this->nodes as $id => $node) {
            if (!WorkflowGraph::loop($node['step'])) continue;
            if (!array_key_exists('connections', $definition)) throw new InvalidArgumentException('Для цикла нужны связи «Каждый элемент» и «После цикла».');
            $each = WorkflowGraph::targets($this->edges, $id, 'each');
            $after = $this->reachable(WorkflowGraph::targets($this->edges, $id, 'done'));
            if (array_intersect($each, $after) !== []) throw new InvalidArgumentException('Ветка «Каждый элемент» не должна начинаться в ветке «После цикла».');
            $this->bodies[$id] = array_values(array_diff($this->reachable($each), $after));
        }
        foreach ($this->bodies as $id => $body) {
            foreach ($this->returns as $edge) {
                if ($edge['targetId'] === $id && !in_array($edge['sourceId'], $body, true)) {
                    throw new InvalidArgumentException('Возврат в цикл должен идти из его ветки «Каждый элемент», не из «Готово».');
                }
            }
            $depth = 1;
            foreach ($this->bodies as $outer => $outerBody) {
                if ($id === $outer) continue;
                if (in_array($id, $outerBody, true)) {
                    $depth++;
                    if (array_diff($body, $outerBody) !== []) throw new InvalidArgumentException('Вложенный цикл должен целиком находиться внутри внешнего.');
                }
            }
            if ($depth > 8) throw new InvalidArgumentException('Допустимо до 8 вложенных циклов.');
        }
    }

    public static function present(array $definition): bool
    {
        foreach (WorkflowGraph::nodes($definition['actions'] ?? []) as $node) if (WorkflowGraph::loop($node['step'])) return true;
        return false;
    }

    public function requiresGraphScheduler(): bool
    {
        if ($this->feedback !== []) return true;
        foreach ($this->bodies as $owner => $body) {
            foreach ($this->edges as $edge) {
                if ($edge['sourcePort'] === 'done' && in_array($edge['sourceId'], $body, true)
                    && $edge['targetId'] !== $owner && !in_array($edge['targetId'], $body, true)) return true;
            }
        }
        return false;
    }

    private function reachable(array $roots): array
    {
        $seen = [];
        while ($roots !== []) {
            $id = array_pop($roots);
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            foreach ($this->edges as $edge) if ($edge['sourceId'] === $id) $roots[] = $edge['targetId'];
        }
        return array_keys($seen);
    }

    /** A disabled loop bypasses its body and continues through done. */
    public function run(WorkflowContext $context, callable $execute): void
    {
        $context->forgetVariable('_workflow_values');
        // A resumed run is rebuilt from its completed logs, not from stale iteration outputs.
        $context->forgetStepOutputs(array_map(fn ($id) => substr($id, 7), array_keys($this->nodes)));
        $start = WorkflowStartNodes::selected($this->definition, $context->getTriggerData());
        $this->sequence(array_keys($this->nodes), WorkflowGraph::targets($this->edges, $start), $context, $execute, [], $start);
    }

    /** Preview is deliberately bounded to one item and never runs the done branch. */
    public function runNode(string $id, WorkflowContext $context, callable $execute, bool $preview = false, ?array $inputSources = null): bool
    {
        $entry = $this->nodes[$id] ?? throw new InvalidArgumentException('Нода цикла не найдена.');
        if (!WorkflowGraph::loop($entry['step'])) throw new InvalidArgumentException('Выберите ноду цикла.');
        if ($preview) $entry['step']['config']['mode'] = 'once';
        if ($preview) {
            foreach ($this->bodies[$id] as $bodyId) {
                $step = $this->nodes[$bodyId]['step'];
                if (($step['type'] ?? '') !== 'workflow_set_variable') continue;
                foreach ($step['config']['assignments'] ?? [] as $row) {
                    if (is_string($row['name'] ?? null) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $row['name'])) $context->forgetVariable('_workflow_values.'.$row['name']);
                }
            }
        }
        $context->forgetStepOutputs(array_map(fn ($key) => substr($key, 7), array_diff($this->reachable([$id]), $inputSources ?? [])));
        $result = $this->execute($entry, $context, $execute, [], $inputSources);
        return ($entry['step']['disabled'] ?? false) || $this->iterate($id, $result['output'] ?? [], $context, $execute, [], $preview)['completed'];
    }

    private function sequence(array $allowed, array $active, WorkflowContext $context, callable $execute, array $iteration, string $source, ?string $returnTo = null): array
    {
        // Connections activate nodes, not static membership in a loop body. The
        // same node may run once through an external branch and once per item.
        $scope = array_intersect_key($this->nodes, array_fill_keys($allowed, true));
        $remaining = $scope;
        $active = array_fill_keys($active, [$source]);
        $returned = false;
        $transfers = [];
        $round = 0;
        $pass = $iteration;
        while (($id = array_key_first(array_intersect_key($remaining, $active))) !== null) {
            $entry = $remaining[$id];
            unset($remaining[$id]);
            $inputs = $active[$id];
            unset($active[$id]);
            $result = $this->execute($entry, $context, $execute, $pass, $inputs);
            $step = $entry['step'];
            $jumps = [];
            $completed = true;
            if (WorkflowGraph::loop($step)) {
                if (!($step['disabled'] ?? false)) {
                    $outcome = $this->iterate($id, $result['output'] ?? [], $context, $execute, $pass);
                    $completed = $outcome['completed'];
                    $jumps = $outcome['transfers'];
                }
                $port = 'done';
            } else {
                $port = WorkflowGraph::condition($step) ? ((($step['disabled'] ?? false) || ($result['output']['passed'] ?? false)) ? 'yes' : 'no') : 'output';
            }
            foreach ($completed ? WorkflowGraph::targets($this->edges, $id, $port) : [] as $target) {
                if ($port === 'done' && !isset($remaining[$target])) $jumps[] = ['source' => $id, 'target' => $target];
                else $active[$target][] = $id;
            }
            foreach ($completed ? WorkflowGraph::targets($this->returns, $id, $port) : [] as $target) {
                if ($target === $returnTo) $returned = true;
                // Entering a body from outside can reach a not-yet-started loop.
                // It starts normally; a loop already visited in this scope is
                // never restarted by a second converging branch.
                elseif ($port === 'done' && !isset($remaining[$target])) $jumps[] = ['source' => $id, 'target' => $target];
                else $active[$target][] = $id;
            }
            foreach ($completed ? WorkflowGraph::targets($this->feedback, $id, $port) : [] as $target) $jumps[] = ['source' => $id, 'target' => $target];
            $local = [];
            foreach ($jumps as $jump) {
                if ($jump['target'] === $returnTo) $returned = true;
                elseif (isset($scope[$jump['target']])) $local[$jump['target']][] = $jump['source'];
                else $transfers[] = $jump; // Unwind only as far as the destination's enclosing scope.
            }
            if ($local !== []) {
                // Explicit done feedback begins a new pass. Old incoming edges
                // are not input for this pass; only still-pending branches remain.
                $active = array_intersect_key($active, $remaining);
                foreach ($local as $target => $sources) $active[$target] = $sources;
                $remaining = $scope;
                $returned = false;
                $pass = [...$iteration, ['node' => substr($id, 7), 'index' => ++$round, 'kind' => 'feedback']];
            }
        }
        return compact('returned', 'transfers');
    }

    private function execute(array $entry, WorkflowContext $context, callable $execute, array $iteration, ?array $inputSources = null): array
    {
        if (++$this->executed > 5000) throw new InvalidArgumentException('Превышен лимит 5000 выполнений нод. Обработайте список частями.');
        $step = $entry['step'];
        unset($step['config']['true_actions'], $step['config']['false_actions']);
        $context->scopeToNode($this->definition, 'action:'.$step['id'], $inputSources);
        try { $result = $execute($step, $context, $entry['path'], $iteration); }
        finally { $context->clearNodeScope(); }
        if (!($result['success'] ?? false)) throw new \RuntimeException($result['error'] ?? 'Не удалось выполнить ноду.');
        $context->setStepOutput($step['id'], $result['output'] ?? []);
        return $result;
    }

    private function iterate(string $id, array $output, WorkflowContext $context, callable $execute, array $parent, bool $preview = false): array
    {
        $items = \App\Workflows\Actions\WorkflowLoopAction::items($output['items'] ?? null);
        $body = $this->bodies[$id];
        $context->forgetStepOutputs(array_map(fn ($key) => substr($key, 7), $body));
        $baseline = $context->toArray();
        foreach ($items as $index => $item) {
            $context->restoreLoopState($baseline);
            $context->setStepOutput(substr($id, 7), ['_workflow_loop' => true, 'item' => $item, 'index' => $index]);
            $iteration = [...$parent, ['node' => substr($id, 7), 'index' => $index]];
            $outcome = $this->sequence($body, WorkflowGraph::targets($this->edges, $id, 'each'), $context, $execute, $iteration, $id, $id);
            // Only a selected return path requests the next item, including old
            // definitions that predate explicit return connections.
            if (!$outcome['returned'] || $outcome['transfers'] !== []) return ['completed' => false, 'transfers' => $outcome['transfers']];
        }
        if (!$preview || $items === []) {
            $context->restoreLoopState($baseline);
            $context->setStepOutput(substr($id, 7), $output);
        }
        return ['completed' => true, 'transfers' => []];
    }

    public static function executionId(string $nodeId, array $iteration): string
    {
        return $iteration === [] ? $nodeId : 'loop_'.hash('sha256', json_encode([$nodeId, $iteration], JSON_THROW_ON_ERROR));
    }
}
