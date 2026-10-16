<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowExpressionCatalog;
use App\Workflows\Actions\WorkflowFilterListAction;
use App\Workflows\Context\WorkflowContext;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\TestCase;

class WorkflowCollectionSuggestionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
    }

    public function test_real_read_collection_keeps_body_keys_and_nested_lists_in_compact_picker_metadata(): void
    {
        $actions = [
            ['id' => 'fetch', 'type' => 'amocrm_read', 'name' => 'Получить сделки'],
            ['id' => 'filter', 'type' => 'workflow_filter_list', 'name' => 'Фильтр списка'],
        ];
        $items = [
            ['id' => 1, 'name' => 'Первая', 'status_id' => 142, 'tags' => [['id' => 7]], 'contact' => ['name' => 'Иван']],
            ['id' => 2, 'name' => 'Вторая', 'status_id' => 100, 'tags' => []],
        ];
        $output = ['data' => $items, 'items' => $items, 'count' => 2, 'has_more' => false];
        $context = (new WorkflowContext)->setStepOutput('fetch', $output)
            ->setVariable('_node_names', ['Получить сделки' => ['fetch']]);
        $sources = WorkflowExpressionCatalog::forPicker(WorkflowExpressionCatalog::sources($actions, 'filter', $context->toArray()));
        $source = $sources[1];
        $this->assertTrue($source['available']);
        $this->assertCount(1, $source['fields']);
        $this->assertNull($source['fields'][0]['value'], 'Do not copy a full collection into each suggestion.');
        $this->assertSame(2, $source['fields'][0]['count']);
        $this->assertSame(['id', 'name', 'status_id', 'tags', 'contact', 'contact.name'], $source['fields'][0]['item_fields']);
        $this->assertSame($items, $context->resolve($source['fields'][0]['expression']));
        $this->assertSame('Первая', $context->resolve('{{ $("Получить сделки").first().name }}'));
        $this->assertSame('Вторая', $context->resolve('{{ $("Получить сделки").last().name }}'));
        $this->assertSame([['id' => 7]], $context->resolve('{{ $("Получить сделки").first().tags }}'));
        $filtered = (new WorkflowFilterListAction)->handle([
            'items' => $source['fields'][0]['expression'], 'match' => 'all',
            'rules' => [['field' => 'status_id', 'operator' => 'eq', 'value' => 142]],
        ], $context);
        $this->assertTrue($filtered['success']);
        $this->assertSame([$items[0]], $filtered['output']['items']);
    }

    public function test_http_nested_collection_metadata_is_bounded_and_does_not_leak_secrets(): void
    {
        $items = array_map(fn ($id) => ['id' => $id, 'client_name' => 'Тест', 'tags' => [],
            'token' => 'never-ship-token', 'credentials' => ['password' => 'never-ship-password']], range(1, 1000));
        $actions = [['id' => 'http', 'type' => 'http_request', 'name' => 'HTTP'], ['id' => 'filter', 'type' => 'workflow_filter_list']];
        $context = (new WorkflowContext)->setStepOutput('http', ['status' => 200, 'success' => true, 'body' => ['result' => $items]])
            ->setVariable('_node_names', ['HTTP' => ['http']]);
        $sources = WorkflowExpressionCatalog::forPicker(WorkflowExpressionCatalog::sources($actions, 'filter', $context->toArray()));
        $list = collect($sources[1]['fields'])->firstWhere('key', '.result');
        $this->assertContains('client_name', $list['item_fields']);
        $this->assertContains('tags', $list['item_fields']);
        $this->assertNotContains('token', $list['item_fields']);
        $this->assertNotContains('credentials.password', $list['item_fields']);
        $this->assertStringNotContainsString('never-ship', json_encode($sources));
        $this->assertLessThan(5000, strlen(json_encode($sources)));
        $this->assertSame('Тест', $context->resolve('{{ $("HTTP").result.first().client_name }}'));
    }

    public function test_empty_collection_never_invents_body_fields(): void
    {
        $actions = [['id' => 'fetch', 'type' => 'amocrm_read'], ['id' => 'filter', 'type' => 'workflow_filter_list']];
        $source = WorkflowExpressionCatalog::sources($actions, 'filter', ['step_outputs' => [
            'fetch' => ['data' => [], 'items' => [], 'count' => 0, 'has_more' => false],
        ]])[1];
        $this->assertTrue($source['available']);
        $this->assertSame([], $source['fields'][0]['item_fields']);
        $this->assertSame(0, $source['fields'][0]['count']);
    }
}
