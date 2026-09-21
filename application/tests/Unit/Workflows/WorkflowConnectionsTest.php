<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowGraph;
use App\Workflows\Engine\WorkflowDebugger;
use App\Workflows\Engine\WorkflowTestRunner;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowCanvasFixture;
use Tests\TestCase;

class WorkflowConnectionsTest extends TestCase
{
    public function test_switching_catalogue_categories_keeps_the_plus_insertion_edge(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)
            ->call('openAddActionOnConnection', 'action:condition', 'no', 'action:note')
            ->call('switchActionPalette', 'service')
            ->call('switchActionPalette', 'query')
            ->call('selectReadOperation', 'tasks.list');
        $definition = $page->get('definition');
        $id = 'action:'.$definition['actions'][1]['id'];
        $this->assertSame('tasks.list', $definition['actions'][1]['config']['operation']);
        $this->assertSame([$id], WorkflowGraph::targets($definition['connections'], 'action:condition', 'no'));
        $this->assertSame(['action:note'], WorkflowGraph::targets($definition['connections'], $id));
        $fields = (new \ReflectionMethod(\App\Workflows\Actions\AmoCrmReadAction::class, 'schema'))->invoke(null);
        $this->assertInstanceOf(\Filament\Forms\Components\Hidden::class, $fields[0]);
        $this->assertSame('operation', $fields[0]->getName());
    }

    public function test_closing_plus_insertion_then_opening_catalogue_creates_a_detached_node(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)
            ->call('openAddActionOnConnection', 'action:condition', 'no', 'action:note')
            ->call('cancelActionInsertion')->call('switchActionPalette', 'query')
            ->call('selectReadOperation', 'tasks.list');
        $definition = $page->get('definition');
        $id = 'action:'.$definition['actions'][1]['id'];
        $this->assertSame(['action:note'], WorkflowGraph::targets($definition['connections'], 'action:condition', 'no'));
        $this->assertSame([], array_values(array_filter($definition['connections'], fn($edge) => $edge['targetId'] === $id)));
    }

    public function test_a_start_without_an_outgoing_edge_connects_to_an_existing_node(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)
            ->call('disconnectWorkflowNodes', 'trigger', 'output', 'action:condition');
        $this->assertSame([], WorkflowGraph::targets($page->get('definition')['connections'], 'trigger'));
        $page->call('connectWorkflowNodes', 'trigger', 'output', 'action:note', null);
        $this->assertSame(['action:note'], WorkflowGraph::targets($page->get('definition')['connections'], 'trigger'));
    }

    public function test_condition_plus_adds_a_second_branch_without_replacing_the_first(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)
            ->call('openAddActionOnConnection', 'action:condition', 'yes', null)
            ->call('selectActionType', 'amocrm_add_note');

        $targets = WorkflowGraph::targets($page->get('definition')['connections'], 'action:condition', 'yes');

        $this->assertCount(2, $targets);
        $this->assertContains('action:task', $targets);
    }

    public function test_an_unconnected_company_action_can_connect_to_a_detached_condition(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class);
        $before = WorkflowGraph::nodes($page->get('workflowActions'));
        $page->call('openDetachedActionPalette')->call('selectActionType', 'amocrm_update_company_fields');
        $nodes = WorkflowGraph::nodes($page->get('workflowActions'));
        $source = array_key_first(array_diff_key($nodes, $before));
        $this->assertNotNull($source);
        $page->call('openDetachedActionPalette')->call('selectActionType', 'control-condition');
        $new = array_diff_key(WorkflowGraph::nodes($page->get('workflowActions')), $nodes);
        $target = array_key_first($new);
        $this->assertNotNull($target);
        $page->call('connectWorkflowNodes', $source, 'output', $target, null);
        $this->assertSame([$target], WorkflowGraph::targets($page->get('definition')['connections'], $source));
    }

    protected function setUp(): void { parent::setUp(); WorkflowCanvasDatabase::prepare(); \Illuminate\Support\Facades\Http::preventStrayRequests(); }

    public function test_catalog_creates_an_orphan_and_inserting_on_an_edge_splices_it(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)->call('openDetachedActionPalette')->call('selectActionType', 'amocrm_add_note');
        $definition = $page->get('definition');
        $id = 'action:'.$definition['actions'][1]['id'];
        $this->assertSame([], array_filter($definition['connections'], fn($edge) => $edge['targetId'] === $id));
        $page->call('openAddActionOnConnection', 'action:condition', 'no', 'action:note')->call('selectActionType', 'amocrm_create_task');
        $newId = 'action:'.$page->get('workflowActions')[2]['id'];
        $edges = $page->get('definition')['connections'];
        $this->assertSame([$newId], WorkflowGraph::targets($edges, 'action:condition', 'no'));
        $this->assertSame(['action:note'], WorkflowGraph::targets($edges, $newId));
        $page->assertDispatched('workflow-node-inserted', nodeId: $newId, sourceId: 'action:condition', sourcePort: 'no', targetId: 'action:note');
        $page->assertSet('mountedActions', []);
    }

    public function test_condition_output_can_be_disconnected_reconnected_and_rejects_cycles(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)
            ->call('disconnectWorkflowNodes', 'action:condition', 'no', 'action:note');
        $this->assertSame([], WorkflowGraph::targets($page->get('definition')['connections'], 'action:condition', 'no'));
        $page->call('connectWorkflowNodes', 'action:condition', 'no', 'action:task');
        $this->assertSame([], WorkflowGraph::targets($page->get('definition')['connections'], 'action:condition', 'no'));
        $page->call('connectWorkflowNodes', 'action:condition', 'no', 'action:note');
        $definition = $page->get('definition');
        $this->assertSame(['action:note'], WorkflowGraph::targets($definition['connections'], 'action:condition', 'no'));
        $page->call('connectWorkflowNodes', 'action:task', 'output', 'action:condition');
        $this->assertSame($definition['connections'], $page->get('definition')['connections']);
        $page->call('removeWorkflowAction', 'condition');
        $this->assertSame(['task', 'note'], array_column($page->get('workflowActions'), 'id'));
        $this->assertSame([], $page->get('definition')['connections']);
    }

    public function test_debug_and_test_execute_only_the_chosen_connections_once(): void
    {
        $definition = $this->definition();
        $runner = app(WorkflowDebugger::class);
        $session = $runner->start($definition, [], null, null);
        for ($i = 0; $i < 8 && $session['status'] === 'ready'; $i++) $session = $runner->advance($session);
        $this->assertSame('completed', $session['status']);
        $this->assertSame(['if', 'yes', 'join'], array_column($session['results'], 'id'));
        $this->assertSame('true', $session['results'][0]['executed_branch']);
        $amo = $this->createMock(\App\Services\Workflows\WorkflowAmoCrmActionExecutor::class);
        $amo->method('execute')->willReturn(['success' => true, 'output' => ['test' => true]]);
        $this->app->instance(\App\Services\Workflows\WorkflowAmoCrmActionExecutor::class, $amo);
        $test = app(WorkflowTestRunner::class)->test($definition, ['_dry_run' => true]);
        $this->assertTrue($test['success']);
        $this->assertSame(['if', 'yes', 'join'], array_column($test['steps'], 'id'));
    }

    public function test_condition_port_runs_every_connected_branch(): void
    {
        $definition = $this->definition();
        $definition['connections'][] = [
            'sourceId' => 'action:if',
            'sourcePort' => 'yes',
            'targetId' => 'action:orphan',
        ];

        $session = app(WorkflowDebugger::class)->start($definition, [], null, null);

        while ($session['status'] === 'ready') {
            $session = app(WorkflowDebugger::class)->advance($session);
        }

        $this->assertSame('completed', $session['status']);
        $this->assertEqualsCanonicalizing(['if', 'yes', 'join', 'orphan'], array_column($session['results'], 'id'));
        $this->assertCount(4, $session['results']);
    }

    public function test_replacing_an_attached_edge_removes_only_the_old_connection(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)->call('openDetachedActionPalette')->call('selectActionType', 'amocrm_add_note');
        $newId = 'action:'.$page->get('workflowActions')[1]['id'];
        $page->call('connectWorkflowNodes', 'action:condition', 'no', $newId, 'action:note');
        $edges = $page->get('definition')['connections'];
        $this->assertSame([$newId], WorkflowGraph::targets($edges, 'action:condition', 'no'));
        $this->assertSame(['action:task'], WorkflowGraph::targets($edges, 'action:condition', 'yes'));
    }

    public function test_disabled_condition_follows_yes_and_orphans_never_run(): void
    {
        $definition = $this->definition();
        $definition['actions'][0]['disabled'] = true;
        $definition['actions'][0]['config']['conditions'][0]['right'] = 2;
        $runner = app(WorkflowDebugger::class);
        $session = $runner->start($definition, [], null, null);
        while ($session['status'] === 'ready') $session = $runner->advance($session);
        $this->assertSame(['if', 'yes', 'join'], array_column($session['results'], 'id'));
        $this->assertSame('skipped', $session['results'][0]['status']);
        $definition['connections'] = [];
        $this->assertSame('completed', $runner->start($definition, [], null, null)['status']);
        $this->assertFalse(\App\Models\Workflows\Workflow::definitionHasConfiguredActions($definition));
    }

    public function test_unknown_ports_and_cycles_are_rejected(): void
    {
        $definition = $this->definition();
        $definition['connections'][] = ['sourceId' => 'action:if', 'sourcePort' => 'output', 'targetId' => 'action:join'];
        $this->expectException(\InvalidArgumentException::class);
        WorkflowGraph::ordered($definition);
    }

    public function test_production_graph_executor_uses_connections_instead_of_array_order(): void
    {
        $definition = $this->definition();
        $executor = new class(app(\Leek\FilamentWorkflows\Actions\ActionRegistry::class)) extends \App\Workflows\Engine\WorkflowExecutor {
            public array $calls = [];
            public function runGraph($context, $run): void { $this->executeSteps([], $context, $run); }
            protected function executeStep(array $step, \Leek\FilamentWorkflows\Context\WorkflowContext $context, \Leek\FilamentWorkflows\Models\WorkflowRun $run): array {
                $this->calls[] = $step['id'];
                return ['success' => true, 'output' => ['passed' => true]];
            }
        };
        $run = $this->getMockBuilder(\Leek\FilamentWorkflows\Models\WorkflowRun::class)->onlyMethods(['update'])->getMock();
        $run->method('update')->willReturn(true);
        $run->setRelation('workflow', (new \App\Models\Workflows\Workflow)->forceFill(['definition' => $definition]));
        $executor->runGraph(new \App\Workflows\Context\WorkflowContext, $run);
        $this->assertSame(['if', 'yes', 'join'], $executor->calls);
        $definition['connections'][]=['sourceId'=>'action:yes','sourcePort'=>'output','targetId'=>'action:orphan'];
        $run->workflow->definition=$definition; $executor->calls=[];
        $executor->runGraph(new \App\Workflows\Context\WorkflowContext, $run);
        $this->assertEqualsCanonicalizing(['if','yes','join','orphan'],$executor->calls);
        $this->assertCount(4,$executor->calls);
    }

    public static function definition(): array
    {
        $step = fn($id) => ['id' => $id, 'type' => 'amocrm_add_note', 'config' => ['text' => $id]];
        $edge = fn($source, $port, $target) => ['sourceId' => $source, 'sourcePort' => $port, 'targetId' => $target];
        return ['version' => 2, 'trigger' => ['type' => 'manual', 'config' => []], 'actions' => [
            ['id' => 'if', 'type' => 'control-condition', 'config' => ['conditions' => [['left' => 1, 'operator' => 'equals', 'right' => 1]]]],
            $step('yes'), $step('no'), $step('join'), $step('orphan'),
        ], 'connections' => [$edge('trigger', 'output', 'action:if'), $edge('action:if', 'yes', 'action:yes'), $edge('action:if', 'no', 'action:no'), $edge('action:yes', 'output', 'action:join'), $edge('action:no', 'output', 'action:join')]];
    }
}
