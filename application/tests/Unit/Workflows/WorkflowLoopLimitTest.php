<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\{WorkflowDefinitionValidator, WorkflowExecutionStats, WorkflowLoopRunner, WorkflowTransfer};
use App\Workflows\Actions\WorkflowLoopAction;
use App\Workflows\Context\WorkflowContext;
use App\Workflows\Engine\WorkflowDebugger;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\{WorkflowCanvasDatabase, WorkflowCanvasFixture};
use Tests\TestCase;

class WorkflowLoopLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        Http::preventStrayRequests();
    }

    private function definition(int $limit = 2): array
    {
        return ['trigger' => ['type' => 'manual', 'config' => []], 'actions' => [
            ['id' => 'loop', 'name' => 'Цикл', 'type' => 'workflow_loop', 'config' => ['items' => [10, 20, 30], 'mode' => 'limit', 'limit' => $limit]],
            ['id' => 'body', 'type' => 'workflow_set_variable', 'config' => ['assignments' => [['name' => 'item', 'value' => '{{ $("Цикл") }}']]]],
            ['id' => 'after', 'type' => 'workflow_set_variable', 'config' => ['assignments' => [['name' => 'done', 'value' => true]]]],
        ], 'connections' => [
            ['sourceId' => 'trigger', 'sourcePort' => 'output', 'targetId' => 'action:loop'],
            ['sourceId' => 'action:loop', 'sourcePort' => 'each', 'targetId' => 'action:body'],
            ['sourceId' => 'action:body', 'sourcePort' => 'output', 'targetId' => 'action:loop'],
            ['sourceId' => 'action:loop', 'sourcePort' => 'done', 'targetId' => 'action:after'],
        ]];
    }

    public function test_limited_action_takes_first_n_items_without_changing_the_original_count(): void
    {
        $action = new WorkflowLoopAction;
        foreach ([1 => [10], 2 => [10, 20], 5 => [10, 20, 30], 1000 => [10, 20, 30]] as $limit => $expected) {
            $config = ['mode' => 'limit', 'limit' => (string) $limit, 'items' => '{{ $json.items }}'];
            $result = $action->handle($config, new WorkflowContext(['items' => [10, 20, 30]]));
            $this->assertTrue($result['success']);
            $this->assertSame($expected, $result['output']['items']);
            $this->assertSame(3, $result['output']['input_count']);
            $this->assertSame([], WorkflowDefinitionValidator::configIssues('workflow_loop', $config));
        }
        $this->assertSame([], $action->handle(['mode' => 'limit', 'limit' => 5, 'items' => []])['output']['items']);
    }

    public function test_invalid_limits_are_rejected_by_runtime_and_definition_validation(): void
    {
        foreach ([null, '', 0, -1, 1001, 1.5, true, [], '2.5', 'abc'] as $limit) {
            $config = ['mode' => 'limit', 'limit' => $limit, 'items' => [1, 2, 3]];
            $result = (new WorkflowLoopAction)->handle($config);
            $this->assertFalse($result['success']);
            $this->assertStringContainsString('Количество проходов', $result['error']);
            $this->assertNotEmpty(WorkflowDefinitionValidator::configIssues('workflow_loop', $config));
        }
        foreach (['all' => [1, 2, 3], 'once' => [1]] as $mode => $items) {
            $config = ['mode' => $mode, 'limit' => 'ignored', 'items' => [1, 2, 3]];
            $this->assertSame($items, (new WorkflowLoopAction)->handle($config)['output']['items']);
            $this->assertSame([], WorkflowDefinitionValidator::configIssues('workflow_loop', $config));
        }
    }

    public function test_normal_execution_stops_at_the_limit_and_runs_done_once(): void
    {
        $calls = [];
        (new WorkflowLoopRunner($this->definition()))->run(new WorkflowContext, function ($step, $context, $path, $iteration) use (&$calls) {
            $calls[] = ['id' => $step['id'], 'input' => $context->getNodeInput(), 'iteration' => $iteration];
            return $step['type'] === 'workflow_loop' ? (new WorkflowLoopAction)->handle($step['config'], $context) : ['success' => true, 'output' => []];
        });
        $this->assertSame(['loop', 'body', 'body', 'after'], array_column($calls, 'id'));
        $this->assertSame([10, 20], array_column(array_slice($calls, 1, 2), 'input'));
        $this->assertSame([10, 20], $calls[3]['input']);
    }

    public function test_node_preview_keeps_the_saved_limit_but_only_exposes_the_first_item(): void
    {
        $definition = $this->definition();
        $result = app(WorkflowDebugger::class)->executeNode($definition['actions'][0], [], [], null, null, false, $definition);
        $this->assertSame('completed', $result['status'], $result['error'] ?? '');
        $this->assertSame(['loop'], array_column($result['results'], 'id'));
        $this->assertSame(10, $result['results'][0]['output']['item']);
        $this->assertSame(1, WorkflowExecutionStats::annotate($result['results'])[0]['display_count']);
        $this->assertSame(2, $result['definition']['actions'][0]['config']['limit']);
        Http::assertNothingSent();
    }

    public function test_limited_mode_does_not_introduce_implicit_loop_returns(): void
    {
        $definition = $this->definition();
        unset($definition['connections'][2]);
        $debugger = app(WorkflowDebugger::class);
        $result = $debugger->advance($debugger->start($definition, [], null, null));
        $this->assertSame('completed', $result['status'], $result['error'] ?? '');
        $this->assertSame(['loop', 'body'], array_column($result['results'], 'id'));
    }

    public function test_full_debugger_applies_the_limit_before_running_done(): void
    {
        $debugger = app(WorkflowDebugger::class);
        $session = $debugger->start($this->definition(), [], null, null);
        while ($session['status'] === 'ready') $session = $debugger->advance($session);
        $this->assertSame('completed', $session['status'], $session['error'] ?? '');
        $this->assertSame(['loop', 'body', 'body', 'after'], array_column($session['results'], 'id'));
    }

    public function test_each_nested_loop_applies_its_own_limit_on_every_outer_iteration(): void
    {
        $definition = $this->definition();
        $definition['actions'][1] = ['id' => 'body', 'type' => 'workflow_loop', 'config' => ['items' => [1, 2, 3], 'mode' => 'limit', 'limit' => 1]];
        $definition['actions'][] = ['id' => 'inner', 'type' => 'workflow_set_variable', 'config' => ['assignments' => [['name' => 'inner', 'value' => 1]]]];
        $definition['connections'][2]['sourcePort'] = 'done';
        $definition['connections'][] = ['sourceId' => 'action:body', 'sourcePort' => 'each', 'targetId' => 'action:inner'];
        $definition['connections'][] = ['sourceId' => 'action:inner', 'sourcePort' => 'output', 'targetId' => 'action:body'];
        $debugger = app(WorkflowDebugger::class);
        $result = $debugger->advance($debugger->start($definition, [], null, null));
        $this->assertSame(['loop', 'body', 'inner', 'body', 'inner'], array_column($result['results'], 'id'));
        $this->assertSame('ready', $result['status'], $result['error'] ?? '');
        $result = $debugger->advance($result);
        $this->assertSame('completed', $result['status'], $result['error'] ?? '');
        $this->assertSame('after', $result['results'][5]['id']);
    }

    public function test_limited_mode_survives_export_and_import(): void
    {
        $definition = WorkflowTransfer::decode(WorkflowTransfer::encode('Лимит', $this->definition()))['definition'];
        $this->assertSame('limit', $definition['actions'][0]['config']['mode']);
        $this->assertSame(2, $definition['actions'][0]['config']['limit']);
        $this->assertSame([], WorkflowDefinitionValidator::issues($definition));
    }

    private function editor()
    {
        return Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => $this->definition()['actions']])
            ->call('openWorkflowInspector', 'action:loop');
    }

    public function test_editor_saves_and_reopens_the_numeric_limit(): void
    {
        $page = $this->editor();
        $html = implode('', $page->effects['partials'] ?? []);
        $this->assertStringContainsString('Указать количество', $html);
        $this->assertStringContainsString('Количество проходов', $html);
        $page->set('mountedActions.0.data.limit', '3')->call('callMountedAction')->assertHasNoErrors();
        $this->assertEquals(3, $page->get('workflowActions')[0]['config']['limit']);
        $page->call('openWorkflowInspector', 'action:loop')->assertSet('mountedActions.0.data.mode', 'limit')->assertSet('mountedActions.0.data.limit', 3);
    }

    public function test_invalid_editor_limit_cannot_save_but_does_not_block_other_modes(): void
    {
        foreach (['', 0, -1, 1.5, 1001] as $limit) {
            $page = $this->editor()->set('mountedActions.0.data.limit', $limit)->call('callMountedAction')
                ->assertHasErrors(['mountedActions.0.data.limit']);
        }
        $page->set('mountedActions.0.data.mode', 'all')->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame('all', $page->get('workflowActions')[0]['config']['mode']);
    }
}
