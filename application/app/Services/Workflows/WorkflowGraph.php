<?php

namespace App\Services\Workflows;

use InvalidArgumentException;

/** Optional explicit connections; definitions without them retain their original tree semantics. */
final class WorkflowGraph
{
    public static function nodes(array $actions, string $parent = '', int $depth = 0): array
    {
        if ($depth > 32) throw new InvalidArgumentException('Слишком глубокая вложенность нод.');
        $nodes = [];
        foreach (array_values($actions) as $index => $action) {
            if (!is_array($action) || !is_array($action['config'] ?? [])) throw new InvalidArgumentException('Некорректная конфигурация ноды.');
            $path = $parent === '' ? (string) $index : $parent.'.'.$index;
            $id = (string) ($action['id'] ?? '');
            if ($id === '' || isset($nodes['action:'.$id])) {
                throw new InvalidArgumentException('У каждой ноды должен быть уникальный ID.');
            }
            $nodes['action:'.$id] = ['step' => $action, 'path' => $path];
            foreach (['true_actions', 'false_actions'] as $branch) {
                if (!is_array($action['config'][$branch] ?? [])) throw new InvalidArgumentException('Некорректный список нод ветки.');
                foreach (self::nodes($action['config'][$branch] ?? [], $path.'.config.'.$branch, $depth + 1) as $key => $node) {
                    if (isset($nodes[$key])) throw new InvalidArgumentException('Повторяющийся ID ноды.');
                    $nodes[$key] = $node;
                }
            }
            if (count($nodes) > 500) throw new InvalidArgumentException('В одном сценарии доступно до 500 нод.');
        }
        return $nodes;
    }

    public static function condition(array $step): bool
    {
        return in_array($step['type'] ?? '', ['condition', 'control-condition'], true);
    }

    public static function loop(array $step): bool { return ($step['type'] ?? '') === 'workflow_loop'; }

    public static function ports(array $step): array
    {
        return self::loop($step) ? ['each', 'done'] : (self::condition($step) ? ['yes', 'no'] : ['output']);
    }

    public static function connections(array $definition): array
    {
        if (array_key_exists('connections', $definition) && !is_array($definition['connections'])) throw new InvalidArgumentException('Некорректный список связей.');
        return array_values($definition['connections'] ?? array_map(
            fn (array $edge) => array_intersect_key($edge, array_flip(['sourceId', 'sourcePort', 'targetId'])),
            array_filter(WorkflowCanvasGraph::edges($definition['actions'] ?? [], null, true), fn (array $edge) => $edge['targetId'] !== null),
        ));
    }

    /** Return edges close a loop's own iteration, not an arbitrary graph cycle. */
    public static function loopReturns(array $definition): array
    {
        $nodes = self::nodes($definition['actions'] ?? []);
        $edges = self::connections($definition);
        $returns = [];
        foreach ($nodes as $id => $node) {
            if (!self::loop($node['step'])) continue;
            $pending = self::targets($edges, $id, 'each');
            $seen = [];
            while ($pending !== []) {
                $next = array_pop($pending);
                if ($next === $id || isset($seen[$next]) || !isset($nodes[$next])) continue;
                $seen[$next] = true;
                // A nested loop is opaque: only its done branch belongs to this iteration.
                foreach ($edges as $edge) {
                    if ($edge['sourceId'] === $next && (!self::loop($nodes[$next]['step']) || $edge['sourcePort'] === 'done')) {
                        $pending[] = $edge['targetId'];
                    }
                }
            }
            foreach ($edges as $index => $edge) {
                if ($edge['targetId'] === $id && isset($seen[$edge['sourceId']])
                    && (!self::loop($nodes[$edge['sourceId']]['step']) || $edge['sourcePort'] === 'done')) {
                    $returns[$index] = $edge;
                }
            }
        }
        return $returns;
    }

    public static function forwardConnections(array $definition): array
    {
        return array_values(array_diff_key(self::connections($definition), self::loopReturns($definition), self::loopFeedback($definition)));
    }

    /** Done is a normal continuation, including explicit jumps to earlier nodes. */
    public static function loopFeedback(array $definition): array
    {
        $nodes = self::nodes($definition['actions'] ?? []);
        $returns = self::loopReturns($definition);
        $edges = array_diff_key(self::connections($definition), $returns);
        $reachable = static function (array $pending, ?int $skip = null) use ($edges): array {
            $seen = [];
            while ($pending !== []) {
                $id = array_pop($pending);
                if (isset($seen[$id])) continue;
                $seen[$id] = true;
                foreach ($edges as $index => $edge) if ($index !== $skip && $edge['sourceId'] === $id) $pending[] = $edge['targetId'];
            }
            return $seen;
        };
        $feedback = [];
        foreach ($edges as $index => $edge) {
            if ($edge['sourcePort'] !== 'done' || !isset($nodes[$edge['sourceId']]) || !self::loop($nodes[$edge['sourceId']]['step'])) continue;
            $next = $reachable([$edge['targetId']], $index);
            $ownReturns = array_column(array_filter($returns, fn ($return) => $return['targetId'] === $edge['sourceId']), 'sourceId');
            if (isset($next[$edge['sourceId']]) || array_intersect(array_keys($next), $ownReturns) !== []
                || in_array($edge['targetId'], self::targets($edges, $edge['sourceId'], 'each'), true)) $feedback[$index] = $edge;
        }
        return $feedback;
    }

    /** Validate the acyclic execution order apart from explicit loop continuations. */
    public static function ordered(array $definition): array
    {
        $nodes = self::nodes($definition['actions'] ?? []);
        $starts = WorkflowStartNodes::all($definition);
        // Legacy graph-only helpers omit the trigger declaration.
        $starts['trigger'] ??= ['type' => 'manual'];
        if (count($nodes) > 500) throw new InvalidArgumentException('В одном сценарии доступно до 500 нод.');
        $edges = self::connections($definition);
        if (count($edges) > 2000) throw new InvalidArgumentException('Слишком много связей.');
        $incoming = array_fill_keys(array_keys($nodes), 0);
        $outgoing = [];
        $seen = [];
        foreach ($edges as $edge) {
            if (!is_array($edge) || !is_string($edge['sourceId'] ?? null) || !is_string($edge['targetId'] ?? null) || !is_string($edge['sourcePort'] ?? null)) throw new InvalidArgumentException('Некорректная связь нод.');
            $source = $edge['sourceId'] ?? '';
            $target = $edge['targetId'] ?? '';
            $port = $edge['sourcePort'] ?? '';
            $ports = isset($nodes[$source]) ? self::ports($nodes[$source]['step']) : ['output'];
            if ((!isset($starts[$source]) && !isset($nodes[$source])) || !isset($nodes[$target]) || !in_array($port, $ports, true)
                || ($source === $target && $port !== 'done')) {
                throw new InvalidArgumentException('Связь содержит недоступную ноду или выход.');
            }
            $key = $source.'|'.$port.'|'.$target;
            if (isset($seen[$key])) throw new InvalidArgumentException('Эта связь уже существует.');
            if (isset($nodes[$source]) && self::condition($nodes[$source]['step']) && !self::loop($nodes[$target]['step'])) {
                $other = $source.'|'.($port === 'yes' ? 'no' : 'yes').'|'.$target;
                if (isset($seen[$other])) throw new InvalidArgumentException('Ветки «Да» и «Нет» одного условия нельзя подключить к одной ноде.');
            }
            $seen[$key] = true;
        }
        foreach (self::forwardConnections($definition) as $edge) {
            if (!isset($starts[$edge['sourceId']])) $incoming[$edge['targetId']]++;
            $outgoing[$edge['sourceId']][] = $edge['targetId'];
        }
        $ready = array_keys(array_filter($incoming, fn ($count) => $count === 0));
        $ordered = [];
        while ($ready !== []) {
            $id = array_shift($ready);
            $ordered[$id] = $nodes[$id];
            foreach ($outgoing[$id] ?? [] as $target) {
                if (--$incoming[$target] === 0) $ready[] = $target;
            }
        }
        if (count($ordered) !== count($nodes)) throw new InvalidArgumentException('Связь создаёт неуправляемый цикл. Используйте ноду «Цикл» или её выход «Готово».');
        return $ordered;
    }

    public static function targets(array $connections, string $source, string $port = 'output'): array
    {
        return array_values(array_unique(array_column(array_filter($connections,
            fn ($edge) => $edge['sourceId'] === $source && $edge['sourcePort'] === $port), 'targetId')));
    }

    public static function ancestors(array $definition, string $target): array
    {
        $edges = self::forwardConnections($definition);
        $seen = [];
        $visit = function (string $id) use (&$visit, &$seen, $edges): void {
            foreach ($edges as $edge) {
                $source = $edge['sourceId'];
                if ($edge['targetId'] === $id && str_starts_with($source, 'action:') && !isset($seen[$source])) {
                    $seen[$source] = true;
                    $visit($source);
                }
            }
        };
        $visit($target);
        unset($seen[$target]);
        return array_keys(array_intersect_key(self::nodes($definition['actions'] ?? []), $seen));
    }
}
