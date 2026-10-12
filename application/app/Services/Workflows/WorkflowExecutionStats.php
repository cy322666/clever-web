<?php

namespace App\Services\Workflows;

/** Canvas counts are measured from recorded results, never from step sequence numbers. */
final class WorkflowExecutionStats
{
    public static function annotate(array $results): array
    {
        $counts = $loops = $visits = $iterations = $types = [];
        foreach ($results as $result) {
            $id = $result['id'];
            // Older preview guards labelled a body's terminal error as a loop.
            // The first recorded execution identifies that node's actual type.
            $types[$id] ??= $result['type'] ?? '';
            // Each prefix identifies one actual round of its owning loop. Include
            // parent/feedback context, and keep repeated invocations distinct.
            $prefix = [];
            foreach ($result['iteration'] ?? [] as $round) {
                $prefix[] = $round;
                if (($round['kind'] ?? '') === 'feedback') continue;
                $owner = $round['node'];
                $visit = $visits[$owner] ?? 0;
                $iterations[$owner][$visit][json_encode($prefix)] = true;
            }
            if ($types[$id] === 'workflow_loop') {
                $loops[$id] = true;
                if (!in_array($result['status'] ?? '', ['completed', 'success'], true)) continue;
                $visit = $visits[$id] = ($visits[$id] ?? 0) + 1;
                // An open loop with no body still emits its first item, not the
                // whole input list. Empty/disabled loops have no rounds.
                $output = $result['output'] ?? [];
                $counts[$id][$visit] = array_key_exists('item', $output) || !empty($output['items']) ? 1 : 0;
            } else {
                $counts[$id] = ($counts[$id] ?? 0) + self::entities($result);
            }
        }
        foreach ($loops as $id => $_) {
            $visitsCounts = $counts[$id] ?? [];
            foreach ($iterations[$id] ?? [] as $visit => $rounds) $visitsCounts[$visit] = max($visitsCounts[$visit] ?? 0, count($rounds));
            $counts[$id] = array_sum($visitsCounts);
        }
        foreach ($results as &$result) {
            $result['display_count'] = $counts[$result['id']] ?? 0;
            $result['count_kind'] = isset($loops[$result['id']]) ? 'iterations' : 'entities';
        }
        unset($result);
        return $results;
    }

    public static function entities(array $result): int
    {
        if (!in_array($result['status'] ?? '', ['completed', 'success'], true)) return 0;
        $output = WorkflowOutputView::value($result['output'] ?? null);
        if (is_array($output['items'] ?? null) && array_is_list($output['items'])) return count($output['items']);
        return is_array($output) && array_is_list($output) ? count($output) : 1;
    }
}
