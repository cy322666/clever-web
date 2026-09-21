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
                $condition = WorkflowGraph::condition($node['step']);

                foreach ($condition ? ['yes', 'no'] : ['output'] as $port) {
                    $targets = WorkflowGraph::targets($connections, $id, $port);

                    if ($targets === []) {
                        $edges[] = ['sourceId' => $id, 'sourcePort' => $port, 'targetId' => null, 'path' => '', 'index' => 0];
                    } elseif ($condition && ! $execution) {
                        $edges[] = [
                            'sourceId' => $id,
                            'sourcePort' => $port,
                            'targetId' => null,
                            'branch' => true,
                            'path' => '',
                            'index' => count($targets),
                        ];
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
                        $branchSteps = $enabled ? ($step['config'][$branch] ?? []) : [];
                        $incoming = array_merge($incoming, $walk($branchSteps, $branchPath, [[
                            'sourceId' => $nodeId,
                            'sourcePort' => $port,
                            'path' => $branchPath,
                            'index' => 0,
                        ]]));

                        if (! $execution && $branchSteps !== []) {
                            $edges[] = [
                                'sourceId' => $nodeId,
                                'sourcePort' => $port,
                                'targetId' => null,
                                'branch' => true,
                                'path' => $branchPath,
                                'index' => count($branchSteps),
                            ];
                        }
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
