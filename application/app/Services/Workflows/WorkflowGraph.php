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

    public static function connections(array $definition): array
    {
        if (array_key_exists('connections', $definition) && !is_array($definition['connections'])) throw new InvalidArgumentException('Некорректный список связей.');
        return array_values($definition['connections'] ?? array_map(
            fn (array $edge) => array_intersect_key($edge, array_flip(['sourceId', 'sourcePort', 'targetId'])),
            array_filter(WorkflowCanvasGraph::edges($definition['actions'] ?? [], null, true), fn (array $edge) => $edge['targetId'] !== null),
        ));
    }

    /** Validates endpoints and rejects cycles, including cycles among disconnected nodes. */
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
            $ports = isset($nodes[$source]) && self::condition($nodes[$source]['step']) ? ['yes', 'no'] : ['output'];
            if ((!isset($starts[$source]) && !isset($nodes[$source])) || !isset($nodes[$target]) || !in_array($port, $ports, true) || $source === $target) {
                throw new InvalidArgumentException('Связь содержит недоступную ноду или выход.');
            }
            $key = $source.'|'.$port.'|'.$target;
            if (isset($seen[$key])) throw new InvalidArgumentException('Эта связь уже существует.');
            if (isset($nodes[$source]) && self::condition($nodes[$source]['step'])) {
                $other = $source.'|'.($port === 'yes' ? 'no' : 'yes').'|'.$target;
                if (isset($seen[$other])) throw new InvalidArgumentException('Ветки «Да» и «Нет» одного условия нельзя подключить к одной ноде.');
            }
            $seen[$key] = true;
            if (!isset($starts[$source])) $incoming[$target]++;
            $outgoing[$source][] = $target;
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
        if (count($ordered) !== count($nodes)) throw new InvalidArgumentException('Связь создаёт цикл. Уберите обратную связь.');
        return $ordered;
    }

    public static function targets(array $connections, string $source, string $port = 'output'): array
    {
        return array_values(array_unique(array_column(array_filter($connections,
            fn ($edge) => $edge['sourceId'] === $source && $edge['sourcePort'] === $port), 'targetId')));
    }

    public static function ancestors(array $definition, string $target): array
    {
        $edges = self::connections($definition);
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
