<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\{WorkflowCanvasGraph, WorkflowDefinitionValidator, WorkflowGraph, WorkflowLoopRunner, WorkflowOutputView};
use App\Workflows\Actions\WorkflowLoopAction;
use App\Workflows\Context\WorkflowContext;
use App\Workflows\Engine\WorkflowDebugger;
use Illuminate\Support\Facades\Http;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Livewire\Livewire;
use Tests\Support\{WorkflowCanvasDatabase, WorkflowCanvasFixture};
use Tests\TestCase;

class WorkflowLoopTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        Http::preventStrayRequests();
    }

    private function step(string $id, string $type = 'probe', array $config = []): array
    {
        return ['id' => $id, 'type' => $type, 'name' => $id === 'loop' ? 'Цикл' : $id, 'config' => $config];
    }

    private function edge(string $from, string $to, string $port = 'output'): array
    {
        return ['sourceId' => $from === 'trigger' ? $from : 'action:'.$from, 'sourcePort' => $port, 'targetId' => 'action:'.$to];
    }

    private function definition(array $items = [['id' => 1], ['id' => 2], ['id' => 3]], string $mode = 'all'): array
    {
        return ['trigger' => ['type' => 'manual'], 'actions' => [
            $this->step('loop', 'workflow_loop', compact('items', 'mode')),
            $this->step('body', 'probe', ['id' => '{{ $("Цикл").id }}']), $this->step('after'),
        ], 'connections' => [$this->edge('trigger', 'loop'), $this->edge('loop', 'body', 'each'), $this->edge('loop', 'after', 'done')]];
    }

    private function context(): WorkflowContext
    {
        return (new WorkflowContext)->setVariable('_node_names', ['Цикл' => ['loop']]);
    }

    private function execute(array $definition, ?WorkflowContext $context = null, ?callable $probe = null): array
    {
        $calls = [];
        (new WorkflowLoopRunner($definition))->run($context ?? $this->context(), function ($step, $ctx, $path, $iteration) use (&$calls, $probe) {
            $calls[] = ['id' => $step['id'], 'iteration' => $iteration, 'input' => $ctx->getNodeInput()];
            if ($step['type'] === 'workflow_loop') return (new WorkflowLoopAction)->handle($step['config'], $ctx);
            return $probe ? $probe($step, $ctx) : ['success' => true, 'output' => $ctx->resolve($step['config'])];
        });
        return $calls;
    }

    public function test_list_input_modes_empty_lists_and_validation(): void
    {
        $action = new WorkflowLoopAction;
        $this->assertSame([1], $action->handle(['items' => '[1,2]', 'mode' => 'once'])['output']['items']);
        $this->assertSame([1, 2], $action->handle(['items' => '{{ $json.orders }}'], (new WorkflowContext(['orders' => [1, 2]])))['output']['items']);
        $this->assertSame([], $action->handle(['items' => []])['output']['items']);
        foreach ([null, '', '{}', '{"0":1}', ['id' => 1], 5, '[bad', range(0, 1000)] as $bad) {
            $this->assertFalse($action->handle(['items' => $bad])['success']);
            $this->assertNotEmpty(WorkflowDefinitionValidator::configIssues('workflow_loop', ['items' => $bad]));
        }
        $this->assertFalse($action->handle(['items' => [], 'mode' => 'invalid'])['success']);
    }

    public function test_all_items_repeat_body_and_done_runs_once_without_wrappers(): void
    {
        $context = $this->context();
        $calls = $this->execute($this->definition(), $context);
        $this->assertSame(['loop', 'body', 'body', 'body', 'after'], array_column($calls, 'id'));
        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], array_column(array_slice($calls, 1, 3), 'input'));
        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $calls[4]['input']);
        $this->assertNull($context->getStepOutput('body'));
        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], WorkflowOutputView::value($context->getStepOutput('loop')));
    }

    public function test_once_and_empty_input(): void
    {
        $this->assertSame(['loop', 'body', 'after'], array_column($this->execute($this->definition([['id' => 1], ['id' => 2]], 'once')), 'id'));
        $this->assertSame(['loop', 'after'], array_column($this->execute($this->definition([])), 'id'));
    }

    public function test_iteration_outputs_and_variables_do_not_leak_and_condition_selects_only_one_branch(): void
    {
        $def = $this->definition();
        $def['actions'][1] = $this->step('body', 'control-condition');
        $def['actions'][] = $this->step('yes');
        $def['actions'][] = $this->step('no');
        $def['connections'][] = $this->edge('body', 'yes', 'yes');
        $def['connections'][] = $this->edge('body', 'no', 'no');
        // Converging on the after branch must not run it on each iteration.
        $def['connections'][] = $this->edge('yes', 'after');
        $def['connections'][] = $this->edge('no', 'after');
        $calls = $this->execute($def, null, function ($step, $ctx) {
            if ($step['id'] === 'body') {
                $this->assertNull($ctx->getStepOutput('yes'));
                $this->assertNull($ctx->getVariable('test_iteration'));
                $ctx->setVariable('test_iteration', true);
                return ['success' => true, 'output' => ['passed' => $ctx->get('$("Цикл").id') !== 2]];
            }
            return ['success' => true, 'output' => ['contact' => ['id' => 555]]];
        });
        $this->assertSame(['loop', 'body', 'yes', 'body', 'no', 'body', 'yes', 'after'], array_column($calls, 'id'));
    }

    public function test_nested_cycles_are_scoped_and_have_distinct_execution_keys(): void
    {
        $def = $this->definition([['id' => 1], ['id' => 2]]);
        $def['actions'][1] = $this->step('body', 'workflow_loop', ['items' => [1, 2]]);
        $def['actions'][] = $this->step('inner');
        $def['connections'][] = $this->edge('body', 'inner', 'each');
        $calls = $this->execute($def);
        $inner = array_values(array_filter($calls, fn ($call) => $call['id'] === 'inner'));
        $this->assertCount(4, $inner);
        $keys = array_map(fn ($call) => WorkflowLoopRunner::executionId('inner', $call['iteration']), $inner);
        $this->assertCount(4, array_unique($keys));
        $this->assertSame($keys[0], WorkflowLoopRunner::executionId('inner', $inner[0]['iteration']));
    }

    public function test_invalid_cross_entry_and_cycles_are_rejected_before_execution(): void
    {
        $def = $this->definition();
        $def['connections'][] = $this->edge('trigger', 'body');
        $this->assertStringContainsString('Внутрь цикла', implode(' ', WorkflowDefinitionValidator::issues($def)));
        $def = $this->definition();
        $def['connections'][] = $this->edge('body', 'loop');
        $this->assertStringContainsString('создаёт цикл', implode(' ', WorkflowDefinitionValidator::issues($def)));
        $def = $this->definition();
        $def['connections'][] = $this->edge('loop', 'body', 'done');
        $this->assertStringContainsString('не должна начинаться', implode(' ', WorkflowDefinitionValidator::issues($def)));
    }

    private function debugger(): WorkflowDebugger
    {
        $registry = $this->createMock(ActionRegistry::class);
        $registry->method('has')->willReturn(true);
        $registry->method('resolve')->willReturnCallback(fn ($type) => $type === 'workflow_loop' ? new WorkflowLoopAction : new class {
            public function handle(array $config): array { return ['success' => true, 'output' => $config]; }
        });
        return new WorkflowDebugger($registry);
    }

    public function test_node_preview_executes_first_item_and_body_but_not_after_and_preserves_item_for_picker(): void
    {
        $def = $this->definition();
        $result = $this->debugger()->executeNode($def['actions'][0], [], [], null, null, false, $def);
        $this->assertSame('completed', $result['status'], $result['error'] ?? '');
        $this->assertSame(['loop', 'body'], array_column($result['results'], 'id'));
        $this->assertSame(1, $result['results'][1]['output']['id']);
        $this->assertSame(['id' => 1], WorkflowOutputView::value($result['results'][0]['output']));
        $this->assertSame(1, WorkflowContext::fromArray($result['context'])->get('$("Цикл").id'));
        Http::assertNothingSent();
    }

    public function test_full_debugger_runs_all_iterations_and_advances_to_after(): void
    {
        $runner = $this->debugger();
        $session = $runner->advance($runner->start($this->definition(), [], null, null));
        $this->assertSame(['loop', 'body', 'body', 'body'], array_column($session['results'], 'id'));
        $this->assertSame('after', $session['pending'][0]['step']['id']);
        $this->assertSame([1, 2, 3], array_column(array_column(array_slice($session['results'], 1), 'output'), 'id'));
        $session = $runner->advance($session);
        $this->assertSame('completed', $session['status']);
        $this->assertCount(5, $session['results']);
    }

    public function test_safe_preview_never_sends_http_and_body_failure_stops_remaining_items_and_done(): void
    {
        $def = $this->definition();
        $def['actions'][1] = $this->step('body', 'http_request', ['url' => 'https://example.com/write', 'method' => 'POST']);
        $session = app(WorkflowDebugger::class)->executeNode($def['actions'][0], [], [], null, null, false, $def);
        $this->assertSame('completed', $session['status'], $session['error'] ?? '');
        $this->assertSame('simulated', $session['results'][1]['status']);
        Http::assertNothingSent();

        $def = $this->definition();
        $def['actions'][1] = $this->step('body', 'control-condition', ['conditions' => [[
            'left' => '{{ $("Цикл").missing }}', 'operator' => 'equals', 'right' => 1,
        ]]]);
        $session = app(WorkflowDebugger::class)->advance(app(WorkflowDebugger::class)->start($def, [], null, null));
        $this->assertSame('failed', $session['status']);
        $this->assertSame(['loop', 'body'], array_column($session['results'], 'id'));
        $this->assertNotEmpty($session['error']);
    }

    public function test_disabled_loop_skips_body_and_execution_limit_stops_nested_expansion(): void
    {
        $def = $this->definition();
        $def['actions'][0]['disabled'] = true;
        $calls = $this->execute($def);
        $this->assertSame(['loop', 'after'], array_column($calls, 'id'));
        $def = $this->definition(range(1, 1000));
        $def['actions'][1] = $this->step('body', 'workflow_loop', ['items' => range(1, 10)]);
        $def['actions'][] = $this->step('inner');
        $def['connections'][] = $this->edge('body', 'inner', 'each');
        $this->expectExceptionMessage('лимит 5000');
        $this->execute($def);
    }

    public function test_production_logs_separate_iterations_and_replay_reuses_each_write(): void
    {
        \Tests\Support\WorkflowListDatabase::prepare();
        (require database_path('migrations/2026_06_09_160734_create_workflow_run_steps_table.php'))->up();
        $def = $this->definition();
        $run = $this->getMockBuilder(\Leek\FilamentWorkflows\Models\WorkflowRun::class)->onlyMethods(['update'])->getMock();
        $run->id = 20;
        $run->method('update')->willReturn(true);
        $run->setRelation('workflow', (new \App\Models\Workflows\Workflow)->forceFill(['definition' => $def]));
        $executor = new class(app(ActionRegistry::class)) extends \App\Workflows\Engine\WorkflowExecutor {
            public array $writes = [];
            public function graph($context, $run): void { $this->executeGraph($context, $run); }
            protected function runStep(array $step, \Leek\FilamentWorkflows\Context\WorkflowContext $context): array
            {
                if ($step['type'] === 'workflow_loop') return (new WorkflowLoopAction)->handle($step['config'], $context);
                $input = $context->resolve($step['config']);
                $this->writes[] = $input;
                $context->setVariable('_resolved_inputs.'.$step['id'], $input);
                return ['success' => true, 'output' => $input];
            }
        };
        $context = $this->context();
        $executor->graph($context, $run);
        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3], []], $executor->writes);
        $executor->graph($context, $run);
        $this->assertCount(4, $executor->writes);
        $logs = $run->steps()->orderBy('id')->get();
        $this->assertCount(5, $logs);
        $this->assertCount(5, array_unique($logs->pluck('step_id')->all()));
        $this->assertSame('body', $logs[1]->input_data['_node_id']);
        $this->assertSame(0, $logs[1]->input_data['_loop_iteration'][0]['index']);
        $this->assertSame(1, $logs[2]->input_data['_loop_iteration'][0]['index']);
        $this->assertSame(['id' => 2], $logs[2]->input_data['_resolved_input']);
        $run->context_data = ['variables' => ['_definition_snapshot' => $def]];
        $run->setRelation('steps', $logs);
        $graph = \App\Services\Workflows\WorkflowExecutionGraph::fromRun($run);
        $this->assertSame(['loop', 'body', 'body', 'body', 'after'], array_column($graph['results'], 'id'));
        $this->assertArrayNotHasKey('_node_id', $graph['results'][1]['input']);
        Http::assertNothingSent();
    }

    public function test_editor_registers_loop_with_two_ports_and_saves_input_and_once_mode(): void
    {
        $this->assertTrue(app(ActionRegistry::class)->has('workflow_loop'));
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => []])->call('selectActionType', 'workflow_loop');
        $node = $page->get('workflowActions')[0];
        $this->assertSame('workflow_loop', $node['type']);
        $this->assertArrayHasKey('connections', $page->get('definition'));
        $page->assertSee('Каждый элемент')->assertSee('После цикла');
        $page->call('openWorkflowActionEditor', $node['id']);
        $schema = (new \ReflectionMethod($page->instance(), 'getMountedActionSchema'))->invoke($page->instance());
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $this->assertTrue(str_contains($schema->toHtml(), 'Выполнить 1 раз'));
        $page->set('mountedActions.0.data.items', '[{"id":17},{"id":18}]')->set('mountedActions.0.data.mode', 'once')
            ->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame('once', $page->get('workflowActions')[0]['config']['mode']);
        $page->call('openWorkflowActionEditor', $node['id'])->assertSet('mountedActions.0.data.mode', 'once');
        $edges = WorkflowCanvasGraph::edges([$node], []);
        $this->assertSame(['each', 'done'], array_column(array_filter($edges, fn ($edge) => $edge['sourceId'] === 'action:'.$node['id']), 'sourcePort'));
    }
}
