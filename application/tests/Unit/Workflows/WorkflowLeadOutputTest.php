<?php

namespace Tests\Unit\Workflows;

use App\Models\Core\Account;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Services\Workflows\WorkflowAmoCrmLoopGuard;
use App\Services\Workflows\WorkflowExecutionGraph;
use App\Services\Workflows\WorkflowExpressionCatalog;
use App\Services\Workflows\WorkflowOutputView;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Support\Facades\Http;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\TestCase;

class WorkflowLeadOutputTest extends TestCase
{
    private function createdLeadOutput(): array
    {
        return ['action' => 'created', 'entity_type' => 'lead', 'entity_id' => 31536785, 'amo_exchange' => [
            ['response' => ['body' => ['_embedded' => ['leads' => [
                ['id' => 31536785, 'request_id' => '0'],
            ]]]]],
        ]];
    }

    public function test_created_lead_is_one_object_without_changing_the_raw_journal(): void
    {
        $output = $this->createdLeadOutput();
        $original = $output;
        $this->assertSame(['id' => 31536785], WorkflowOutputView::value($output));
        $this->assertSame($original, $output);
        unset($output['amo_exchange']);
        $this->assertSame(['id' => 31536785], WorkflowOutputView::value($output));
    }

    public function test_direct_and_saved_singleton_expressions_resolve_the_same_lead(): void
    {
        $context = (new WorkflowContext)->setStepOutput('created', $this->createdLeadOutput())
            ->setVariable('_node_names', ['Создать новую сделку' => ['created']]);
        $this->assertSame(['id' => 31536785], $context->resolve('{{ $("Создать новую сделку") }}'));
        foreach (['.id', '[0].id', '.first.id', '.first().id', '.last().id'] as $path) {
            $this->assertSame(31536785, $context->resolve('{{ $("Создать новую сделку")'.$path.' }}'));
        }
        $this->assertSame(['id' => 31536785], $context->resolve('{{ $("Создать новую сделку")[0] }}'));
        $this->assertNull($context->resolve('{{ $("Создать новую сделку")[1].id }}'));
        $this->assertSame('0', $context->resolve('{{ $node["created"].json.amo_exchange[0].response.body._embedded.leads[0].request_id }}'));
    }

    public function test_a_later_link_response_or_another_lead_cannot_replace_the_created_lead(): void
    {
        $output = $this->createdLeadOutput();
        $output['amo_exchange'][] = ['response' => ['body' => ['_embedded' => ['leads' => [['id' => 42]]]]]];
        $output['amo_exchange'][] = ['response' => ['body' => ['_embedded' => ['links' => [['to_entity_id' => 99]]]]]];
        $this->assertSame(['id' => 31536785], WorkflowOutputView::value($output));
    }

    public function test_picker_offers_id_directly_even_for_previously_saved_execution_data(): void
    {
        WorkflowCanvasDatabase::prepare();
        $actions = [
            ['id' => 'created', 'name' => 'Создать новую сделку', 'type' => 'amocrm_create_lead', 'config' => []],
            ['id' => 'next', 'type' => 'workflow_set_variable', 'config' => []],
        ];
        $sources = WorkflowExpressionCatalog::sources($actions, 'next', ['step_outputs' => ['created' => $this->createdLeadOutput()]]);
        $this->assertSame(['Результат', 'id'], array_column($sources[1]['fields'], 'label'));
        $this->assertSame('{{ $("Создать новую сделку").id }}', $sources[1]['fields'][1]['expression']);
        $this->assertSame(31536785, $sources[1]['fields'][1]['value']);
    }

    public function test_real_read_results_remain_lists_even_with_a_single_lead(): void
    {
        foreach ([[['id' => 1]], [['id' => 1], ['id' => 2]]] as $items) {
            $read = ['data' => ['_embedded' => ['leads' => $items]], 'items' => $items, 'has_more' => false];
            $this->assertSame($items, WorkflowOutputView::value($read));
        }
    }

    public function test_empty_api_bodies_do_not_hide_the_recorded_node_result(): void
    {
        $updated = ['action' => 'updated', 'entity_type' => 'lead', 'entity_id' => 77,
            'amo_exchange' => [['response' => ['code' => 204, 'body' => []]]]];
        $this->assertSame([], WorkflowOutputView::value($updated));
        $this->assertSame(['action' => 'updated', 'entity_type' => 'lead', 'entity_id' => 77], WorkflowOutputView::displayValue($updated));
        $this->assertSame(['status' => 204, 'body' => '', 'success' => true], WorkflowOutputView::displayValue(['status' => 204, 'body' => '', 'success' => true]));
        $this->assertSame([], WorkflowOutputView::displayValue([
            'items' => [], 'count' => 0, 'page' => 1, 'has_more' => false, 'next_page' => null,
            'request' => ['method' => 'GET', 'path' => '/api/v4/leads', 'query' => ['limit' => 10]],
        ]));

        WorkflowCanvasDatabase::prepare();
        $definition = ['trigger' => ['type' => 'manual'], 'actions' => [['id' => 'updated', 'type' => 'amocrm_update_lead_fields', 'config' => []]]];
        $graph = WorkflowExecutionGraph::build($definition, [['id' => 'updated', 'type' => 'amocrm_update_lead_fields', 'status' => 'completed', 'output' => $updated]]);
        $this->assertSame(77, $graph['results'][0]['display_output']['entity_id']);
    }

    public function test_real_creation_path_projects_one_lead_without_an_extra_api_request(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://lead-output.test/api/v4/leads' => Http::response(
            $this->createdLeadOutput()['amo_exchange'][0]['response']['body'], 201,
        )]);
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        (new \ReflectionProperty($executor, 'captureAmoExchange'))->setValue($executor, true);
        $create = new \ReflectionMethod($executor, 'createEntity');
        $client = (new \ReflectionClass($create->getParameters()[0]->getType()->getName()))->newInstanceWithoutConstructor();
        $account = (new Account)->forceFill(['id' => 1, 'user_id' => 1, 'endpoint' => 'https://lead-output.test', 'access_token' => 'test-only']);
        $result = $create->invoke($executor, $client, $account, 'lead', ['name' => 'Test lead', 'contact_id' => 77], null);
        $result = (new \ReflectionMethod($executor, 'withAmoExchange'))->invoke($executor, $result, null);

        $this->assertTrue($result['success']);
        $this->assertSame(['id' => 31536785], WorkflowOutputView::value($result['output']));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request[0]['_embedded']['contacts'] === [['id' => 77]]);
    }

    public function test_execution_history_also_shows_one_object_and_counts_one_entity(): void
    {
        WorkflowCanvasDatabase::prepare();
        $definition = ['trigger' => ['type' => 'manual'], 'actions' => [
            ['id' => 'created', 'name' => 'Создать новую сделку', 'type' => 'amocrm_create_lead', 'config' => []],
        ]];
        $graph = WorkflowExecutionGraph::build($definition, [
            ['id' => 'created', 'type' => 'amocrm_create_lead', 'status' => 'completed', 'output' => $this->createdLeadOutput()],
        ]);
        $this->assertSame(['id' => 31536785], $graph['results'][0]['display_output']);
        $node = collect($graph['nodes'])->firstWhere('id', 'action:created');
        $this->assertSame(1, $node['item_count']);
    }
}
