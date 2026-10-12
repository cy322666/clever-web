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
        $def = $this->definition();
        $def['connections'][] = $this->edge('body', 'loop');
        $calls = $this->execute($def, $context);
        $this->assertSame(['loop', 'body', 'body', 'body', 'after'], array_column($calls, 'id'));
        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], array_column(array_slice($calls, 1, 3), 'input'));
        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $calls[4]['input']);
        $this->assertNull($context->getStepOutput('body'));
        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], WorkflowOutputView::value($context->getStepOutput('loop')));
    }

    public function test_once_and_empty_input(): void
    {
        $def = $this->definition([['id' => 1], ['id' => 2]], 'once');
        $def['connections'][] = $this->edge('body', 'loop');
        $this->assertSame(['loop', 'body', 'after'], array_column($this->execute($def), 'id'));
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
        $def['connections'][] = $this->edge('yes', 'loop');
        $def['connections'][] = $this->edge('no', 'loop');
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
        $def['connections'][] = $this->edge('inner', 'body');
        $def['connections'][] = $this->edge('body', 'loop', 'done');
        $calls = $this->execute($def);
        $inner = array_values(array_filter($calls, fn ($call) => $call['id'] === 'inner'));
        $this->assertCount(4, $inner);
        $keys = array_map(fn ($call) => WorkflowLoopRunner::executionId('inner', $call['iteration']), $inner);
        $this->assertCount(4, array_unique($keys));
        $this->assertSame($keys[0], WorkflowLoopRunner::executionId('inner', $inner[0]['iteration']));
    }

    public function test_invalid_cycles_are_rejected_before_execution(): void
    {
        $def = $this->definition();
        $def['connections'][] = $this->edge('body', 'after');
        $def['connections'][] = $this->edge('after', 'body');
        $this->assertStringContainsString('неуправляемый цикл', implode(' ', WorkflowDefinitionValidator::issues($def)));
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
        $def = $this->definition();
        $def['connections'][] = $this->edge('body', 'loop');
        $session = $runner->advance($runner->start($def, [], null, null));
        $this->assertSame(['loop', 'body', 'body', 'body'], array_column($session['results'], 'id'));
        $this->assertSame('after', $session['pending'][0]['step']['id']);
        $this->assertSame([1, 2, 3], array_column(array_column(array_slice($session['results'], 1), 'output'), 'id'));
        $session = $runner->advance($session);
        $this->assertSame('completed', $session['status']);
        $this->assertCount(5, $session['results']);
    }

    public function test_manual_loop_run_respects_all_once_and_empty_input_without_running_done(): void
    {
        foreach ([['all', [1, 2, 3], 3], ['once', [1, 2, 3], 1], ['all', [], 0]] as [$mode, $ids, $count]) {
            $def = $this->definition(array_map(fn ($id) => compact('id'), $ids), $mode);
            $def['connections'][] = $this->edge('body', 'loop');
            $session = $this->debugger()->executeNode($def['actions'][0], [], [], null, null, false, $def);
            $this->assertSame('completed', $session['status'], $session['error'] ?? '');
            $this->assertCount(1 + $count, $session['results']);
            $this->assertNotContains('after', array_column($session['results'], 'id'));
            $counts = \App\Services\Workflows\WorkflowExecutionStats::annotate($session['results']);
            $this->assertSame($count, $counts[0]['display_count']);
        }
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
        $def['connections'][] = $this->edge('inner', 'body');
        $def['connections'][] = $this->edge('body', 'loop', 'done');
        $this->expectExceptionMessage('лимит 5000');
        $this->execute($def);
    }

    public static function entryVariants(): array
    {
        return ['loop only' => [false], 'loop and external entry' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('entryVariants')]
    public function test_production_logs_separate_iterations_and_replay_reuses_each_write(bool $external): void
    {
        \Tests\Support\WorkflowListDatabase::prepare();
        (require database_path('migrations/2026_06_09_160734_create_workflow_run_steps_table.php'))->up();
        $def = $this->definition();
        $def['connections'][] = $this->edge('body', 'loop');
        if ($external) $def['connections'][] = $this->edge('trigger', 'body');
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
        $expectedWrites = [['id' => 1], ['id' => 2], ['id' => 3]];
        if ($external) $expectedWrites[] = ['id' => null];
        $expectedWrites[] = [];
        $this->assertSame($expectedWrites, $executor->writes);
        $executor->graph($context, $run);
        $this->assertCount(count($expectedWrites), $executor->writes);
        $logs = $run->steps()->orderBy('id')->get();
        $this->assertCount(count($expectedWrites) + 1, $logs);
        $this->assertCount(count($expectedWrites) + 1, array_unique($logs->pluck('step_id')->all()));
        $this->assertSame('body', $logs[1]->input_data['_node_id']);
        $this->assertSame(0, $logs[1]->input_data['_loop_iteration'][0]['index']);
        $this->assertSame(1, $logs[2]->input_data['_loop_iteration'][0]['index']);
        $this->assertSame(['id' => 2], $logs[2]->input_data['_resolved_input']);
        $run->context_data = ['variables' => ['_definition_snapshot' => $def]];
        $run->setRelation('steps', $logs);
        $graph = \App\Services\Workflows\WorkflowExecutionGraph::fromRun($run);
        $this->assertSame($external ? ['loop', 'body', 'body', 'body', 'body', 'after'] : ['loop', 'body', 'body', 'body', 'after'], array_column($graph['results'], 'id'));
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
        $page->assertSee('Каждый элемент')->assertSee('Готово');
        $page->call('openWorkflowActionEditor', $node['id']);
        $schema = (new \ReflectionMethod($page->instance(), 'getMountedActionSchema'))->invoke($page->instance());
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $this->assertStringContainsString('Запустить', $schema->toHtml());
        $this->assertStringNotContainsString('Выполнить 1 раз', $schema->toHtml());
        $page->set('mountedActions.0.data.items', '[{"id":17},{"id":18}]')->set('mountedActions.0.data.mode', 'once')
            ->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame('once', $page->get('workflowActions')[0]['config']['mode']);
        $this->assertTrue($page->get('workflowActions')[0]['config']['require_return']);
        $page->call('openWorkflowActionEditor', $node['id'])->assertSet('mountedActions.0.data.mode', 'once');
        $edges = WorkflowCanvasGraph::edges([$node], []);
        $this->assertSame(['each', 'done'], array_column(array_filter($edges, fn ($edge) => $edge['sourceId'] === 'action:'.$node['id']), 'sourcePort'));
    }

    public function test_explicit_return_advances_items_without_reexecuting_loop_and_done_runs_once(): void
    {
        $def = $this->definition();
        $def['connections'][] = $this->edge('body', 'loop');
        $context = $this->context();
        $calls = $this->execute($def, $context);
        $this->assertSame(['loop', 'body', 'body', 'body', 'after'], array_column($calls, 'id'));
        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $calls[4]['input']);
        $this->assertSame([], WorkflowGraph::ancestors($def, 'action:loop'));
        $this->assertSame(['action:loop'], WorkflowGraph::ancestors($def, 'action:body'));
        $edges = WorkflowCanvasGraph::edges($def['actions'], $def['connections']);
        $returns = array_values(array_filter($edges, fn ($e) => $e['loopReturn'] ?? false));
        $this->assertCount(1, $returns);
        $this->assertSame('action:body', $returns[0]['sourceId']);
        $this->assertSame('action:loop', $returns[0]['targetId']);
    }

    public function test_either_condition_output_can_return_directly_to_its_loop(): void
    {
        $def = $this->definition();
        $def['actions'][1] = $this->step('body', 'control-condition');
        $def['connections'][] = $this->edge('body', 'loop', 'yes');
        $def['connections'][] = $this->edge('body', 'loop', 'no');
        $calls = $this->execute($def, null, fn ($step, $ctx) => ['success' => true, 'output' => ['passed' => $ctx->get('$("Цикл").id') !== 2]]);
        $this->assertSame(['loop', 'body', 'body', 'body', 'after'], array_column($calls, 'id'));
    }

    public function test_an_open_selected_branch_stops_iteration_without_done(): void
    {
        $def = $this->definition();
        $def['actions'][1] = $this->step('body', 'control-condition');
        $def['connections'][] = $this->edge('body', 'loop', 'yes');
        $calls = $this->execute($def, null, fn ($step, $ctx) => ['success' => true, 'output' => ['passed' => $ctx->get('$("Цикл").id') !== 2]]);
        $this->assertSame(['loop', 'body', 'body'], array_column($calls, 'id'));
        $def['actions'][0]['config']['require_return'] = true;
        array_pop($def['connections']);
        $this->assertSame(['loop', 'body'], array_column($this->execute($def), 'id'));
    }

    public function test_closed_nested_loops_return_to_their_own_scope(): void
    {
        $def = $this->definition([1, 2]);
        $def['actions'][1] = $this->step('body', 'workflow_loop', ['items' => [10, 20]]);
        $def['actions'][] = $this->step('inner');
        $def['connections'][] = $this->edge('body', 'inner', 'each');
        $def['connections'][] = $this->edge('inner', 'body');
        $def['connections'][] = $this->edge('body', 'loop', 'done');
        $calls = $this->execute($def);
        $this->assertSame(['loop', 'body', 'inner', 'inner', 'body', 'inner', 'inner', 'after'], array_column($calls, 'id'));
        $this->assertCount(2, WorkflowGraph::loopReturns($def));
        $def['connections'][] = $this->edge('inner', 'loop');
        $this->expectException(\InvalidArgumentException::class);
        new WorkflowLoopRunner($def);
    }

    public function test_closed_loop_once_empty_disabled_and_failure(): void
    {
        foreach ([['once', [1, 2], ['loop', 'body', 'after']], ['all', [], ['loop', 'after']]] as [$mode, $items, $expected]) {
            $def = $this->definition($items, $mode);
            $def['connections'][] = $this->edge('body', 'loop');
            $this->assertSame($expected, array_column($this->execute($def), 'id'));
        }
        $def = $this->definition();
        $def['connections'][] = $this->edge('body', 'loop');
        $def['actions'][0]['disabled'] = true;
        $this->assertSame(['loop', 'after'], array_column($this->execute($def), 'id'));
        unset($def['actions'][0]['disabled']);
        $this->expectExceptionMessage('test failure');
        $this->execute($def, null, fn () => ['success' => false, 'error' => 'test failure']);
    }

    public function test_debugger_only_activates_done_after_an_actual_return(): void
    {
        $def = $this->definition();
        $def['actions'][0]['config']['require_return'] = true;
        $runner = $this->debugger();
        $session = $runner->advance($runner->start($def, [], null, null));
        $this->assertSame(['loop', 'body'], array_column($session['results'], 'id'));
        $this->assertSame([], $session['pending']);
        $def['connections'][] = $this->edge('body', 'loop');
        $session = $runner->advance($runner->start($def, [], null, null));
        $this->assertSame('after', $session['pending'][0]['step']['id']);
        $preview = $runner->executeNode($def['actions'][0], [], [], null, null, false, $def);
        $this->assertSame(['loop', 'body', 'body', 'body'], array_column($preview['results'], 'id'));
        $this->assertSame(1, $preview['results'][1]['output']['id']);
    }

    public function test_editor_connects_and_disconnects_a_return_without_enabling_implicit_iteration(): void
    {
        $def = $this->definition();
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => $def['actions']])->set('definition', $def)
            ->call('connectWorkflowNodes', 'action:body', 'output', 'action:loop');
        $this->assertCount(4, $page->get('definition')['connections']);
        $this->assertTrue($page->get('workflowActions')[0]['config']['require_return']);
        $page->assertSeeHtml('data-workflow-loop-return="true"');
        $page->call('disconnectWorkflowNodes', 'action:body', 'output', 'action:loop');
        $this->assertTrue($page->get('workflowActions')[0]['config']['require_return']);
        $this->assertSame(['loop', 'body'], array_column($this->execute($page->get('definition')), 'id'));
    }

    public function test_parallel_returns_advance_once_and_keep_both_branches(): void
    {
        $def = $this->definition([1, 2]);
        $def['actions'][] = $this->step('second');
        $def['connections'][] = $this->edge('loop', 'second', 'each');
        $def['connections'][] = $this->edge('body', 'loop');
        $def['connections'][] = $this->edge('second', 'loop');
        $this->assertSame(['loop', 'body', 'second', 'body', 'second', 'after'], array_column($this->execute($def), 'id'));
    }

    public function test_import_export_preserves_return_edges_and_allows_cross_entry(): void
    {
        $def = $this->definition();
        $def['actions'][1]['type'] = $def['actions'][2]['type'] = 'http_request';
        $def['connections'][] = $this->edge('body', 'loop');
        $json = \App\Services\Workflows\WorkflowTransfer::encode('Замкнутый цикл', $def);
        $decoded = \App\Services\Workflows\WorkflowTransfer::decode($json);
        $this->assertSame($def['connections'], $decoded['definition']['connections']);
        $def['connections'][] = $this->edge('trigger', 'body');
        $decoded = \App\Services\Workflows\WorkflowTransfer::decode(\App\Services\Workflows\WorkflowTransfer::encode('Внешний вход', $def));
        $this->assertSame($def['connections'], $decoded['definition']['connections']);
    }

    public function test_old_and_new_open_loops_stop_after_first_item_regardless_of_legacy_flag(): void
    {
        foreach ([null, false, true] as $flag) {
            $def = $this->definition();
            if ($flag !== null) $def['actions'][0]['config']['require_return'] = $flag;
            $calls = $this->execute($def);
            $this->assertSame(['loop', 'body'], array_column($calls, 'id'));
            $this->assertSame(['id' => 1], $calls[1]['input']);
            $runner = $this->debugger();
            $session = $runner->advance($runner->start($def, [], null, null));
            $this->assertSame(['loop', 'body'], array_column($session['results'], 'id'));
            $this->assertSame('completed', $session['status']);
            $this->assertSame([], $session['pending']);
        }
    }

    public function test_editor_allows_external_entry_and_each_scope_runs_with_its_own_input(): void
    {
        $def = $this->definition([['id' => 1], ['id' => 2]]);
        $def['connections'][] = $this->edge('body', 'loop');
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => $def['actions']])->set('definition', $def)
            ->call('connectWorkflowNodes', 'trigger', 'output', 'action:body');
        $def = $page->get('definition');
        $this->assertContains($this->edge('trigger', 'body'), $def['connections']);
        $context = $this->context()->setTriggerData(['external' => true]);
        $calls = $this->execute($def, $context);
        $this->assertSame(['loop', 'body', 'body', 'body', 'after'], array_column($calls, 'id'));
        $this->assertSame([['id' => 1], ['id' => 2], ['external' => true]], array_column(array_slice($calls, 1, 3), 'input'));
        $this->assertSame([], $calls[3]['iteration']);
        $this->assertCount(5, array_unique(array_map(fn ($call) => WorkflowLoopRunner::executionId($call['id'], $call['iteration']), $calls)));

        $runner = $this->debugger();
        $session = $runner->start($def, ['external' => true], null, null);
        for ($i = 0; $i < 8 && $session['status'] === 'ready'; $i++) $session = $runner->advance($session);
        $this->assertSame('completed', $session['status'], $session['error'] ?? '');
        $this->assertSame(array_column($calls, 'id'), array_column($session['results'], 'id'));
        $this->assertEmpty($session['results'][3]['iteration'] ?? []);
    }

    public function test_external_entry_can_run_an_open_body_without_starting_the_loop(): void
    {
        $def = $this->definition();
        $def['connections'][0] = $this->edge('trigger', 'body');
        $calls = $this->execute($def, $this->context()->setTriggerData(['external' => true]));
        $this->assertSame(['body'], array_column($calls, 'id'));
        $this->assertSame(['external' => true], $calls[0]['input']);
        $this->assertSame([], $calls[0]['iteration']);
    }

    public function test_external_return_starts_unvisited_loop_with_the_returning_node_input(): void
    {
        $def = $this->definition();
        $def['connections'][0] = $this->edge('trigger', 'body');
        $def['connections'][] = $this->edge('body', 'loop');
        $def['actions'][0]['config']['items'] = '{{ $json }}';
        $def['actions'][1]['config'] = [['id' => 8], ['id' => 9]];
        $calls = $this->execute($def);
        $this->assertSame(['body', 'loop', 'body', 'body', 'after'], array_column($calls, 'id'));
        $this->assertSame([['id' => 8], ['id' => 9]], $calls[1]['input']);
        $this->assertSame([['id' => 8], ['id' => 9]], array_column(array_slice($calls, 2, 2), 'input'));

        $runner = $this->debugger();
        $session = $runner->start($def, [], null, null);
        for ($i = 0; $i < 8 && $session['status'] === 'ready'; $i++) $session = $runner->advance($session);
        $this->assertSame('completed', $session['status'], $session['error'] ?? '');
        $this->assertSame(array_column($calls, 'id'), array_column($session['results'], 'id'));
    }

    public function test_two_loops_can_share_nodes_without_advancing_each_other(): void
    {
        $def = $this->definition([['id' => 1], ['id' => 2]]);
        $def['actions'][] = $this->step('other', 'workflow_loop', ['items' => [['id' => 3], ['id' => 4]]]);
        $def['connections'][] = $this->edge('trigger', 'other');
        $def['connections'][] = $this->edge('other', 'body', 'each');
        $def['connections'][] = $this->edge('body', 'loop');
        $def['connections'][] = $this->edge('body', 'other');
        $calls = $this->execute($def);
        $this->assertSame(['loop', 'body', 'body', 'other', 'body', 'body', 'after'], array_column($calls, 'id'));
        $body = array_values(array_filter($calls, fn ($call) => $call['id'] === 'body'));
        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]], array_column($body, 'input'));
        $this->assertCount(4, array_unique(array_map(fn ($call) => WorkflowLoopRunner::executionId('body', $call['iteration']), $body)));
        $runner = $this->debugger();
        $session = $runner->start($def, [], null, null);
        for ($i = 0; $i < 8 && $session['status'] === 'ready'; $i++) $session = $runner->advance($session);
        $this->assertSame('completed', $session['status'], $session['error'] ?? '');
        $this->assertSame(array_column($calls, 'id'), array_column($session['results'], 'id'));
    }

    public function test_an_inactive_external_branch_does_not_run_or_override_loop_input(): void
    {
        $def = $this->definition([['id' => 7], ['id' => 8]]);
        $def['actions'][] = $this->step('gate', 'control-condition');
        $def['actions'][] = $this->step('external');
        $def['connections'][0] = $this->edge('trigger', 'gate');
        $def['connections'][] = $this->edge('gate', 'loop', 'yes');
        $def['connections'][] = $this->edge('gate', 'external', 'no');
        $def['connections'][] = $this->edge('external', 'body');
        $def['connections'][] = $this->edge('body', 'loop');
        $calls = $this->execute($def, null, fn () => ['success' => true, 'output' => ['passed' => true]]);
        $this->assertSame(['gate', 'loop', 'body', 'body', 'after'], array_column($calls, 'id'));
        $this->assertSame([['id' => 7], ['id' => 8]], array_column(array_slice($calls, 2, 2), 'input'));
    }

    public function test_inserting_on_return_and_removing_tail_preserve_the_loop(): void
    {
        $def = $this->definition();
        $def['actions'][0]['config']['require_return'] = true;
        $def['connections'][] = $this->edge('body', 'loop');
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => $def['actions']])->set('definition', $def);
        $page->call('openAddActionOnConnection', 'action:body', 'output', 'action:loop')->call('selectActionType', 'http_request');
        $this->assertCount(4, $page->get('workflowActions'));
        $this->assertCount(1, WorkflowGraph::loopReturns($page->get('definition')));
        $this->assertCount(8, $this->execute($page->get('definition')));
        $return = array_values(WorkflowGraph::loopReturns($page->get('definition')))[0];
        $page->call('removeWorkflowAction', substr($return['sourceId'], 7));
        $this->assertSame('action:body', array_values(WorkflowGraph::loopReturns($page->get('definition')))[0]['sourceId']);
        $page->call('removeWorkflowAction', 'body');
        $this->assertSame([], WorkflowGraph::targets($page->get('definition')['connections'], 'action:loop', 'each'));
        $this->assertSame(['loop'], array_column($this->execute($page->get('definition')), 'id'));
    }
}
