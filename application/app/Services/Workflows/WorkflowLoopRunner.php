<?php

namespace App\Services\Workflows;

use App\Workflows\Context\WorkflowContext;
use InvalidArgumentException;

/** Structured iteration over an acyclic graph. No user-created back edges. */
final class WorkflowLoopRunner
{
    private array $nodes;
    private array $edges;
    private array $bodies = [];
    private int $executed = 0;

    public function __construct(private array $definition)
    {
        $this->nodes = WorkflowGraph::ordered($definition);
        $this->edges = WorkflowGraph::connections($definition);
        foreach ($this->nodes as $id => $node) {
            if (!WorkflowGraph::loop($node['step'])) continue;
            if (!array_key_exists('connections', $definition)) throw new InvalidArgumentException('Для цикла нужны связи «Каждый элемент» и «После цикла».');
            $each = WorkflowGraph::targets($this->edges, $id, 'each');
            $after = $this->reachable(WorkflowGraph::targets($this->edges, $id, 'done'));
            if (array_intersect($each, $after) !== []) throw new InvalidArgumentException('Ветка «Каждый элемент» не должна начинаться в ветке «После цикла».');
            $this->bodies[$id] = array_values(array_diff($this->reachable($each), $after));
        }
        foreach ($this->bodies as $id => $body) {
            foreach ($this->edges as $edge) {
                if (in_array($edge['targetId'], $body, true) && !in_array($edge['sourceId'], $body, true)
                    && !($edge['sourceId'] === $id && $edge['sourcePort'] === 'each')) {
                    throw new InvalidArgumentException('Внутрь цикла нельзя входить из другой ветки. Подключите её ко входу ноды «Цикл».');
                }
            }
            $depth = 1;
            foreach ($this->bodies as $outer => $outerBody) {
                if ($id === $outer) continue;
                if (in_array($id, $outerBody, true)) {
                    $depth++;
                    if (array_diff($body, $outerBody) !== []) throw new InvalidArgumentException('Вложенный цикл должен целиком находиться внутри внешнего.');
                } elseif (!in_array($outer, $body, true) && array_intersect($body, $outerBody) !== []) {
                    throw new InvalidArgumentException('У двух циклов не может быть общей повторяемой ветки.');
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
        $this->sequence(array_keys($this->nodes), WorkflowGraph::targets($this->edges, $start), $context, $execute, []);
    }

    /** Preview is deliberately bounded to one item and never runs the done branch. */
    public function runNode(string $id, WorkflowContext $context, callable $execute, bool $preview = false): void
    {
        $entry = $this->nodes[$id] ?? throw new InvalidArgumentException('Нода цикла не найдена.');
        if (!WorkflowGraph::loop($entry['step'])) throw new InvalidArgumentException('Выберите ноду цикла.');
        if ($preview) $entry['step']['config']['mode'] = 'once';
        $context->forgetStepOutputs(array_map(fn ($key) => substr($key, 7), $this->reachable([$id])));
        $result = $this->execute($entry, $context, $execute, []);
        if (!($entry['step']['disabled'] ?? false)) $this->iterate($id, $result['output'] ?? [], $context, $execute, [], $preview);
    }

    private function sequence(array $allowed, array $active, WorkflowContext $context, callable $execute, array $iteration): void
    {
        // A nested body is visited only by its owning loop, never by the outer sequence.
        $hidden = [];
        foreach ($this->bodies as $id => $body) if (in_array($id, $allowed, true)) $hidden = array_merge($hidden, $body);
        $visible = array_fill_keys(array_diff($allowed, $hidden), true);
        $active = array_fill_keys($active, true);
        foreach ($this->nodes as $id => $entry) {
            if (!isset($visible[$id], $active[$id])) continue;
            $result = $this->execute($entry, $context, $execute, $iteration);
            $step = $entry['step'];
            if (WorkflowGraph::loop($step)) {
                if (!($step['disabled'] ?? false)) $this->iterate($id, $result['output'] ?? [], $context, $execute, $iteration);
                $port = 'done';
            } else {
                $port = WorkflowGraph::condition($step) ? ((($step['disabled'] ?? false) || ($result['output']['passed'] ?? false)) ? 'yes' : 'no') : 'output';
            }
            foreach (WorkflowGraph::targets($this->edges, $id, $port) as $target) $active[$target] = true;
        }
    }

    private function execute(array $entry, WorkflowContext $context, callable $execute, array $iteration): array
    {
        if (++$this->executed > 5000) throw new InvalidArgumentException('Превышен лимит 5000 выполнений нод. Обработайте список частями.');
        $step = $entry['step'];
        unset($step['config']['true_actions'], $step['config']['false_actions']);
        $context->scopeToNode($this->definition, 'action:'.$step['id']);
        try { $result = $execute($step, $context, $entry['path'], $iteration); }
        finally { $context->clearNodeScope(); }
        if (!($result['success'] ?? false)) throw new \RuntimeException($result['error'] ?? 'Не удалось выполнить ноду.');
        $context->setStepOutput($step['id'], $result['output'] ?? []);
        return $result;
    }

    private function iterate(string $id, array $output, WorkflowContext $context, callable $execute, array $parent, bool $preview = false): void
    {
        $items = \App\Workflows\Actions\WorkflowLoopAction::items($output['items'] ?? null);
        $body = $this->bodies[$id];
        $context->forgetStepOutputs(array_map(fn ($key) => substr($key, 7), $body));
        $baseline = $context->toArray();
        foreach ($items as $index => $item) {
            $context->restoreLoopState($baseline);
            $context->setStepOutput(substr($id, 7), ['_workflow_loop' => true, 'item' => $item, 'index' => $index]);
            $iteration = [...$parent, ['node' => substr($id, 7), 'index' => $index]];
            $this->sequence($body, WorkflowGraph::targets($this->edges, $id, 'each'), $context, $execute, $iteration);
        }
        if (!$preview || $items === []) {
            $context->restoreLoopState($baseline);
            $context->setStepOutput(substr($id, 7), $output);
        }
    }

    public static function executionId(string $nodeId, array $iteration): string
    {
        return $iteration === [] ? $nodeId : 'loop_'.hash('sha256', json_encode([$nodeId, $iteration], JSON_THROW_ON_ERROR));
    }
}
