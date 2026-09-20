<?php

namespace Tests\Unit\Workflows;

use App\Models\Core\Account;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Services\Workflows\WorkflowAmoReadCatalog;
use Illuminate\Support\Facades\Http;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\TestCase;

class WorkflowReadCatalogTest extends TestCase
{
    public function test_new_query_catalog_excludes_removed_sections_and_field_groups(): void
    {
        $available = WorkflowAmoReadCatalog::availableOperations();
        $this->assertSame(['Сделки', 'Контакты', 'Компании', 'Покупатели', 'Задачи', 'Примечания', 'Теги', 'Списки и товары', 'События', 'Другое'], array_keys(WorkflowAmoReadCatalog::options()));
        foreach ($available as $item) {
            $this->assertNotContains($item['group'], ['Неразобранное', 'Воронки', 'Аккаунт', 'Источники', 'Беседы']);
            $this->assertStringNotContainsString('/custom_fields/groups', $item['path']);
        }
        $this->assertArrayHasKey('contacts.custom_fields', $available);
        $this->assertArrayNotHasKey('contacts.custom_fields.one', $available);
        $this->assertSame([
            'contacts.custom_fields' => 'Список',
            'contacts.custom_fields.one' => 'По ID',
        ], WorkflowAmoReadCatalog::variantOptions('contacts.custom_fields'));
        $this->assertArrayHasKey('contacts.list', $available);
        $this->assertArrayNotHasKey('contacts.one', $available);
        $this->assertSame(['contacts.list' => 'Список', 'contacts.one' => 'По ID'], WorkflowAmoReadCatalog::variantOptions('contacts.one'));
        $this->assertArrayHasKey('contacts.one', WorkflowAmoReadCatalog::operations());
    }

    public function test_notes_and_tags_have_dedicated_sections_and_keep_entity_context(): void
    {
        $available = WorkflowAmoReadCatalog::availableOperations();
        $options = WorkflowAmoReadCatalog::options();
        foreach (['leads'=>'Сделки', 'contacts'=>'Контакты', 'companies'=>'Компании', 'customers'=>'Покупатели'] as $entity => $label) {
            $notes = $entity.'.notes.all';
            $this->assertSame('Примечания', $available[$notes]['group']);
            $this->assertSame($label, $available[$notes]['entity_group']);
            $this->assertSame([
                $entity.'.notes.all' => 'Все примечания',
                $entity.'.notes.one' => 'По ID примечания',
                $entity.'.notes' => 'Примечания сущности',
                $entity.'.notes.entity.one' => 'По сущности и ID примечания',
            ], WorkflowAmoReadCatalog::variantOptions($entity.'.notes.entity.one'));
            $this->assertArrayHasKey($notes, $options['Примечания']);
            $this->assertArrayNotHasKey($entity.'.notes.one', $available);
            $tags = $entity.'.tags';
            $this->assertSame('Теги', $available[$tags]['group']);
            $this->assertSame($label, $available[$tags]['entity_group']);
        }
        $this->assertCount(4, $options['Примечания']);
        $this->assertCount(4, $options['Теги']);
    }

    public function test_removed_queries_cannot_be_added_but_saved_queries_remain_editable(): void
    {
        $page = \Livewire\Livewire::test(\Tests\Support\WorkflowCanvasFixture::class);
        $original = $page->get('workflowActions');
        $selectable = [];
        foreach (WorkflowAmoReadCatalog::availableOperations() as $item) $selectable += array_fill_keys(array_keys($item['variants']), true);
        foreach (array_diff_key(WorkflowAmoReadCatalog::operations(), $selectable) as $key => $item) {
            $page->call('selectReadOperation', $key)->assertSet('workflowActions', $original);
        }

        $config = ['operation'=>'contacts.custom_fields.groups.one', 'id'=>42, 'body_mode'=>'fields', 'parameters'=>[]];
        $page = \Livewire\Livewire::test(\Tests\Support\WorkflowCanvasFixture::class, ['workflowActions'=>[
            ['id'=>'legacy','type'=>'amocrm_read','config'=>$config],
        ]])->call('openWorkflowActionEditor','legacy')->assertSet('mountedActions.0.data.id',42)
            ->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame('/api/v4/contacts/custom_fields/groups/42', WorkflowAmoReadCatalog::build($page->get('workflowActions')[0]['config'])['path']);
        Http::assertNothingSent();
    }

    public function test_regrouped_queries_can_be_added_with_unambiguous_node_names(): void
    {
        foreach (['contacts.tags'=>'Контакты · Теги', 'leads.notes.all'=>'Сделки · Примечания'] as $key => $name) {
            $page = \Livewire\Livewire::test(\Tests\Support\WorkflowCanvasFixture::class)
                ->call('openDetachedActionPalette')->call('selectReadOperation', $key);
            $nodes = \App\Services\Workflows\WorkflowGraph::nodes($page->get('workflowActions'));
            $action = end($nodes)['step'];
            $this->assertSame('amocrm_read', $action['type']);
            $this->assertSame($key, $action['config']['operation']);
            $this->assertSame($name, $action['name']);
        }
        Http::assertNothingSent();
    }

    public function test_customer_transactions_and_all_list_id_pairs_are_single_catalog_items(): void
    {
        $available = WorkflowAmoReadCatalog::availableOperations();
        $this->assertSame([
            'transactions.list' => 'Все транзакции',
            'transactions.one' => 'Транзакция по ID',
            'customer_transactions.list' => 'Транзакции покупателя',
            'customer_transactions.one' => 'Транзакция покупателя по ID',
        ], WorkflowAmoReadCatalog::variantOptions('customer_transactions.one'));
        $this->assertArrayHasKey('transactions.list', $available);
        foreach (['transactions.one', 'customer_transactions.list', 'customer_transactions.one'] as $duplicate) {
            $this->assertArrayNotHasKey($duplicate, $available);
        }

        foreach ($available as $key => $item) {
            foreach (array_keys($item['variants']) as $variant) {
                if ($variant === $key) continue;
                $this->assertArrayNotHasKey($variant, $available);
            }
        }
    }

    public function test_combined_entity_node_switches_from_list_to_id_inside_the_editor(): void
    {
        $page = \Livewire\Livewire::test(\Tests\Support\WorkflowCanvasFixture::class)
            ->call('openDetachedActionPalette', 'query')
            ->call('selectReadOperation', 'contacts.list');
        $nodes = \App\Services\Workflows\WorkflowGraph::nodes($page->get('workflowActions'));
        $action = end($nodes)['step'];

        $page->call('openWorkflowActionEditor', $action['id'])
            ->assertSet('mountedActions.0.data.operation', 'contacts.list')
            ->assertSet('mountedActions.0.data.operation_variant', 'contacts.list')
            ->set('mountedActions.0.data.operation_variant', 'contacts.one')
            ->set('mountedActions.0.data.id', '42')
            ->call('callMountedAction')
            ->assertHasNoErrors();

        $config = \App\Services\Workflows\WorkflowGraph::nodes($page->get('workflowActions'))['action:'.$action['id']]['step']['config'];
        $this->assertSame('contacts.one', $config['operation']);
        $this->assertSame('/api/v4/contacts/42', WorkflowAmoReadCatalog::build($config)['path']);
        $this->assertArrayNotHasKey('operation_variant', $config);
    }

    public function test_legacy_json_opens_as_parameters_with_an_identical_query_string(): void
    {
        $original = ['operation'=>'tasks.list', 'body_mode'=>'json', 'json_body'=>json_encode([
            'filter'=>['entity_type'=>'leads','entity_id'=>[42,43],'is_completed'=>false],
            'limit'=>50, 'empty'=>[], 'unset'=>null, 'variable'=>'{{lead.id}}',
        ])];
        $converted = WorkflowAmoReadCatalog::editorConfig($original);
        $this->assertSame('fields',$converted['body_mode']);
        $this->assertArrayNotHasKey('json_body',$converted);
        $this->assertSame(http_build_query(WorkflowAmoReadCatalog::build($original)['query']), http_build_query(WorkflowAmoReadCatalog::build($converted)['query']));
        $page = \Livewire\Livewire::test(\Tests\Support\WorkflowCanvasFixture::class, ['workflowActions'=>[
            ['id'=>'read','type'=>'amocrm_read','config'=>$original],
        ]])->call('openWorkflowActionEditor','read')->assertSet('mountedActions.0.data.body_mode','fields')
            ->call('callMountedAction')->assertHasNoErrors();
        $saved = $page->get('workflowActions')[0]['config'];
        $this->assertSame(http_build_query(WorkflowAmoReadCatalog::build($original)['query']), http_build_query(WorkflowAmoReadCatalog::build($saved)['query']));
    }

    public function test_dynamic_legacy_query_is_preserved_instead_of_silently_emptied(): void
    {
        $config = ['operation'=>'tasks.list','body_mode'=>'json','json_body'=>'{{ $json.query }}'];
        $this->assertSame($config,WorkflowAmoReadCatalog::editorConfig($config));
        \Livewire\Livewire::test(\Tests\Support\WorkflowCanvasFixture::class, ['workflowActions'=>[
            ['id'=>'read','type'=>'amocrm_read','config'=>$config],
        ]])->call('openWorkflowActionEditor','read')->assertSet('mountedActions.0.data.json_body','{{ $json.query }}')
            ->call('callMountedAction')->assertHasNoErrors()->assertSet('workflowActions.0.config.json_body','{{ $json.query }}');
    }

    protected function setUp(): void { parent::setUp(); WorkflowCanvasDatabase::prepare(); Http::preventStrayRequests(); }

    public function test_every_catalog_operation_builds_a_scoped_read_path(): void
    {
        $operations = WorkflowAmoReadCatalog::operations();
        $this->assertGreaterThan(70, count($operations));
        foreach ($operations as $key => $operation) {
            $request = WorkflowAmoReadCatalog::build(['operation' => $key, 'id' => 'abc123', 'entity_id' => 2, 'pipeline_id' => 3, 'catalog_id' => 4, 'request_path' => '/api/v4/leads']);
            $this->assertStringStartsWith('/api/v4/', $request['path']);
            $this->assertStringNotContainsString('{', $request['path']);
        }
    }

    public function test_structured_query_parameters_and_json_support_arrays(): void
    {
        $config = ['operation' => 'contacts.list', 'parameters' => [
            ['name' => 'filter[id][]', 'value' => [1, 2]], ['name' => 'filter[id][]', 'value' => 3], ['name' => 'limit', 'value' => 50],
        ]];
        $this->assertSame(['filter' => ['id' => [1, 2, 3]], 'limit' => 50], WorkflowAmoReadCatalog::build($config)['query']);
        $json = ['operation' => 'contacts.one', 'id' => 1, 'body_mode' => 'json', 'json_body' => '{"with":"leads"}'];
        $this->assertSame(['with' => 'leads'], WorkflowAmoReadCatalog::build($json)['query']);
    }

    public function test_custom_requests_cannot_change_host_or_escape_the_api(): void
    {
        foreach (['https://evil.test/', '//evil.test/', '/api/v4/../oauth2', '/api/v4/%2e%2e/secrets', '/api/v4/leads?limit=1', '/api/v4/leads#bad', '/api/v4/leads/\\evil'] as $path) {
            try { WorkflowAmoReadCatalog::build(['operation' => 'custom', 'request_path' => $path]); $this->fail('Accepted '.$path); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_read_executor_sends_only_get_and_does_not_follow_next_links(): void
    {
        Http::fake(['https://workflow-read.test/api/v4/contacts*' => Http::response(['_embedded' => ['contacts' => [['id' => 42]]], '_links' => ['next' => ['href' => 'https://evil.test/']]])]);
        $executor = app(WorkflowAmoCrmActionExecutor::class);
        $result = (new \ReflectionMethod($executor, 'readAmo'))->invoke($executor, (new Account)->forceFill(['endpoint' => 'https://workflow-read.test', 'access_token' => 'test-only']), ['operation' => 'contacts.list']);
        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['output']['items'][0]['id']);
        $this->assertTrue($result['output']['has_more']);
        Http::assertSentCount(1);
        Http::assertSent(fn($request) => $request->method() === 'GET' && str_starts_with($request->url(), 'https://workflow-read.test/api/v4/contacts'));
    }
}
