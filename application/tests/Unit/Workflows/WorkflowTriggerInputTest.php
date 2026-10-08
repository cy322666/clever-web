<?php

namespace Tests\Unit\Workflows;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasWorkflowDebugger;
use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowExpressionCatalog;
use App\Services\Workflows\WorkflowGenericWebhookService;
use App\Services\Workflows\WorkflowOutputView;
use App\Workflows\Context\WorkflowContext;
use App\Workflows\Engine\WorkflowDebugger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowTriggerInputTest extends TestCase
{
    private array $item = ['id' => '24974053', 'pipeline_id' => '2205106', 'status_id' => '143', 'price' => '0'];

    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        Schema::table('workflow_runs', function (Blueprint $table): void {
            $table->json('context_data')->nullable();
            $table->string('trigger_source')->default('webhook');
        });
        $this->actingAs(User::findOrFail(1));
        Http::preventStrayRequests();
    }

    private function definition(): array
    {
        return ['trigger' => ['type' => 'amocrm-status-lead', 'name' => 'Событие'],
            'additional_triggers' => [['id' => 'trigger:hook', 'type' => 'generic-webhook', 'name' => 'Вебхук', 'config' => []]], 'actions' => []];
    }

    private function payload(): array
    {
        return ['account' => ['id' => '1', 'subdomain' => 'example'], 'leads' => ['status' => [$this->item]]];
    }

    private function normalized(): array
    {
        return ['_workflow_start_node_id' => 'trigger', 'source' => 'amocrm', 'event' => 'status_lead', 'entity' => 'lead', 'item' => $this->item, 'payload' => $this->payload()];
    }

    private function page(): TriggerInputPage
    {
        $record = Workflow::withoutEvents(fn () => Workflow::forceCreate(['user_id' => 1, 'name' => 'Test', 'trigger_type' => 'webhook', 'is_active' => false, 'definition' => $this->definition()]));
        $page = new TriggerInputPage;
        $page->record = $record;
        $page->definition = $this->definition();
        return $page;
    }

    private function history(Workflow $workflow, array $input, int $userId = 1): void
    {
        DB::table('workflow_runs')->insert(['workflow_id' => $workflow->id, 'user_id' => $userId, 'status' => 'completed',
            'context_data' => json_encode(['trigger_data' => $input]), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_short_fields_and_all_old_paths_resolve_without_mutating_raw_data(): void
    {
        $input = $this->normalized();
        $context = (new WorkflowContext($input))->setVariable('_node_names', ['Событие' => ['trigger']]);
        foreach (['id', 'pipeline_id', 'status_id', 'price'] as $field) {
            $this->assertSame($this->item[$field], $context->resolve('{{ $("Событие").'.$field.' }}'));
            $this->assertSame($this->item[$field], $context->resolve('{{ $("Событие").leads.status[0].'.$field.' }}'));
            $this->assertSame($this->item[$field], $context->resolve('{{ $("Событие").payload.leads.status[0].'.$field.' }}'));
            $this->assertSame($this->item[$field], $context->resolve('{{ $node["Событие"].json.item.'.$field.' }}'));
        }
        $fields = WorkflowExpressionCatalog::sources([], null, $context->toArray(), null, $this->definition())[0]['fields'];
        $this->assertSame(['Результат', 'id', 'pipeline_id', 'status_id', 'price'], array_column($fields, 'label'));
        foreach ($fields as $field) $this->assertSame($field['value'], $context->resolve($field['expression']));
        $this->assertSame($input, $context->getTriggerData());
    }

    public function test_raw_amo_webhook_is_unwrapped_but_batches_and_custom_data_are_preserved(): void
    {
        $body = $this->payload();
        $this->assertSame($this->item, WorkflowOutputView::trigger($body));
        $this->assertSame($this->item, WorkflowOutputView::trigger(['body' => $body, 'method' => 'POST']));
        $body['leads']['status'][] = ['id' => '999'];
        $this->assertSame([$this->item, ['id' => '999']], WorkflowOutputView::trigger($body));
        $custom = ['items' => [['id' => 1]], 'meta' => false];
        $this->assertSame($custom, WorkflowOutputView::trigger(['body' => $custom]));
    }

    public function test_get_deal_by_id_builds_the_expected_request_from_the_short_field(): void
    {
        $context = (new WorkflowContext($this->normalized()))->setVariable('_node_names', ['Событие' => ['trigger']]);
        $config = $context->resolve(['operation' => 'leads.one', 'id' => '{{ $("Событие").id }}', 'parameters' => [['name' => 'with', 'value' => 'contacts']]]);
        $request = \App\Services\Workflows\WorkflowAmoReadCatalog::build($config);
        $this->assertSame('/api/v4/leads/24974053', $request['path']);
        $this->assertSame('contacts', $request['query']['with']);
        Http::assertNothingSent();
    }

    public function test_invalid_json_does_not_crash_the_picker_but_blocks_running(): void
    {
        $page = $this->page();
        $page->debugInput = '{';
        $this->assertFalse($page->getWorkflowExpressionSources()[0]['available']);
        $this->expectException(\JsonException::class);
        $page->runInput();
    }

    public function test_amo_start_never_uses_the_other_webhook_preview_or_other_start_history(): void
    {
        $page = $this->page();
        $this->history($page->record, $this->normalized());
        $this->history($page->record, ['_workflow_start_node_id' => 'trigger:hook', 'body' => ['id' => 999]]);
        $service = $this->createMock(WorkflowGenericWebhookService::class);
        $service->expects($this->never())->method('latestPreview');
        $this->app->instance(WorkflowGenericWebhookService::class, $service);
        $source = $page->getWorkflowExpressionSources()[0];
        $this->assertSame('Событие', $source['name']);
        $this->assertSame($this->item, $source['fields'][0]['value']);
        $this->assertSame($this->normalized(), $page->runInput());
    }

    public function test_selected_webhook_uses_the_same_preview_for_picker_and_execution(): void
    {
        $page = $this->page();
        $page->debugStartNodeId = 'trigger:hook';
        $service = $this->createMock(WorkflowGenericWebhookService::class);
        $service->method('latestPreview')->willReturn(['payload' => ['id' => 999], 'query' => [], 'headers' => [], 'method' => 'POST', 'received_at' => 'today']);
        $this->app->instance(WorkflowGenericWebhookService::class, $service);
        $source = $page->getWorkflowExpressionSources()[0];
        $input = $page->runInput();
        $this->assertSame('Вебхук', $source['name']);
        $this->assertSame('trigger:hook', $input['_workflow_start_node_id']);
        $context = (new WorkflowContext($input))->setVariable('_node_names', WorkflowExpressionCatalog::nodeNames([], $page->definition));
        $this->assertSame(999, $context->resolve($source['fields'][1]['expression']));
        $step = ['id' => 'if', 'type' => 'control-condition', 'config' => ['conditions' => [['left' => '{{ $("Вебхук").id }}', 'operator' => 'equals', 'right' => 999]]]];
        $definition = $page->definition + ['connections' => [['sourceId' => 'trigger:hook', 'sourcePort' => 'output', 'targetId' => 'action:if']]];
        $definition['actions'] = [$step];
        $session = app(WorkflowDebugger::class)->executeNode($step, [], $input, null, null, false, $definition);
        $this->assertSame('completed', $session['status']);
        $this->assertTrue($session['results'][0]['output']['passed']);
        $this->assertNull($context->resolve('{{ $("Событие").id }}'));
        Http::assertNothingSent();
    }

    public function test_cached_execution_context_and_explicit_input_are_shared_by_the_picker(): void
    {
        $page = $this->page();
        $page->debugInput = json_encode($this->normalized());
        $this->assertSame($this->item, $page->getWorkflowExpressionSources()[0]['fields'][0]['value']);
        $this->assertSame($this->normalized(), $page->runInput());
        $input = $this->normalized();
        $input['item']['id'] = '123';
        $page->cached = ['trigger_data' => $input];
        $this->assertSame('123', $page->getWorkflowExpressionSources()[0]['fields'][1]['value']);
        $this->assertSame($input, $page->runInput());
    }

    public function test_history_of_another_owner_is_not_used(): void
    {
        $page = $this->page();
        $this->history($page->record, $this->normalized(), 2);
        $this->assertSame(['_workflow_start_node_id' => 'trigger'], $page->runInput());
    }

    public function test_generic_webhook_history_does_not_fall_back_to_an_amocrm_event(): void
    {
        $page = $this->page();
        $this->history($page->record, $this->normalized());
        $this->assertNull(app(WorkflowGenericWebhookService::class)->latestPreview($page->record));
    }
}

class TriggerInputPage
{
    use HasWorkflowDebugger;
    public Workflow $record;
    public array $definition = [];
    public array $workflowActions = [];
    public array $mountedActions = [];
    public ?string $editingActionId = null;
    public array $cached = [];
    public function getRecord(): Workflow { return $this->record; }
    protected function workflowDebugContext(): array { return $this->cached; }
    public function runInput(): array { return $this->workflowNodeRunInput(); }
}
