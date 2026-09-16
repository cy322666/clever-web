<?php

namespace App\Services\Workflows;

/** Projects the existing execution tree onto the canvas without changing its definition. */
final class WorkflowCanvasGraph
{
    public static function edges(array $actions, ?array $connections = null, bool $execution = false, array $startIds = ['trigger']): array
    {
        if ($connections !== null) {
            $nodes = WorkflowGraph::nodes($actions);
            $edges = array_map(fn ($edge) => $edge + ['path' => '', 'index' => 0], $connections);
            foreach (array_fill_keys($startIds, ['step' => ['type' => 'trigger']]) + $nodes as $id => $node) {
                foreach (WorkflowGraph::condition($node['step']) ? ['yes', 'no'] : ['output'] as $port) {
                    if (WorkflowGraph::targets($connections, $id, $port) === []) {
                        $edges[] = ['sourceId' => $id, 'sourcePort' => $port, 'targetId' => null, 'path' => '', 'index' => 0];
                    }
                }
            }
            return $edges;
        }
        $edges = [];
        $walk = function (array $steps, string $path, array $incoming) use (&$walk, &$edges, $execution): array {
            foreach (array_values($steps) as $index => $step) {
                $stepPath = $path === '' ? (string) $index : $path . '.' . $index;
                $nodeId = 'action:' . (string) ($step['id'] ?? $stepPath);

                foreach ($incoming as $endpoint) {
                    $edges[] = $endpoint + ['targetId' => $nodeId];
                }

                if (in_array($step['type'] ?? '', ['control-condition', 'condition'], true)) {
                    $incoming = [];

                    foreach (['true_actions' => 'yes', 'false_actions' => 'no'] as $branch => $port) {
                        $branchPath = $stepPath . '.config.' . $branch;
                        $enabled = !$execution || ($step['config'][$port === 'yes' ? 'has_true_branch' : 'has_false_branch'] ?? ($port === 'yes'));
                        $incoming = array_merge($incoming, $walk($enabled ? ($step['config'][$branch] ?? []) : [], $branchPath, [[
                            'sourceId' => $nodeId,
                            'sourcePort' => $port,
                            'path' => $branchPath,
                            'index' => 0,
                        ]]));
                    }
                } else {
                    $incoming = [[
                        'sourceId' => $nodeId,
                        'sourcePort' => 'output',
                        'path' => $path,
                        'index' => $index + 1,
                    ]];
                }
            }

            return $incoming;
        };

        $exits = $walk($actions, '', [[
            'sourceId' => 'trigger',
            'sourcePort' => 'output',
            'path' => '',
            'index' => 0,
        ]]);

        foreach ($exits as $endpoint) {
            $edges[] = $endpoint + ['targetId' => null];
        }

        return $edges;
    }
}
