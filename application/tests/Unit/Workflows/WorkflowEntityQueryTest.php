<?php

namespace Tests\Unit\Workflows;

use App\Models\Core\Account;
use App\Models\User;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Services\Workflows\WorkflowAmoReadCatalog;
use App\Services\Workflows\WorkflowEntityQuery;
use App\Services\Workflows\WorkflowNodeReferences;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasFixture;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowEntityQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); WorkflowListDatabase::prepare(); Http::preventStrayRequests();
        (require database_path('migrations/2023_09_01_120654_create_fields_table.php'))->up();
        Schema::table('amocrm_fields', fn(Blueprint $table) => $table->boolean('active')->default(true));
        $this->actingAs(User::findOrFail(1));
    }

    private function taskFilters(): array
    {
        return [
            ['field'=>'entity_type', 'operator'=>'eq', 'value'=>'leads'],
            ['field'=>'entity_id', 'operator'=>'eq', 'value'=>'{{lead.id}}'],
            ['field'=>'is_completed', 'operator'=>'eq', 'value'=>'0'],
        ];
    }

    public function test_open_tasks_for_trigger_deal_resolve_and_send_a_scoped_get(): void
    {
        $context = new WorkflowContext;
        $context->setVariable('lead', ['id'=>42]);
        $config = $context->resolve(['operation'=>'tasks.list', 'body_mode'=>'builder', 'filters'=>$this->taskFilters()]);
        Http::fake(['https://query.test/api/v4/tasks*'=>Http::response(['_embedded'=>['tasks'=>[['id'=>7]]]])]);
        $executor = app(WorkflowAmoCrmActionExecutor::class);
        $result = (new \ReflectionMethod($executor, 'readAmo'))->invoke($executor,
            (new Account)->forceFill(['user_id'=>1, 'endpoint'=>'https://query.test', 'access_token'=>'test-only']), $config);
        $this->assertSame(1, $result['output']['count']);
        Http::assertSent(function($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            return $request->method()==='GET' && $query['filter']===['entity_type'=>'leads', 'entity_id'=>['42'], 'is_completed'=>'0'];
        });
        Http::assertSentCount(1);
        $this->assertSame(['users','task_types'], WorkflowNodeReferences::plan('amocrm_read', ['operation'=>'tasks.list']));
    }

    public function test_lead_statuses_ranges_and_repeated_values_build_documented_filters(): void
    {
        $query = WorkflowEntityQuery::build(['operation'=>'leads.list', 'sort'=>'updated_at', 'direction'=>'desc', 'filters'=>[
            ['field'=>'statuses','value'=>20,'pipeline_id'=>10],
            ['field'=>'statuses','value'=>21,'pipeline_id'=>10],
            ['field'=>'price','operator'=>'from','value'=>0],
            ['field'=>'price','operator'=>'to','value'=>100],
            ['field'=>'responsible_user_id','value'=>[3,4]],
            ['field'=>'created_at','operator'=>'from','value'=>'2026-09-12'],
        ]]);
        $this->assertSame([['pipeline_id'=>10,'status_id'=>20], ['pipeline_id'=>10,'status_id'=>21]], $query['filter']['statuses']);
        $this->assertSame(['from'=>0.0, 'to'=>100.0], $query['filter']['price']);
        $this->assertSame([3,4], $query['filter']['responsible_user_id']);
        $this->assertIsInt($query['filter']['created_at']['from']);
        $this->assertSame(['updated_at'=>'desc'], $query['order']);
    }

    public function test_custom_fields_and_enums_are_scoped_to_owner_and_entity(): void
    {
        foreach ([[1,'contacts',11,'select'],[2,'contacts',12,'text'],[1,'leads',13,'numeric'],[1,'contacts',14,'numeric']] as [$user,$entity,$id,$type]) {
            DB::table('amocrm_fields')->insert(['user_id'=>$user,'entity_type'=>$entity,'field_id'=>$id,'type'=>$type,'name'=>'Поле '.$id,'enums'=>'[{"id":8,"value":"VIP"}]','active'=>true]);
        }
        $fields = WorkflowEntityQuery::fields('contacts.list', 1);
        $this->assertSame([8=>'VIP'], $fields['custom:11']['options']);
        $this->assertArrayNotHasKey('custom:12', $fields);
        $this->assertArrayNotHasKey('custom:13', $fields);
        $query = WorkflowEntityQuery::build(['operation'=>'contacts.list','filters'=>[
            ['field'=>'custom:11','value'=>8], ['field'=>'custom:14','operator'=>'from','value'=>5],
        ]],1);
        $this->assertSame([11=>[8],14=>['from'=>5.0]],$query['filter']['custom_fields_values']);
        $this->expectException(\InvalidArgumentException::class);
        WorkflowEntityQuery::build(['operation'=>'contacts.list','filters'=>[['field'=>'custom:12','value'=>'secret']]],1);
    }

    public function test_invalid_or_contradictory_filters_do_not_broaden_the_query(): void
    {
        foreach ([
            ['operation'=>'tasks.list','filters'=>[['field'=>'entity_id','value'=>42]]],
            ['operation'=>'tasks.list','filters'=>[['field'=>'is_completed','value'=>0],['field'=>'is_completed','value'=>1]]],
            ['operation'=>'tasks.list','filters'=>[['field'=>'price','value'=>20]]],
            ['operation'=>'tasks.list','filters'=>[['field'=>'id','operator'=>'contains','value'=>20]]],
            ['operation'=>'leads.list','filters'=>[['field'=>'statuses','value'=>20]]],
            ['operation'=>'leads.list','filters'=>[['field'=>'price','operator'=>'from','value'=>10],['field'=>'price','operator'=>'to','value'=>5]]],
            ['operation'=>'contacts.list','limit'=>251],
            ['operation'=>'companies.list','page'=>0],
            ['operation'=>'tasks.list','sort'=>'updated_at'],
        ] as $config) {
            try { WorkflowEntityQuery::build($config); $this->fail('Invalid query accepted'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        Http::assertNothingSent();
    }

    public function test_legacy_parameters_and_json_are_unchanged(): void
    {
        $expected = ['filter'=>['entity_type'=>'leads','entity_id'=>42,'is_completed'=>0]];
        $parameters = array_map(fn($field,$value) => ['name'=>'filter['.$field.']','value'=>$value],array_keys($expected['filter']),array_values($expected['filter']));
        $this->assertSame($expected, WorkflowAmoReadCatalog::build(['operation'=>'tasks.list','parameters'=>$parameters])['query']);
        $this->assertSame($expected, WorkflowAmoReadCatalog::build(['operation'=>'tasks.list','body_mode'=>'json','json_body'=>json_encode($expected)])['query']);
    }

    public function test_form_renders_task_options_and_preserves_expressions_on_save(): void
    {
        $config = ['operation'=>'tasks.list','body_mode'=>'builder','limit'=>50,'page'=>1,'filters'=>$this->taskFilters()];
        $html = Livewire::test(EntityQueryEditorFixture::class, ['formData'=>$config])->html();
        $this->assertStringContainsString('Открыта', $html);
        $this->assertStringContainsString('entity_type', $html);
        $this->assertStringContainsString('Добавить фильтр', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*JSON\s*</u', $html);
        $this->assertStringNotContainsString('Бюджет', $html);
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions'=>[
            ['id'=>'read','type'=>'amocrm_read','config'=>$config],
        ]])->call('openWorkflowActionEditor','read')->assertSet('mountedActions.0.name', 'configureWorkflowAction');
        $page->call('callMountedAction')->assertHasNoErrors();
        $config = $page->get('workflowActions')[0]['config'];
        $this->assertSame('tasks.list', $config['operation']);
        $this->assertSame('builder', $config['body_mode']);
        $this->assertSame('{{lead.id}}',array_values($config['filters'])[1]['value']);
        $this->assertSame('0',array_values($config['filters'])[2]['value']);
    }

    public function test_new_list_nodes_default_to_builder_but_other_reads_keep_parameters(): void
    {
        foreach (['tasks.list','leads.list','contacts.list','companies.list','customers.list','contacts.one'] as $operation) {
            $page = Livewire::test(WorkflowCanvasFixture::class)->call('selectReadOperation',$operation);
            $config = $page->get('workflowActions')[1]['config'];
            $this->assertSame($operation === 'contacts.one' ? 'fields' : 'builder', $config['body_mode']);
            $this->assertSame(50, $config['limit']);
            $this->assertSame(1, $config['page']);
        }
    }

    public function test_lead_form_renders_own_status_options_and_resets_value_on_field_change(): void
    {
        DB::table('amocrm_statuses')->insert(['user_id'=>1,'pipeline_id'=>10,'status_id'=>20,'pipeline_name'=>'Продажи','name'=>'Переговоры','active'=>true]);
        $page = Livewire::test(EntityQueryEditorFixture::class, ['formData'=>[
            'operation'=>'leads.list','body_mode'=>'builder','filters'=>[['field'=>'statuses','operator'=>'eq','pipeline_id'=>10,'value'=>20]],
        ]]);
        $this->assertStringContainsString('Переговоры', $page->html());
        $key = array_key_first($page->get('formData')['filters']);
        $page->set('formData.filters.'.$key.'.field','price')->assertSet('formData.filters.'.$key.'.value',null);
        $this->assertSame(['eq','from','to'],array_keys(WorkflowEntityQuery::operators(WorkflowEntityQuery::fields('leads.list')['price'])));
    }

    public function test_existing_raw_read_is_not_switched_or_emptied_on_save(): void
    {
        $parameters = [['name'=>'filter[is_completed]','value'=>'0']];
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions'=>[
            ['id'=>'read','type'=>'amocrm_read','config'=>['operation'=>'tasks.list','body_mode'=>'fields','parameters'=>$parameters]],
        ]])->call('openWorkflowActionEditor','read')->call('callMountedAction')->assertHasNoErrors();
        $config = $page->get('workflowActions')[0]['config'];
        $this->assertSame('fields',$config['body_mode']);
        $this->assertSame($parameters,array_values($config['parameters']));
        $this->assertSame(['filter'=>['is_completed'=>'0']],WorkflowAmoReadCatalog::build($config)['query']);
    }
}

class EntityQueryEditorFixture extends WorkflowCanvasFixture
{
    public array $formData = [];
    public function mount(): void { $this->queryBuilder->fill($this->formData); }
    public function queryBuilder(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->components(\App\Workflows\Actions\AmoCrmReadAction::workflowConfigSchema())->statePath('formData');
    }
    public function render(): string { return '<div>{{ $this->queryBuilder }}</div>'; }
}
