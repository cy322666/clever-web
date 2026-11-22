<?php

namespace Tests\Unit\Workflows;

use App\Models\Core\Account;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Services\Workflows\WorkflowAmoCrmLoopGuard;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowCanvasFixture;
use Tests\TestCase;

class WorkflowActionFieldsTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); WorkflowCanvasDatabase::prepare(); Http::preventStrayRequests(); }

    public function test_new_amocrm_nodes_have_no_hardcoded_ids_and_creation_has_no_context_link(): void
    {
        foreach (\App\Workflows\Actions\WorkflowAmoCrmActionCatalog::classes() as $class) {
            $config = $class::workflowDefaultConfig();
            $this->assertSame(in_array($class::workflowType(), ['amocrm_create_contact','amocrm_create_company'], true) ? 'manual' : 'context', $config['entity_source'], $class);
            $this->assertArrayHasKey('target_entity_id', $config, $class);
            $this->assertNull($config['target_entity_id'], $class);
            foreach (['lead_id', 'contact_id', 'company_id', 'customer_id', 'task_id'] as $key) {
                $this->assertNull($config[$key] ?? null, $class.' '.$key);
            }
        }
        foreach (['amocrm_update_contact_fields', 'amocrm_add_note', 'amocrm_create_task', 'amocrm_start_salesbot', 'amocrm_contact_leads'] as $type) {
            $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [['id' => 'blank', 'type' => $type, 'config' => []]]])
                ->call('openWorkflowActionEditor', 'blank');
            $key = $type === 'amocrm_contact_leads' ? 'lead_id' : 'target_entity_id';
            $page->assertSet('mountedActions.0.data.'.$key, null);
        }
    }

    public function test_contact_get_and_update_are_explicit_nodes_and_get_fetches_the_contact(): void
    {
        $classes = collect(\App\Workflows\Actions\WorkflowAmoCrmActionCatalog::classes())
            ->mapWithKeys(fn (string $class): array => [$class::workflowType() => $class]);

        $this->assertSame('Получить контакт', $classes['amocrm_get_contact']::workflowName());
        $this->assertSame('Обновить контакт', $classes['amocrm_update_contact_fields']::workflowName());
        $this->assertSame('contact', $classes['amocrm_get_contact']::workflowDefaultConfig()['target_entity']);

        Http::fake([
            'https://workflow-fields.test/api/v4/contacts/77*' => Http::response([
                'id' => 77,
                'name' => 'Тестовый контакт',
                'first_name' => 'Тест',
                '_embedded' => ['leads' => [['id' => 501]]],
            ]),
        ]);
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $method = new \ReflectionMethod($executor, 'getContact');
        $account = (new Account)->forceFill(['endpoint' => 'https://workflow-fields.test', 'access_token' => 'test-only']);
        $result = $method->invoke($executor, $account, [
            'entity_source' => 'manual',
            'target_entity' => 'contact',
            'target_entity_id' => 77,
        ], new WorkflowContext);

        $this->assertTrue($result['success']);
        $this->assertSame(77, $result['output']['id']);
        $this->assertSame('Тестовый контакт', $result['output']['contact']['name']);
        $this->assertSame('contact', $result['output']['entity_type']);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://workflow-fields.test/api/v4/contacts/77?with=leads');
    }

    public function test_explicit_ids_are_preserved_when_opening_but_cleared_when_changing_entity_type(): void
    {
        foreach (['amocrm_create_task', 'amocrm_add_note'] as $type) {
            $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [[
                'id' => 'configured', 'type' => $type,
                'config' => ['target_entity' => 'lead', 'target_entity_id' => '{{ $node["read"].json.id }}'],
            ]]])->call('openWorkflowActionEditor', 'configured')
                ->assertSet('mountedActions.0.data.entity_source', 'manual')
                ->assertSet('mountedActions.0.data.target_entity_id', '{{ $node["read"].json.id }}');
            $page->set('mountedActions.0.data.target_entity', 'contact')
                ->assertSet('mountedActions.0.data.target_entity_id', null);
        }
    }

    public function test_an_empty_or_invalid_configured_id_cannot_mutate_the_trigger_or_a_previous_entity(): void
    {
        Http::fake();
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $account = (new Account)->forceFill(['endpoint' => 'https://workflow-fields.test', 'access_token' => 'test-only']);
        $method = new \ReflectionMethod($executor, 'updateFields');
        $client = (new \ReflectionClass($method->getParameters()[0]->getType()->getName()))->newInstanceWithoutConstructor();
        $context = (new WorkflowContext)->setTriggerData(['entity' => 'lead', 'lead' => ['id' => 42]]);
        $context->setStepOutput('previous', ['entity_type' => 'lead', 'entity_id' => 99]);
        foreach ([null, '', 0, '{{missing.id}}', 'incorrect'] as $id) {
            $result = $method->invoke($executor, $client, $account, [
                'entity_source' => 'manual', 'target_entity' => 'lead', 'target_entity_id' => $id,
                'standard_fields' => ['name' => 'Must not be sent'],
            ], $context);
            $this->assertFalse($result['success']);
        }
        Http::assertNothingSent();
    }

    public function test_context_mode_uses_and_reports_the_trigger_entity_id(): void
    {
        Http::fake(['https://workflow-fields.test/*' => Http::response(['id' => 42])]);
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $account = (new Account)->forceFill(['id' => 1, 'endpoint' => 'https://workflow-fields.test', 'access_token' => 'test-only']);
        $method = new \ReflectionMethod($executor, 'updateFields');
        $client = (new \ReflectionClass($method->getParameters()[0]->getType()->getName()))->newInstanceWithoutConstructor();
        $context = (new WorkflowContext)->setTriggerData(['entity' => 'contact', 'item' => ['id' => 77]]);
        $result = $method->invoke($executor, $client, $account, [
            'entity_source' => 'context',
            'target_entity' => 'lead',
            'target_entity_id' => null,
            'standard_fields' => ['name' => 'Контекстная сущность'],
        ], $context);

        $this->assertTrue($result['success']);
        $this->assertSame('contact', $result['output']['entity_type']);
        $this->assertSame(77, $result['output']['entity_id']);
        Http::assertSent(fn($request) => $request->method() === 'PATCH'
            && $request->url() === 'https://workflow-fields.test/api/v4/contacts/77');
    }

    public function test_a_fixed_entity_action_does_not_switch_to_another_trigger_type(): void
    {
        Http::fake();
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $account = (new Account)->forceFill(['endpoint' => 'https://workflow-fields.test', 'access_token' => 'test-only']);
        $method = new \ReflectionMethod($executor, 'updateFields');
        $client = (new \ReflectionClass($method->getParameters()[0]->getType()->getName()))->newInstanceWithoutConstructor();
        $context = (new WorkflowContext)->setTriggerData(['entity' => 'lead', 'item' => ['id' => 77]]);

        $result = $method->invoke($executor, $client, $account, [
            'entity_source' => 'context',
            'target_entity' => 'contact',
            'target_entity_locked' => true,
            'standard_fields' => ['name' => 'Не отправлять'],
        ], $context);

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    public function test_task_form_accepts_expressions_through_fields_and_rejects_json_mode(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)->call('openWorkflowActionEditor', 'task');
        $page->assertSet('mountedActions.0.name', 'configureWorkflowAction');
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $html = (new \ReflectionMethod($page->instance(), 'getMountedActionSchema'))->invoke($page->instance())->toHtml();
        $this->assertFalse(str_contains($html, 'Данные шагов'));
        $this->assertTrue(str_contains($html, 'workflow-node-output'));
        // The removed run strip must not return to the legacy form workspace.
        $this->assertFalse(str_contains($html, 'Запустить'));
        $this->assertFalse(str_contains($html, 'Запустить ноду'));
        $this->assertStringContainsString('aria-label="Вставить поле"', $html);
        $this->assertStringNotContainsString('>Вручную<', $html);
        $this->assertFalse(str_contains($html, '@js'));
        $this->assertFalse(str_contains($html, '$getLabel'));
        $page->set('mountedActions.0.data.target_entity_id', '{{ $json.id }}')
            ->set('mountedActions.0.data.body_mode', 'json')
            ->set('mountedActions.0.data.json_body', '{"entity_id":42,"entity_type":"leads","task_type_id":1,"text":"Тест","complete_till":1800000000}')
            ->call('callMountedAction')->assertHasErrors(['mountedActions.0.data.body_mode' => 'in']);
        $page->set('mountedActions.0.data.body_mode', 'fields')
            ->set('mountedActions.0.data.task_type_id', '1')
            ->set('mountedActions.0.data.complete_till', '1800000000')
            ->set('mountedActions.0.data.text', 'Тест {{ $json.name }}')
            ->call('callMountedAction')->assertHasNoErrors()->assertSet('mountedActions', []);
        $saved = $page->get('workflowActions')[0]['config']['true_actions'][0]['config'];
        $this->assertSame('fields', $saved['body_mode']);
        $this->assertSame('manual', $saved['entity_source']);
        $this->assertSame('{{ $json.id }}', $saved['target_entity_id']);
        $this->assertSame('Тест {{ $json.name }}', $saved['text']);
        $issues = \App\Services\Workflows\WorkflowDefinitionValidator::configIssues('amocrm_create_task', [
            'body_mode' => 'json', 'json_body' => '{"entity_id":42,"entity_type":"leads","task_type_id":1,"text":"Тест","complete_till":1800000000}',
        ]);
        $this->assertContains('Ввод JSON отключён. Заполните параметры ноды через поля.', $issues);
    }

    public function test_json_results_use_a_collapsible_tree(): void
    {
        $html = view('filament.workflow-builder.workflow-json-tree', [
            'value' => ['items' => [['id' => 42, 'name' => 'Тест']]],
        ])->render();

        $this->assertStringContainsString('workflow-json-tree', $html);
        $this->assertStringNotContainsString('Свернуть всё', $html);
        $this->assertStringNotContainsString('Развернуть всё', $html);
        $this->assertStringContainsString('toggle(row.path)', $html);
        $this->assertStringContainsString('copyValue(row.value, row.id, $event.currentTarget)', $html);
    }

    public function test_status_form_keeps_pipeline_and_status_expressions_as_strings(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [['id' => 'status', 'type' => 'amocrm_change_lead_status', 'config' => []]]])
            ->call('openWorkflowActionEditor', 'status')
            ->set('mountedActions.0.data.target_entity_id', '{{ $json.id }}')
            ->set('mountedActions.0.data.pipeline_id', '{{ $json.pipeline_id }}')->set('mountedActions.0.data.status_id', '{{ $json.status_id }}')
            ->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame('{{ $json.status_id }}', $page->get('workflowActions')[0]['config']['status_id']);
    }

    public function test_editing_a_legacy_condition_preserves_both_branches_and_their_flags(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)->call('openWorkflowActionEditor', 'condition');
        $before = $page->get('workflowActions')[0]['config'];
        $page->call('callMountedAction')->assertHasNoErrors();
        $after = $page->get('workflowActions')[0]['config'];
        foreach (['true_actions', 'false_actions', 'has_true_branch', 'has_false_branch'] as $key) $this->assertSame($before[$key], $after[$key]);
    }

    public function test_json_entity_update_sends_a_typed_object_to_the_selected_entity_only(): void
    {
        Http::fake(['https://workflow-fields.test/*' => Http::response(['id' => 42])]);
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $account = (new Account)->forceFill(['id' => 1, 'endpoint' => 'https://workflow-fields.test', 'access_token' => 'test-only']);
        $context = (new WorkflowContext)->setTriggerData(['id' => 42, 'name' => 'ООО "Тест"']);
        $config = $context->resolve(['target_entity' => 'lead', 'target_entity_id' => '{{ $json.id }}', 'body_mode' => 'json', 'json_body' => '{"name":"{{ $json.name }}","price":100}']);
        $clientClass = (new \ReflectionMethod($executor, 'updateFields'))->getParameters()[0]->getType()->getName();
        $client = (new \ReflectionClass($clientClass))->newInstanceWithoutConstructor();
        $result = (new \ReflectionMethod($executor, 'updateFields'))->invoke($executor, $client, $account, $config, $context);
        $this->assertTrue($result['success']);
        Http::assertSent(fn($request) => $request->method() === 'PATCH' && $request->url() === 'https://workflow-fields.test/api/v4/leads/42' && $request['name'] === 'ООО "Тест"' && $request['price'] === 100);
    }

    public function test_json_task_normalizes_ids_and_time(): void
    {
        Http::fake(['https://workflow-fields.test/*' => Http::response(['_embedded' => ['tasks' => [['id' => 7]]]])]);
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $account = (new Account)->forceFill(['id' => 1, 'endpoint' => 'https://workflow-fields.test', 'access_token' => 'test-only']);
        $method = new \ReflectionMethod($executor, 'createTask');
        $client = (new \ReflectionClass($method->getParameters()[0]->getType()->getName()))->newInstanceWithoutConstructor();
        $result = $method->invoke($executor, $client, $account, ['body_mode' => 'json', 'json_body' => '{"entity_id":42,"entity_type":"leads","task_type_id":2,"text":"Тест","complete_till":1800000000,"responsible_user_id":9}'], null);
        $this->assertTrue($result['success']);
        Http::assertSent(fn($request) => $request->method() === 'POST' && $request->url() === 'https://workflow-fields.test/api/v4/tasks' && $request[0]['entity_id'] === 42 && $request[0]['complete_till'] === 1800000000 && $request[0]['responsible_user_id'] === 9);
    }

    public function test_named_update_fields_preserve_zero_and_skip_unchanged_fields(): void
    {
        Http::fake(['https://workflow-fields.test/*' => Http::response(['id' => 42])]);
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $account = (new Account)->forceFill(['id' => 1, 'endpoint' => 'https://workflow-fields.test', 'access_token' => 'test-only']);
        $method = new \ReflectionMethod($executor, 'updateFields');
        $client = (new \ReflectionClass($method->getParameters()[0]->getType()->getName()))->newInstanceWithoutConstructor();
        $result = $method->invoke($executor, $client, $account, [
            'target_entity' => 'lead', 'target_entity_id' => 42, 'body_mode' => 'fields',
            'standard_fields' => ['name' => 'Новая сделка', 'price' => '0', 'status_id' => '', 'pipeline_id' => null],
        ], null);
        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => $request->data() === ['name' => 'Новая сделка', 'price' => 0]);
    }

    public function test_create_lead_sends_fields_and_returns_the_created_id(): void
    {
        Http::fake(['https://workflow-fields.test/*' => Http::response(['_embedded'=>['leads'=>[['id'=>123]]]])]);
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $account = (new Account)->forceFill(['id'=>1,'endpoint'=>'https://workflow-fields.test','access_token'=>'test-only']);
        $method = new \ReflectionMethod($executor, 'createEntity');
        $client = (new \ReflectionClass($method->getParameters()[0]->getType()->getName()))->newInstanceWithoutConstructor();
        $context = new WorkflowContext;
        $context->setVariable('lead', ['id'=>42]);
        $config = $context->resolve(['name'=>'Новая {{lead.id}}','price'=>'0','pipeline_id'=>'10','status_id'=>'20','responsible_user_id'=>'9']);
        $result = $method->invoke($executor, $client, $account, 'lead', $config, null);
        $this->assertTrue($result['success']);
        $this->assertSame(123, $result['output']['entity_id']);
        Http::assertSent(fn ($request) => $request->method()==='POST'
            && $request->url()==='https://workflow-fields.test/api/v4/leads'
            && $request[0]['name']==='Новая 42' && $request[0]['price']==0
            && $request[0]['pipeline_id']===10 && $request[0]['status_id']===20 && $request[0]['responsible_user_id']===9);
        Http::assertSentCount(1);
    }

    public function test_create_company_sends_fields_and_returns_the_created_id(): void
    {
        Http::fake(['https://workflow-fields.test/*' => Http::response(['_embedded'=>['companies'=>[['id'=>321]]]])]);
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $account = (new Account)->forceFill(['id'=>1,'endpoint'=>'https://workflow-fields.test','access_token'=>'test-only']);
        $method = new \ReflectionMethod($executor, 'createEntity');
        $client = (new \ReflectionClass($method->getParameters()[0]->getType()->getName()))->newInstanceWithoutConstructor();

        $result = $method->invoke($executor, $client, $account, 'company', [
            'name'=>'ООО Тест',
            'responsible_user_id'=>'9',
            'tags'=>'Партнёр, VIP',
        ], null);

        $this->assertTrue($result['success']);
        $this->assertSame(321, $result['output']['entity_id']);
        Http::assertSent(fn ($request) => $request->method()==='POST'
            && $request->url()==='https://workflow-fields.test/api/v4/companies'
            && $request[0]['name']==='ООО Тест'
            && $request[0]['responsible_user_id']===9
            && $request[0]['_embedded']['tags']===[['name'=>'Партнёр'], ['name'=>'VIP']]);
        Http::assertSentCount(1);
    }

    public function test_contact_and_company_creation_forms_save_without_a_link(): void
    {
        foreach (['amocrm_create_contact','amocrm_create_company'] as $type) {
            $config = app(\Leek\FilamentWorkflows\Actions\ActionRegistry::class)->getDefaultConfig($type);
            $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions'=>[
                ['id'=>'standalone','type'=>$type,'config'=>$config],
            ]])->call('openWorkflowActionEditor','standalone')
                ->set('mountedActions.0.data.name','Без привязки')
                ->call('callMountedAction')->assertHasNoErrors();
            $saved = $page->get('workflowActions')[0]['config'];
            $this->assertEmpty($saved['target_entity'] ?? null);
            $this->assertEmpty($saved['target_entity_id'] ?? null);
            $this->assertSame([],\App\Services\Workflows\WorkflowDefinitionValidator::configIssues($type,$saved));
            $this->assertContains('Укажите ID сущности.',\App\Services\Workflows\WorkflowDefinitionValidator::configIssues($type,
                ['name'=>'Test','entity_source'=>'manual','target_entity'=>'lead']));
            $this->assertNotEmpty(\App\Services\Workflows\WorkflowDefinitionValidator::configIssues($type,
                ['name'=>'Test','entity_source'=>'manual','target_entity_id'=>42]));
        }
        Http::assertNothingSent();
    }

    public static function creationEntities(): array
    {
        return [['contact','contacts'],['company','companies']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('creationEntities')]
    public function test_standalone_creation_does_not_link_to_the_trigger_entity(string $entity, string $plural): void
    {
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $account = (new Account)->forceFill(['id'=>1,'endpoint'=>'https://workflow-fields.test','access_token'=>'test-only']);
        $method = new \ReflectionMethod($executor,'createEntity');
        $client = (new \ReflectionClass($method->getParameters()[0]->getType()->getName()))->newInstanceWithoutConstructor();
        $context = (new WorkflowContext)->setTriggerData(['entity'=>'lead','lead'=>['id'=>42]]);
        Http::fake(['https://workflow-fields.test/*'=>fn($request)=>Http::response(['_embedded'=>[$plural=>$request->method()==='GET'?[]:[['id'=>321]]]])]);
        $result = $method->invoke($executor,$client,$account,$entity,['name'=>'Без привязки'],$context);
        $this->assertTrue($result['success']);
        $this->assertSame(321,$result['output']['entity_id']);
        Http::assertSentCount($entity === 'contact' ? 2 : 1);
        Http::assertNotSent(fn($request)=>str_ends_with($request->url(),'/link'));
        Http::assertSent(fn($request)=>$request->method()==='POST' && $request->url()==='https://workflow-fields.test/api/v4/'.$plural);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('creationEntities')]
    public function test_creation_still_links_when_a_target_and_id_are_selected(string $entity, string $plural): void
    {
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $account = (new Account)->forceFill(['id'=>1,'endpoint'=>'https://workflow-fields.test','access_token'=>'test-only']);
        $method = new \ReflectionMethod($executor,'createEntity');
        $client = (new \ReflectionClass($method->getParameters()[0]->getType()->getName()))->newInstanceWithoutConstructor();
        Http::fake(['https://workflow-fields.test/*'=>fn($request)=>Http::response(str_ends_with($request->url(),'/link')?[]:['_embedded'=>[$plural=>$request->method()==='GET'?[]:[['id'=>321]]]])]);
        $result = $method->invoke($executor,$client,$account,$entity,
            ['name'=>'С привязкой','entity_source'=>'manual','target_entity'=>'lead','target_entity_id'=>42],null);
        $this->assertTrue($result['success']);
        Http::assertSentCount($entity === 'contact' ? 3 : 2);
        Http::assertSent(fn($request)=>$request->url()==='https://workflow-fields.test/api/v4/leads/42/link'
            && $request[0]['to_entity_id']===321 && $request[0]['to_entity_type']===$plural);
    }

    public function test_link_entities_posts_a_company_link_to_the_selected_lead(): void
    {
        Http::fake(['https://workflow-fields.test/*' => Http::response([])]);
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $account = (new Account)->forceFill(['id'=>1,'endpoint'=>'https://workflow-fields.test','access_token'=>'test-only']);
        $method = new \ReflectionMethod($executor, 'linkEntity');
        $client = (new \ReflectionClass($method->getParameters()[0]->getType()->getName()))->newInstanceWithoutConstructor();

        $result = $method->invoke($executor, $client, $account, [
            'entity_source' => 'manual',
            'target_entity' => 'lead',
            'target_entity_id' => 42,
            'linked_entity' => 'company',
            'linked_entity_id' => 77,
        ], null);

        $this->assertTrue($result['success']);
        $this->assertSame('company', $result['output']['linked_entity']);
        $this->assertSame(77, $result['output']['linked_entity_id']);
        Http::assertSent(fn ($request) => $request->method()==='POST'
            && $request->url()==='https://workflow-fields.test/api/v4/leads/42/link'
            && $request[0]['to_entity_id']===77
            && $request[0]['to_entity_type']==='companies');
        Http::assertSentCount(1);
    }
}
