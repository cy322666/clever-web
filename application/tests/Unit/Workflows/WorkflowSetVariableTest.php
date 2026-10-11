<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\{WorkflowDefinitionValidator, WorkflowExpressionCatalog, WorkflowLoopRunner};
use App\Workflows\Actions\WorkflowSetVariableAction;
use App\Workflows\Context\WorkflowContext;
use App\Workflows\Engine\WorkflowDebugger;
use Illuminate\Support\Facades\Http;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Livewire\Livewire;
use Tests\Support\{WorkflowCanvasDatabase, WorkflowCanvasFixture};
use Tests\TestCase;

class WorkflowSetVariableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        Http::preventStrayRequests();
    }

    private function step(string $id, string $type, array $config = []): array
    {
        return compact('id', 'type', 'config') + ['name' => $id];
    }

    private function edge(string $from, string $to, string $port = 'output'): array
    {
        return ['sourceId' => $from === 'trigger' ? $from : 'action:'.$from, 'sourcePort' => $port, 'targetId' => 'action:'.$to];
    }

    private function assignment(string $id, mixed $value): array
    {
        return $this->step($id, 'workflow_set_variable', ['assignments' => [['name' => 'contact_id', 'value' => $value]]]);
    }

    public function test_assignments_keep_types_and_do_not_overwrite_internal_flags(): void
    {
        $context = (new WorkflowContext)->setVariable('_dry_run', true);
        $values = ['contact_id' => 123, 'empty' => null, 'enabled' => false, 'zero' => 0, 'text' => '001', 'rows' => [['id' => 7]]];
        $rows = [];
        foreach ($values as $name => $value) $rows[] = compact('name', 'value');
        $result = (new WorkflowSetVariableAction)->handle(['assignments' => $rows], $context);
        $this->assertTrue($result['success']);
        $this->assertSame($values, $result['output']);
        foreach ($values as $name => $value) $this->assertSame($value, $context->resolve('{{ $vars.'.$name.' }}'));
        $this->assertSame(7, $context->resolve('{{ $vars.rows.first().id }}'));
        $this->assertSame('ID 123', $context->resolve('ID {{ $vars.contact_id }}'));
        $this->assertSame(123, WorkflowContext::fromArray($context->toArray())->resolve('{{ $vars.contact_id }}'));
        foreach (['_dry_run', 'a.b', 'a[0]', '', str_repeat('x', 65)] as $name) {
            $config = ['assignments' => [['name' => $name, 'value' => 10]]];
            $this->assertNotEmpty(WorkflowDefinitionValidator::configIssues('workflow_set_variable', $config));
            $this->assertFalse((new WorkflowSetVariableAction)->handle($config, $context)['success']);
        }
        $this->assertTrue($context->getVariable('_dry_run'));
        $this->assertFalse((new WorkflowSetVariableAction)->handle(['assignments' => [
            ['name' => 'contact_id', 'value' => 99], ['name' => 'contact_id', 'value' => 100],
        ]], $context)['success']);
        $this->assertSame(123, $context->get('$vars.contact_id'));
    }

    private function branchedLoop(): array
    {
        $condition = fn ($id, $value) => $this->step($id, 'control-condition', ['conditions' => [['left' => '{{ $("loop").id }}', 'operator' => 'equals', 'right' => $value]]]);
        return ['trigger' => ['type' => 'manual'], 'actions' => [
            $this->step('loop', 'workflow_loop', ['items' => [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]]]),
            $condition('a', 1), $condition('b', 2), $condition('c', 3),
            $this->assignment('set_a', 101), $this->assignment('set_b', 202), $this->assignment('set_c', 303),
            $this->step('join', 'workflow_set_variable', ['assignments' => [['name' => 'seen', 'value' => '{{ $vars.contact_id }}']]]),
        ], 'connections' => [
            $this->edge('trigger', 'loop'), $this->edge('loop', 'a', 'each'),
            $this->edge('a', 'set_a', 'yes'), $this->edge('a', 'b', 'no'),
            $this->edge('b', 'set_b', 'yes'), $this->edge('b', 'c', 'no'),
            $this->edge('c', 'set_c', 'yes'), $this->edge('c', 'join', 'no'),
            $this->edge('set_a', 'join'), $this->edge('set_b', 'join'), $this->edge('set_c', 'join'),
            $this->edge('join', 'loop'),
        ]];
    }

    public function test_three_branches_use_one_variable_without_leaking_to_next_item_or_run(): void
    {
        $definition = $this->branchedLoop();
        $this->assertSame([], WorkflowDefinitionValidator::issues($definition));
        $runner = app(WorkflowDebugger::class);
        $session = $runner->advance($runner->start($definition, [], null, null));
        $this->assertSame('completed', $session['status'], $session['error'] ?? '');
        $joins = array_values(array_filter($session['results'], fn ($r) => $r['id'] === 'join'));
        $this->assertSame([101, 202, 303, null], array_column(array_column($joins, 'output'), 'seen'));
        $this->assertNull(WorkflowContext::fromArray($session['context'])->get('$vars.contact_id'));
        $this->assertNull((new WorkflowContext)->get('$vars.contact_id'));
        // Preview reused after another node run must not inherit its old item value.
        $context = WorkflowContext::fromArray($session['context'])->setVariable('_workflow_values', ['contact_id' => 999]);
        $definition['actions'][0]['config']['items'] = [['id' => 4]];
        $preview = $runner->executeNode($definition['actions'][0], $context->toArray(), [], null, null, false, $definition);
        $last = end($preview['results']);
        $this->assertSame('completed', $preview['status'], $preview['error'] ?? '');
        $this->assertNull($last['output']['seen']);
        Http::assertNothingSent();
    }

    public function test_replay_reapplies_saved_assignments_without_repeating_completed_actions(): void
    {
        \Tests\Support\WorkflowListDatabase::prepare();
        (require database_path('migrations/2026_06_09_160734_create_workflow_run_steps_table.php'))->up();
        $definition = $this->branchedLoop();
        $run = $this->getMockBuilder(\Leek\FilamentWorkflows\Models\WorkflowRun::class)->onlyMethods(['update'])->getMock();
        $run->id = 20;
        $run->method('update')->willReturn(true);
        $run->setRelation('workflow', (new \App\Models\Workflows\Workflow)->forceFill(['definition' => $definition]));
        $executor = new class(app(ActionRegistry::class)) extends \App\Workflows\Engine\WorkflowExecutor {
            public int $executions = 0;
            public function graph($context, $run): void { $this->executeGraph($context, $run); }
            protected function runStep(array $step, \Leek\FilamentWorkflows\Context\WorkflowContext $context): array {
                $this->executions++;
                return parent::runStep($step, $context);
            }
        };
        $context = (new WorkflowContext)->setVariable('_node_names', WorkflowExpressionCatalog::nodeNames($definition['actions']));
        $executor->graph($context, $run);
        $count = $executor->executions;
        // Simulate resuming before the final join: all earlier assignments are reused.
        $finalId = WorkflowLoopRunner::executionId('join', [['node' => 'loop', 'index' => 2]]);
        $run->steps()->where('step_id', $finalId)->delete();
        $executor->graph($context, $run);
        $this->assertSame($count + 1, $executor->executions);
        $this->assertSame(['seen' => 303], $run->steps()->where('step_id', $finalId)->first()->output_data);
        Http::assertNothingSent();
    }

    public function test_editor_and_picker_offer_assignments_and_persist_their_values(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => []])->call('selectActionType', 'workflow_set_variable');
        $node = $page->get('workflowActions')[0];
        $page->assertDispatched('workflow-node-created', nodeId: 'action:'.$node['id']);
        $page->call('openWorkflowActionEditor', $node['id'])
            ->set('mountedActions.0.data.assignments', [['name' => 'contact_id', 'value' => '{{ $json.id }}']])
            ->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame('contact_id', $page->get('workflowActions')[0]['config']['assignments'][0]['name']);
        $actions = [$this->assignment('set', 123), $this->step('next', 'workflow_delay', ['seconds' => 1])];
        $sources = WorkflowExpressionCatalog::sources($actions, 'next', ['variables' => ['_workflow_values' => ['contact_id' => 123]]]);
        $variables = collect($sources)->firstWhere('id', '$vars');
        $this->assertContains('{{ $vars.contact_id }}', array_column($variables['fields'], 'expression'));
        $this->assertSame(123, $variables['fields'][1]['value']);
    }
}
