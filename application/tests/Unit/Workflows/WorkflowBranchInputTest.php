<?php

namespace Tests\Unit\Workflows;

use App\Models\Workflows\Workflow;
use App\Workflows\Context\WorkflowContext;
use App\Workflows\Engine\WorkflowDebugger;
use App\Workflows\Engine\WorkflowExecutor;
use App\Workflows\Engine\WorkflowTestRunner;
use Illuminate\Support\Facades\Http;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Models\WorkflowRun;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\TestCase;

class WorkflowBranchInputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        Http::preventStrayRequests();
    }

    private function definition(): array
    {
        return ['trigger' => ['type' => 'manual'], 'actions' => [
            ['id' => 'a', 'type' => 'test-input', 'config' => ['id' => 111]],
            ['id' => 'b', 'type' => 'test-input', 'config' => ['id' => 222, 'from_trigger' => '{{ $json.id }}']],
            ['id' => 'c', 'type' => 'test-input', 'config' => ['id' => '{{ $json.id }}']],
        ], 'connections' => [
            $this->edge('trigger', 'a'), $this->edge('trigger', 'b'), $this->edge('a', 'c'),
        ]];
    }

    private function edge(string $from, string $to, string $port = 'output'): array
    {
        return ['sourceId' => str_starts_with($from, 'trigger') ? $from : 'action:'.$from, 'sourcePort' => $port, 'targetId' => 'action:'.$to];
    }

    private function registry(): ActionRegistry
    {
        $registry = $this->createMock(ActionRegistry::class);
        $registry->method('has')->willReturn(true);
        $registry->method('resolve')->willReturn(new class {
            public function handle(array $config): array { return ['success' => true, 'output' => $config]; }
        });
        return $registry;
    }

    public function test_runtime_uses_connected_input_not_the_last_executed_sibling(): void
    {
        $executor = new class($this->registry()) extends WorkflowExecutor {
            public array $seen = [];
            protected function executeStep(array $step, \Leek\FilamentWorkflows\Context\WorkflowContext $context, WorkflowRun $run): array
            {
                $this->seen[$step['id']] = $context->resolve($step['config']);
                return ['success' => true, 'output' => $this->seen[$step['id']]];
            }
            public function graph(WorkflowContext $context, WorkflowRun $run): void { $this->executeGraph($context, $run); }
        };
        $run = $this->getMockBuilder(WorkflowRun::class)->onlyMethods(['update'])->getMock();
        $run->method('update')->willReturn(true);
        $run->setRelation('workflow', (new Workflow)->forceFill(['definition' => $this->definition()]));
        $context = new WorkflowContext(['id' => 7]);
        $executor->graph($context, $run);
        $this->assertSame(['a', 'b', 'c'], array_keys($executor->seen));
        $this->assertSame(7, $executor->seen['b']['from_trigger']);
        $this->assertSame(111, $executor->seen['c']['id']);
        $this->assertCount(3, $context->getStepOutputs());
        Http::assertNothingSent();
    }

    public function test_test_runner_and_debugger_use_the_same_branch_inputs(): void
    {
        $result = (new WorkflowTestRunner($this->registry()))->test($this->definition(), ['id' => 7]);
        $this->assertTrue($result['success']);
        $this->assertSame(7, $result['steps'][1]['output']['from_trigger']);
        $this->assertSame(111, $result['steps'][2]['output']['id']);

        $debugger = new WorkflowDebugger($this->registry());
        $session = $debugger->start($this->definition(), ['id' => 7], null, null);
        while ($session['status'] === 'ready') $session = $debugger->advance($session);
        $this->assertSame('completed', $session['status']);
        $this->assertSame(7, $session['results'][1]['output']['from_trigger']);
        $this->assertSame(111, $session['results'][2]['output']['id']);
    }

    public function test_single_node_debugging_uses_real_graph_and_preserves_explicit_references(): void
    {
        $context = (new WorkflowContext(['id' => 7]))->setStepOutput('a', ['id' => 111])->setStepOutput('b', ['id' => 222]);
        $definition = $this->definition();
        $definition['actions'][2]['config']['explicit'] = '{{ $node["b"].json.id }}';
        $result = (new WorkflowDebugger($this->registry()))->executeNode($definition['actions'][2], $context->toArray(), [], null, null, false, $definition);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(['id' => 111, 'explicit' => 222], $result['results'][0]['output']);
    }

    public function test_empty_parent_output_never_falls_back_to_a_sibling(): void
    {
        $context = (new WorkflowContext(['id' => 7]))->setStepOutput('a', [])->setStepOutput('b', ['id' => 222]);
        $context->scopeToNode($this->definition(), 'action:c');
        $this->assertSame([], $context->get('$json'));
        $this->assertNull($context->get('$json.id'));
        $this->assertSame(['a' => []], $context->getStepOutputs());
        $this->assertCount(2, $context->toArray()['step_outputs']);
    }

    public function test_multiple_inputs_require_an_explicit_source_only_when_implicit_input_is_used(): void
    {
        $definition = $this->definition();
        $definition['connections'][] = $this->edge('b', 'c');
        $context = (new WorkflowContext)->setStepOutput('a', ['id' => 111])->setStepOutput('b', ['id' => 222]);
        $context->scopeToNode($definition, 'action:c');
        $this->assertSame(111, $context->get('$node["a"].json.id'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('несколько входов');
        $context->get('$json.id');
    }

    public function test_disabled_nodes_pass_through_input_instead_of_borrowing_a_sibling(): void
    {
        $definition = $this->definition();
        $definition['actions'][0]['disabled'] = true;
        $context = (new WorkflowContext(['id' => 7]))->setStepOutput('b', ['id' => 222]);
        $context->scopeToNode($definition, 'action:c');
        $this->assertSame(7, $context->get('$json.id'));
    }

    public function test_only_taken_condition_output_is_an_input_at_a_join(): void
    {
        $definition = $this->definition();
        $definition['actions'][1] = ['id' => 'b', 'type' => 'control-condition', 'config' => []];
        $definition['connections'][] = $this->edge('b', 'c', 'no');
        $context = (new WorkflowContext)->setStepOutput('a', ['id' => 111])->setStepOutput('b', ['passed' => true]);
        $context->scopeToNode($definition, 'action:c');
        $this->assertSame(111, $context->get('$json.id'));
    }
}
