<?php

namespace Tests\Unit\Workflows;

use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowDefinitionValidator;
use App\Services\Workflows\WorkflowExecutionGraph;
use App\Workflows\Engine\WorkflowExecutor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use Leek\FilamentWorkflows\Exceptions\NonRetryableWorkflowException;
use Leek\FilamentWorkflows\Models\WorkflowRun;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        Http::preventStrayRequests();
        Schema::create('workflow_run_steps', function (Blueprint $t) {
            $t->id(); $t->integer('workflow_run_id'); $t->string('step_id'); $t->string('step_type');
            $t->string('action_type')->nullable(); $t->string('status'); $t->integer('attempt_number');
            $t->text('input_data')->nullable(); $t->text('output_data')->nullable(); $t->text('error_message')->nullable();
            $t->timestamp('started_at')->nullable(); $t->timestamp('completed_at')->nullable(); $t->integer('duration_ms')->nullable(); $t->timestamps();
        });
    }

    private function definition(array $actions): array
    {
        return ['trigger' => ['type' => 'manual'], 'actions' => $actions];
    }

    public function test_static_activation_validation_checks_fields_references_and_connections_without_api_calls(): void
    {
        $definition = $this->definition([
            ['id' => 'note', 'type' => 'amocrm_add_note', 'config' => ['text' => 'Тест']],
            ['id' => 'bot', 'type' => 'amocrm_start_salesbot', 'config' => ['bot_id' => '']],
            ['id' => 'request', 'type' => 'http_request', 'config' => ['url' => 'invalid', 'body' => '{']],
        ]);
        $issues = implode("\n", WorkflowDefinitionValidator::issues($definition));
        $this->assertStringContainsString('SalesBot', $issues);
        $this->assertStringContainsString('HTTP(S)', $issues);
        $this->assertStringContainsString('JSON', $issues);
        $definition['actions'] = [$definition['actions'][0]];
        $definition['actions'][0]['config']['text'] = '{{ $node["missing"].json.id }}';
        $this->assertStringContainsString('отсутствующую ноду', implode('', WorkflowDefinitionValidator::issues($definition)));
        $definition['actions'][0]['config']['text'] = '{{ $node["Запуск вручную"].json.id }}';
        $names = \App\Services\Workflows\WorkflowExpressionCatalog::referenceNames($definition['actions'], $definition);
        $definition['actions'][0]['config']['text'] = '{{ $node["'.$names['trigger'].'"].json.id }}';
        $this->assertSame([], WorkflowDefinitionValidator::issues($definition));
        $definition['connections'] = [['sourceId' => 'action:note', 'sourcePort' => 'output', 'targetId' => 'action:note']];
        $this->assertNotEmpty(WorkflowDefinitionValidator::issues($definition));
        Http::assertNothingSent();
    }

    public function test_invalid_workflow_cannot_be_activated_through_model_but_can_be_saved_as_draft(): void
    {
        $flow = (new Workflow)->forceFill(['user_id' => 2, 'name' => 'Draft', 'is_active' => false,
            'definition' => $this->definition([['id' => 'bot', 'type' => 'amocrm_start_salesbot', 'config' => []]])]);
        $flow->save();
        try { $flow->is_active = true; $flow->save(); $this->fail('Invalid workflow activated'); }
        catch (\Illuminate\Validation\ValidationException $error) { $this->assertStringContainsString('SalesBot', $error->getMessage()); }
        $this->assertFalse($flow->fresh()->is_active);
    }

    public function test_failed_node_is_terminal_and_keeps_its_http_body(): void
    {
        $run = $this->getMockBuilder(WorkflowRun::class)->onlyMethods(['update'])->getMock();
        $run->id = 20; $run->method('update')->willReturn(true);
        $run->setRelation('workflow', (new Workflow)->forceFill(['failure_strategy' => 'stop']));
        $executor = new class(app(ActionRegistry::class)) extends WorkflowExecutor {
            public array $calls = [];
            protected function runStep(array $step, WorkflowContext $context): array
            {
                $this->calls[] = $step['id'];
                $context->setVariable('_resolved_inputs.'.$step['id'], ['name' => 'resolved '.count($this->calls)]);
                return $step['id'] === 'note' ? ['success' => true, 'output' => ['entity_id' => 77]]
                    : ['success' => false, 'error' => 'HTTP 503', 'output' => ['amo_exchange' => [['request' => ['body' => ['name' => 'Contact']], 'response' => ['code' => 503]]]]];
            }
            public function one(string $id, WorkflowRun $run, WorkflowContext $context): array
            { return $this->executeStep(['id' => $id, 'type' => 'amocrm_add_note', 'config' => ['text' => 'Test']], $context, $run); }
        };
        $context = new WorkflowContext;
        $executor->one('note', $run, $context);
        try { $executor->one('contact', $run, $context); $this->fail('Every node error must be terminal.'); }
        catch (NonRetryableWorkflowException $error) { $this->assertSame('HTTP 503', $error->getMessage()); }
        $this->assertTrue($executor->one('note', $run, new WorkflowContext)['reused']);
        $this->assertSame(['note', 'contact'], $executor->calls);
        $steps = $run->steps()->orderBy('id')->get();
        $this->assertSame([1, 1], $steps->pluck('attempt_number')->all());
        $this->assertSame(503, $steps[1]->output_data['amo_exchange'][0]['response']['code']);
        $this->assertSame('resolved 2', $steps[1]->input_data['_resolved_input']['name']);
        $this->assertSame(['entity_id' => 77], $steps[0]->output_data);
    }

    public function test_runtime_preflight_fails_before_any_action_even_if_a_later_node_is_invalid(): void
    {
        $executor = new class(app(ActionRegistry::class)) extends WorkflowExecutor {
            public int $calls = 0;
            protected function runStep(array $step, WorkflowContext $context): array { $this->calls++; return ['success' => true]; }
            public function all(WorkflowRun $run): void { $this->executeSteps($run->workflow->definition['actions'], new WorkflowContext, $run); }
        };
        $run = new WorkflowRun;
        $run->setRelation('workflow', (new Workflow)->forceFill(['definition' => $this->definition([
            ['id' => 'note', 'type' => 'amocrm_add_note', 'config' => ['text' => 'Test']],
            ['id' => 'bot', 'type' => 'amocrm_start_salesbot', 'config' => []],
        ])]));
        try { $executor->all($run); $this->fail('Must stop before the first mutation'); }
        catch (NonRetryableWorkflowException) { $this->assertSame(0, $executor->calls); }
        Http::assertNothingSent();
    }

    public function test_history_distinguishes_repeated_executions_from_output_item_count(): void
    {
        $graph = WorkflowExecutionGraph::build($this->definition([['id' => 'note', 'type' => 'amocrm_add_note', 'config' => []]]), [
            ['id' => 'note', 'type' => 'amocrm_add_note', 'status' => 'completed', 'output' => ['entity_id' => 11]],
            ['id' => 'note', 'type' => 'amocrm_add_note', 'status' => 'completed', 'output' => ['entity_id' => 12]],
            ['id' => 'note', 'type' => 'amocrm_add_note', 'status' => 'completed', 'output' => ['entity_id' => 13]],
        ]);
        $this->assertSame(1, $graph['nodes'][1]['item_count']);
        $this->assertSame(3, $graph['nodes'][1]['execution_count']);
        $this->assertSame([1, 2, 3], array_column($graph['results'], 'occurrence'));
    }

    public function test_editor_rename_saves_the_current_schema_without_enabling_it(): void
    {
        $this->actingAs(User::findOrFail(2));
        $flow = (new Workflow)->forceFill(['user_id' => 2, 'name' => 'Before', 'is_active' => false,
            'definition' => $this->definition([['id' => 'delay', 'type' => 'workflow_delay', 'config' => ['seconds' => 1]]])]);
        $flow->save();
        $page = new \App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\EditWorkflow;
        $page->record = $flow; $page->data = ['is_active' => false];
        $page->definition = $flow->definition; $page->trigger = $flow->definition['trigger'];
        $page->definition['actions'][0]['config']['seconds'] = 10;
        $page->workflowActions = $page->definition['actions'];
        $page->renameWorkflow('After');
        $this->assertSame('After', $flow->fresh()->name);
        $this->assertSame(10, $flow->fresh()->definition['actions'][0]['config']['seconds']);
        $this->assertFalse($flow->fresh()->is_active);
    }
}
