<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\{WorkflowCanvasGraph, WorkflowDefinitionValidator, WorkflowGraph, WorkflowLoopRunner};
use App\Workflows\Actions\WorkflowLoopAction;
use App\Workflows\Context\WorkflowContext;
use App\Workflows\Engine\WorkflowDebugger;
use Illuminate\Support\Facades\Http;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Livewire\Livewire;
use Tests\Support\{WorkflowCanvasDatabase, WorkflowCanvasFixture};
use Tests\TestCase;

class WorkflowLoopFeedbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        Http::preventStrayRequests();
    }

    private function edge(string $from, string $to, string $port = 'output'): array
    {
        return ['sourceId' => $from === 'trigger' ? $from : 'action:'.$from, 'sourcePort' => $port, 'targetId' => 'action:'.$to];
    }

    private function definition(): array
    {
        $step = fn ($id, $type, $config) => compact('id', 'type', 'config') + ['name' => $id];
        return ['trigger' => ['type' => 'manual'], 'actions' => [
            $step('outer', 'workflow_loop', ['items' => [['id' => 1], ['id' => 2]]]),
            $step('check', 'control-condition', ['conditions' => [['left' => '{{ $json.first().id }}', 'operator' => 'equals', 'right' => 99]]]),
            $step('inner', 'workflow_loop', ['items' => [['id' => 99], ['id' => 99]]]),
            $step('body', 'workflow_set_variable', ['assignments' => [['name' => 'id', 'value' => '{{ $json.id }}']]]),
            $step('after', 'workflow_set_variable', ['assignments' => [['name' => 'complete', 'value' => true]]]),
        ], 'connections' => [
            $this->edge('trigger', 'outer'), $this->edge('outer', 'check', 'each'), $this->edge('outer', 'after', 'done'),
            $this->edge('check', 'inner', 'no'), $this->edge('check', 'outer', 'yes'),
            $this->edge('inner', 'body', 'each'), $this->edge('body', 'inner'), $this->edge('inner', 'check', 'done'),
        ]];
    }

    public function test_done_rechecks_previous_node_and_advances_outer_loop_with_fresh_input(): void
    {
        $definition = $this->definition();
        $this->assertSame([], WorkflowDefinitionValidator::issues($definition));
        $this->assertCount(1, WorkflowGraph::loopFeedback($definition));
        $debugger = app(WorkflowDebugger::class);
        $session = $debugger->advance($debugger->start($definition, [], null, null));
        $this->assertSame('completed', $session['status'], $session['error'] ?? '');
        $this->assertSame(['outer', 'check', 'inner', 'body', 'body', 'check', 'check', 'inner', 'body', 'body', 'check', 'after'], array_column($session['results'], 'id'));
        $checks = array_values(array_filter($session['results'], fn ($r) => $r['id'] === 'check'));
        $this->assertSame([false, true, false, true], array_column(array_column($checks, 'output'), 'passed'));
        $this->assertCount(count($session['results']), array_unique(array_map(fn ($r) => WorkflowLoopRunner::executionId($r['id'], $r['iteration']), $session['results'])));
        Http::assertNothingSent();
    }

    public function test_feedback_can_repeat_inner_loop_and_never_reuses_a_previous_pass_identity(): void
    {
        $calls = [];
        (new WorkflowLoopRunner($this->definition()))->run(new WorkflowContext, function ($step, $context, $path, $iteration) use (&$calls) {
            $calls[] = ['id' => $step['id'], 'iteration' => $iteration];
            if ($step['type'] === 'workflow_loop') return (new WorkflowLoopAction)->handle($step['config'], $context);
            if ($step['id'] === 'check') {
                $count = $context->getVariable('checks', 0) + 1;
                $context->setVariable('checks', $count);
                return ['success' => true, 'output' => ['passed' => $count === 3]];
            }
            return ['success' => true, 'output' => []];
        });
        $this->assertSame(4, count(array_filter($calls, fn ($r) => $r['id'] === 'inner')));
        $this->assertSame(8, count(array_filter($calls, fn ($r) => $r['id'] === 'body')));
        $this->assertSame('after', end($calls)['id']);
        $this->assertCount(count($calls), array_unique(array_map(fn ($r) => WorkflowLoopRunner::executionId($r['id'], $r['iteration']), $calls)));
    }

    public function test_editor_allows_done_to_previous_node_body_and_itself(): void
    {
        foreach (['check', 'body', 'inner', 'after', 'outer'] as $target) {
            $definition = $this->definition();
            array_pop($definition['connections']);
            $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => $definition['actions']])->set('definition', $definition)
                ->call('connectWorkflowNodes', 'action:inner', 'done', 'action:'.$target);
            $this->assertContains($this->edge('inner', $target, 'done'), $page->get('definition')['connections'], $target);
        }
        $edges = WorkflowCanvasGraph::edges($this->definition()['actions'], $this->definition()['connections']);
        $feedback = array_values(array_filter($edges, fn ($e) => $e['sourceId'] === 'action:inner' && $e['sourcePort'] === 'done'));
        $this->assertFalse($feedback[0]['loopReturn']);
        $this->assertTrue($feedback[0]['loopFeedback']);
    }

    public function test_infinite_done_feedback_is_bounded_and_preview_never_follows_done(): void
    {
        $definition = $this->definition();
        $definition['actions'][1]['config']['conditions'][0]['right'] = 100;
        $debugger = app(WorkflowDebugger::class);
        $preview = $debugger->executeNode($definition['actions'][2], [], [], null, null, false, $definition);
        $this->assertSame(['inner'], array_column($preview['results'], 'id'));
        $this->assertSame(['id' => 99], $preview['results'][0]['output']['item']);
        $calls = 0;
        try {
            (new WorkflowLoopRunner($definition))->run(new WorkflowContext, function ($step, $context) use (&$calls) {
                $calls++;
                return $step['type'] === 'workflow_loop' ? (new WorkflowLoopAction)->handle($step['config'], $context) : ['success' => true, 'output' => ['passed' => false]];
            });
            $this->fail('Unbounded feedback');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('лимит 5000', $exception->getMessage());
            $this->assertSame(5000, $calls);
        }
    }

    public function test_done_can_leave_nested_scope_for_an_external_node(): void
    {
        $definition = $this->definition();
        $definition['connections'][array_key_last($definition['connections'])] = $this->edge('inner', 'after', 'done');
        $calls = [];
        (new WorkflowLoopRunner($definition))->run(new WorkflowContext, function ($step, $context) use (&$calls) {
            $calls[] = $step['id'];
            return $step['type'] === 'workflow_loop' ? (new WorkflowLoopAction)->handle($step['config'], $context) : ['success' => true, 'output' => ['passed' => false]];
        });
        $this->assertSame(['outer', 'check', 'inner', 'body', 'body', 'after'], $calls);
        $debugger = app(WorkflowDebugger::class);
        $session = $debugger->advance($debugger->start($definition, [], null, null));
        $this->assertSame('completed', $session['status'], $session['error'] ?? '');
        $this->assertSame($calls, array_column($session['results'], 'id'));
    }

    public function test_root_node_preview_never_enters_nested_done_feedback(): void
    {
        $definition = $this->definition();
        $definition['actions'][1]['config']['conditions'][0]['right'] = 100;
        $this->assertSame([], WorkflowDefinitionValidator::issues($definition));
        $session = app(WorkflowDebugger::class)->executeNode($definition['actions'][0], [], [], null, null, false, $definition);
        $this->assertSame('completed', $session['status']);
        $this->assertNull($session['error']);
        $this->assertSame(['outer'], array_column($session['results'], 'id'));
        $annotated = \App\Services\Workflows\WorkflowExecutionStats::annotate($session['results']);
        $this->assertGreaterThan(0, $annotated[0]['display_count']);
        $this->assertNotContains('after', array_column($session['results'], 'id'));
        Http::assertNothingSent();
    }

    public function test_cancellation_callback_stops_before_the_single_node(): void
    {
        $definition = $this->definition();
        $session = app(WorkflowDebugger::class)->executeNode($definition['actions'][0], [], [], null, null, false, $definition,
            function ($step, $sequence): void { $this->assertSame(1, $sequence); throw new \RuntimeException('Остановлено'); });
        $this->assertSame('failed', $session['status']);
        $this->assertSame('Остановлено', $session['error']);
        $this->assertSame(['outer'], array_column($session['results'], 'id'));
        $this->assertSame('error', $session['results'][0]['status']);
        Http::assertNothingSent();
    }

    public function test_production_resume_reuses_exact_pass_logs_without_repeating_writes(): void
    {
        \Tests\Support\WorkflowListDatabase::prepare();
        (require database_path('migrations/2026_06_09_160734_create_workflow_run_steps_table.php'))->up();
        $run = $this->getMockBuilder(\Leek\FilamentWorkflows\Models\WorkflowRun::class)->onlyMethods(['update'])->getMock();
        $run->id = 20;
        $run->method('update')->willReturn(true);
        $run->setRelation('workflow', (new \App\Models\Workflows\Workflow)->forceFill(['definition' => $this->definition()]));
        $executor = new class(app(ActionRegistry::class)) extends \App\Workflows\Engine\WorkflowExecutor {
            public int $executions = 0;
            public function graph($context, $run): void { $this->executeGraph($context, $run); }
            protected function runStep(array $step, \Leek\FilamentWorkflows\Context\WorkflowContext $context): array {
                $this->executions++;
                return parent::runStep($step, $context);
            }
        };
        $context = new WorkflowContext;
        $executor->graph($context, $run);
        $this->assertSame(12, $executor->executions);
        $this->assertSame(12, $run->steps()->count());
        $executor->graph($context, $run);
        $this->assertSame(12, $executor->executions);
        // Resume at the second evaluation, without performing the completed body writes again.
        $check = WorkflowLoopRunner::executionId('check', [['node' => 'outer', 'index' => 1], ['node' => 'inner', 'index' => 1, 'kind' => 'feedback']]);
        $this->assertSame(1, $run->steps()->where('step_id', $check)->delete());
        $executor->graph($context, $run);
        $this->assertSame(13, $executor->executions);
        $this->assertTrue($run->steps()->where('step_id', $check)->first()->output_data['passed']);
        Http::assertNothingSent();
    }
}
