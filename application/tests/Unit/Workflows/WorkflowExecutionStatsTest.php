<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowExecutionStats;
use PHPUnit\Framework\TestCase;

class WorkflowExecutionStatsTest extends TestCase
{
    private function sampleResult(string $id, array $output = ['id' => 17], array $iteration = [], string $type = 'http_request', string $status = 'completed'): array
    {
        return compact('id', 'output', 'iteration', 'type', 'status') + ['sequence' => 99];
    }

    private function counts(array $results): array
    {
        return array_column(WorkflowExecutionStats::annotate($results), 'display_count', 'id');
    }

    public function test_counts_entities_not_sequence_including_projected_http_and_crm_outputs(): void
    {
        $this->assertSame(['one' => 1, 'batch' => 2, 'empty' => 0, 'condition' => 1, 'failed' => 0], $this->counts([
            $this->sampleResult('one'),
            $this->sampleResult('batch', ['items' => [['id' => 1], ['id' => 2]], 'has_more' => false]),
            $this->sampleResult('empty', ['status' => 200, 'success' => true, 'body' => []]),
            $this->sampleResult('condition', ['passed' => false, 'branch' => 'false', 'condition_results' => []], type: 'control-condition'),
            $this->sampleResult('failed', status: 'failed'),
        ]));
    }

    public function test_loop_counts_rounds_once_not_body_steps_or_input_size(): void
    {
        $results = [$this->sampleResult('loop', ['_workflow_loop' => true, 'items' => range(1, 100)], type: 'workflow_loop')];
        for ($i = 0; $i < 3; $i++) {
            $iteration = [['node' => 'loop', 'index' => $i]];
            $results[] = $this->sampleResult('body', iteration: $iteration);
            $results[] = $this->sampleResult('other', iteration: $iteration);
        }
        $this->assertSame(['loop' => 3, 'body' => 3, 'other' => 3], $this->counts($results));
        $this->assertSame('iterations', WorkflowExecutionStats::annotate($results)[0]['count_kind']);
    }

    public function test_open_empty_disabled_and_failed_loops_do_not_claim_the_full_input_count(): void
    {
        $this->assertSame(['open' => 1, 'empty' => 0, 'disabled' => 0, 'failed' => 0], $this->counts([
            $this->sampleResult('open', ['items' => [1, 2, 3]], type: 'workflow_loop'),
            $this->sampleResult('empty', ['items' => []], type: 'workflow_loop'),
            $this->sampleResult('disabled', ['items' => [1]], type: 'workflow_loop', status: 'skipped'),
            $this->sampleResult('failed', type: 'workflow_loop', status: 'failed'),
        ]));
    }

    public function test_nested_loops_and_repeated_invocations_keep_their_own_counts(): void
    {
        $results = [$this->sampleResult('outer', ['items' => [1, 2]], type: 'workflow_loop')];
        for ($i = 0; $i < 2; $i++) {
            $parent = [['node' => 'outer', 'index' => $i]];
            $results[] = $this->sampleResult('inner', ['items' => [1, 2]], $parent, 'workflow_loop');
            for ($j = 0; $j < 2; $j++) $results[] = $this->sampleResult('body', iteration: [...$parent, ['node' => 'inner', 'index' => $j]]);
        }
        $this->assertSame(['outer' => 2, 'inner' => 4, 'body' => 4], $this->counts($results));
        $this->assertSame(['outer' => 4, 'inner' => 8, 'body' => 8], $this->counts([...$results, ...$results]));
        // A new manual run gets new counts, not the previous run's total.
        $this->assertSame(['outer' => 2, 'inner' => 4, 'body' => 4], $this->counts($results));
    }

    public function test_feedback_and_a_mid_loop_error_preserve_observed_rounds_without_counting_feedback_as_an_item(): void
    {
        $results = [$this->sampleResult('loop', ['items' => [1, 2, 3]], type: 'workflow_loop')];
        $results[] = $this->sampleResult('body', iteration: [['node' => 'loop', 'index' => 0]]);
        $results[] = $this->sampleResult('body', iteration: [['node' => 'loop', 'index' => 0], ['node' => 'loop', 'index' => 99, 'kind' => 'feedback']]);
        $results[] = $this->sampleResult('body', iteration: [['node' => 'loop', 'index' => 1]], status: 'failed');
        $this->assertSame(['loop' => 2, 'body' => 2], $this->counts($results));
        $results[] = $this->sampleResult('body', type: 'workflow_loop', status: 'failed');
        $this->assertSame(['loop' => 2, 'body' => 2], $this->counts($results));
    }
}
