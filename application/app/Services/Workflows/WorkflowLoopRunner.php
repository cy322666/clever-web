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
    private int $executed = 0;

    public function __construct(private array $definition)
    {
        $this->nodes = WorkflowGraph::ordered($definition);
        $this->edges = WorkflowGraph::forwardConnections($definition);
        $this->returns = WorkflowGraph::loopReturns($definition);
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
        $context->forgetStepOutputs(array_map(fn ($key) => substr($key, 7), array_diff($this->reachable([$id]), $inputSources ?? [])));
        $result = $this->execute($entry, $context, $execute, [], $inputSources);
        return ($entry['step']['disabled'] ?? false) || $this->iterate($id, $result['output'] ?? [], $context, $execute, [], $preview);
    }

    private function sequence(array $allowed, array $active, WorkflowContext $context, callable $execute, array $iteration, string $source, ?string $returnTo = null): bool
    {
        // Connections activate nodes, not static membership in a loop body. The
        // same node may run once through an external branch and once per item.
        $remaining = array_intersect_key($this->nodes, array_fill_keys($allowed, true));
        $active = array_fill_keys($active, [$source]);
        $returned = false;
        while (($id = array_key_first(array_intersect_key($remaining, $active))) !== null) {
            $entry = $remaining[$id];
            unset($remaining[$id]);
            $result = $this->execute($entry, $context, $execute, $iteration, $active[$id]);
            $step = $entry['step'];
            if (WorkflowGraph::loop($step)) {
                if (!($step['disabled'] ?? false) && !$this->iterate($id, $result['output'] ?? [], $context, $execute, $iteration)) continue;
                $port = 'done';
            } else {
                $port = WorkflowGraph::condition($step) ? ((($step['disabled'] ?? false) || ($result['output']['passed'] ?? false)) ? 'yes' : 'no') : 'output';
            }
            foreach (WorkflowGraph::targets($this->edges, $id, $port) as $target) $active[$target][] = $id;
            foreach (WorkflowGraph::targets($this->returns, $id, $port) as $target) {
                if ($target === $returnTo) $returned = true;
                // Entering a body from outside can reach a not-yet-started loop.
                // It starts normally; a loop already visited in this scope is
                // never restarted by a second converging branch.
                else $active[$target][] = $id;
            }
        }
        return $returned;
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

    private function iterate(string $id, array $output, WorkflowContext $context, callable $execute, array $parent, bool $preview = false): bool
    {
        $items = \App\Workflows\Actions\WorkflowLoopAction::items($output['items'] ?? null);
        $body = $this->bodies[$id];
        $context->forgetStepOutputs(array_map(fn ($key) => substr($key, 7), $body));
        $baseline = $context->toArray();
        foreach ($items as $index => $item) {
            $context->restoreLoopState($baseline);
            $context->setStepOutput(substr($id, 7), ['_workflow_loop' => true, 'item' => $item, 'index' => $index]);
            $iteration = [...$parent, ['node' => substr($id, 7), 'index' => $index]];
            $returned = $this->sequence($body, WorkflowGraph::targets($this->edges, $id, 'each'), $context, $execute, $iteration, $id, $id);
            // Only a selected return path requests the next item, including old
            // definitions that predate explicit return connections.
            if (!$returned) return false;
        }
        if (!$preview || $items === []) {
            $context->restoreLoopState($baseline);
            $context->setStepOutput(substr($id, 7), $output);
        }
        return true;
    }

    public static function executionId(string $nodeId, array $iteration): string
    {
        return $iteration === [] ? $nodeId : 'loop_'.hash('sha256', json_encode([$nodeId, $iteration], JSON_THROW_ON_ERROR));
    }
}
