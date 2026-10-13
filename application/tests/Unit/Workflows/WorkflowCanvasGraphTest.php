<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowCanvasGraph;
use PHPUnit\Framework\TestCase;

class WorkflowCanvasGraphTest extends TestCase
{
    public function test_common_continuation_joins_branch_ends_not_a_third_condition_output(): void
    {
        $actions = [$this->condition(), ['id' => 'after', 'type' => 'test']];
        $edges = WorkflowCanvasGraph::edges($actions);
        $conditionEdges = array_values(array_filter($edges, fn ($e) => $e['sourceId'] === 'action:if' && ! ($e['branch'] ?? false)));
        $this->assertSame(['yes', 'no'], array_column($conditionEdges, 'sourcePort'));
        $branchControls = array_values(array_filter($edges, fn ($e) => $e['branch'] ?? false));
        $this->assertSame(['yes', 'no'], array_column($branchControls, 'sourcePort'));
        $joins = array_values(array_filter($edges, fn ($e) => $e['targetId'] === 'action:after'));
        $this->assertSame(['action:yes-step', 'action:no-step'], array_column($joins, 'sourceId'));
        $this->assertSame(['0.config.true_actions', '0.config.false_actions'], array_column($joins, 'path'));
        $this->assertSame([1, 1], array_column($joins, 'index'));
        $this->assertSame('action:after', $edges[array_key_last($edges)]['sourceId']);
    }

    public function test_empty_branches_join_the_following_node_using_only_yes_and_no(): void
    {
        $edges = WorkflowCanvasGraph::edges([
            ['id' => 'if', 'type' => 'control-condition', 'config' => []],
            ['id' => 'after', 'type' => 'test'],
        ]);
        $joins = array_values(array_filter($edges, fn ($e) => $e['targetId'] === 'action:after'));
        $this->assertSame(['yes', 'no'], array_column($joins, 'sourcePort'));
        $this->assertSame([0, 0], array_column($joins, 'index'));
        $this->assertCount(4, $edges);
    }

    public function test_condition_at_the_end_exposes_only_two_branch_tails(): void
    {
        $edges = WorkflowCanvasGraph::edges([$this->condition()]);
        $tails = array_values(array_filter($edges, fn ($e) => $e['targetId'] === null && ! ($e['branch'] ?? false)));
        $this->assertSame(['action:yes-step', 'action:no-step'], array_column($tails, 'sourceId'));
    }

    public function test_only_actual_loop_returns_are_angular_even_when_done_shares_the_body(): void
    {
        $actions = [
            ['id' => 'outer', 'type' => 'workflow_loop'],
            ['id' => 'inner', 'type' => 'workflow_loop'],
            ['id' => 'body', 'type' => 'workflow_delay'],
            ['id' => 'telegram', 'type' => 'telegram_send_message'],
        ];
        $edge = fn ($source, $port, $target) => ['sourceId' => 'action:'.$source, 'sourcePort' => $port, 'targetId' => 'action:'.$target];
        $connections = [
            $edge('outer', 'each', 'inner'), $edge('inner', 'each', 'body'),
            $edge('body', 'output', 'inner'), $edge('inner', 'done', 'outer'),
            $edge('outer', 'done', 'telegram'), $edge('telegram', 'output', 'inner'),
        ];
        foreach ([false, true] as $execution) {
            $edges = array_slice(WorkflowCanvasGraph::edges($actions, $connections, $execution), 0, count($connections));
            $this->assertSame([false, false, true, true, false, false], array_column($edges, 'loopReturn'));
            $this->assertSame([false, false, false, false, true, false], array_column($edges, 'loopFeedback'));
        }
    }

    private function condition(): array
    {
        return ['id' => 'if', 'type' => 'control-condition', 'config' => [
            'true_actions' => [['id' => 'yes-step', 'type' => 'test']],
            'false_actions' => [['id' => 'no-step', 'type' => 'test']],
        ]];
    }
}
