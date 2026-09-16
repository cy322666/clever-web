<?php

namespace Tests\Unit\Workflows;

use App\Models\Core\Account;
use App\Models\User;
use App\Services\amoCRM\Client;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Services\Workflows\WorkflowAmoCrmLoopGuard;
use App\Services\Workflows\WorkflowAmoCrmSalesBotService;
use App\Services\Workflows\WorkflowGraph;
use App\Workflows\Actions\WorkflowAmoCrmActionCatalog;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasFixture;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowSalesbotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        config(['cache.default'=>'array']);
        Http::preventStrayRequests();
    }

    public function test_salesbot_is_available_and_its_three_fields_accept_variables(): void
    {
        $service = $this->createMock(WorkflowAmoCrmSalesBotService::class);
        $service->method('options')->willReturn([7=>'Тестовый бот']);
        $this->app->instance(WorkflowAmoCrmSalesBotService::class, $service);
        $this->assertNotContains('amocrm_start_salesbot', WorkflowAmoCrmActionCatalog::unsupportedWorkflowTypes());
        $this->assertContains('amocrm_stop_salesbot', WorkflowAmoCrmActionCatalog::unsupportedWorkflowTypes());
        $page = Livewire::test(WorkflowCanvasFixture::class)->call('openDetachedActionPalette')->call('selectActionType','amocrm_start_salesbot');
        $nodes = WorkflowGraph::nodes($page->get('workflowActions'));
        $id = end($nodes)['step']['id'];
        $page->call('openWorkflowActionEditor',$id)
            ->set('mountedActions.0.data.bot_id','{{ $json.bot_id }}')
            ->set('mountedActions.0.data.entity_source','manual')
            ->set('mountedActions.0.data.target_entity','contacts')
            ->set('mountedActions.0.data.target_entity_id','{{ $json.contact_id }}')
            ->call('callMountedAction')->assertHasNoErrors();
        $config = WorkflowGraph::nodes($page->get('workflowActions'))['action:'.$id]['step']['config'];
        $this->assertSame('contacts',$config['target_entity']);
        $this->assertSame('{{ $json.contact_id }}',$config['target_entity_id']);
        $this->assertSame('{{ $json.bot_id }}',$config['bot_id']);
    }

    public function test_bot_list_loads_all_pages_and_caches_only_the_current_account(): void
    {
        DB::table('accounts')->insert([
            ['id'=>11,'user_id'=>1,'widget'=>'workflows','subdomain'=>'one','active'=>true,'refresh_token'=>'test'],
            ['id'=>22,'user_id'=>2,'widget'=>'workflows','subdomain'=>'two','active'=>true,'refresh_token'=>'test'],
        ]);
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(3))->method('requestV4')->willReturnCallback(function ($method,$path,$payload,$query) {
            $this->assertSame('GET',$method);
            $this->assertSame('/api/v4/bots',$path);
            $this->assertSame(250,$query['limit']);
            if (auth()->id()===2) return ['_page_count'=>1,'_embedded'=>['items'=>[['id'=>99,'name'=>'Другой аккаунт']]]];
            return ['_page_count'=>2,'_embedded'=>['items'=> $query['page']===1
                ? [['id'=>7,'name'=>'Alpha &amp; Co']]
                : [['id'=>8,'name'=>'Beta','settings'=>['active'=>false]]]]];
        });
        $service = new class($client) extends WorkflowAmoCrmSalesBotService {
            public function __construct(private Client $api) {}
            protected function client(Account $account): Client { return $this->api; }
        };
        $this->actingAs(User::findOrFail(1));
        $this->assertSame([7=>'Alpha & Co',8=>'Beta · неактивен'],$service->options());
        $this->assertSame([7=>'Alpha & Co',8=>'Beta · неактивен'],$service->options());
        $this->actingAs(User::findOrFail(2));
        $this->assertSame([99=>'Другой аккаунт'],$service->options());
    }

    public function test_empty_bot_catalog_still_renders_a_select_and_expression_mode(): void
    {
        $service = $this->createMock(WorkflowAmoCrmSalesBotService::class);
        $service->method('options')->willReturn([]);
        $this->app->instance(WorkflowAmoCrmSalesBotService::class, $service);
        $page = Livewire::test(WorkflowCanvasFixture::class)->call('openDetachedActionPalette')->call('selectActionType', 'amocrm_start_salesbot');
        $nodes = WorkflowGraph::nodes($page->get('workflowActions'));
        $page->call('openWorkflowActionEditor', end($nodes)['step']['id']);
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $html = (new \ReflectionMethod($page->instance(), 'getMountedActionSchema'))->invoke($page->instance())->toHtml();
        $this->assertStringContainsString('Нет доступных вариантов', $html);
        $this->assertMatchesRegularExpression('/<select[^>]+aria-label="SalesBot"/', $html);
        $this->assertStringContainsString('Переменная', $html);
    }

    public function test_launch_passes_each_supported_entity_and_requires_202(): void
    {
        Http::fake(['https://salesbot.test/*'=>Http::response('',202)]);
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $method = new \ReflectionMethod($executor,'startSalesbot');
        $account = (new Account)->forceFill(['endpoint'=>'https://salesbot.test','access_token'=>'test']);
        foreach (['leads','contacts','customers'] as $entity) {
            $context = (new WorkflowContext)->setVariable('lead',['id'=>42]);
            $result = $method->invoke($executor,$account,$context->resolve(['bot_id'=>'7','target_entity'=>$entity,'target_entity_id'=>'{{lead.id}}']));
            $this->assertSame(['bot_id'=>7,'entity_id'=>42,'entity_type'=>$entity,'status'=>'accepted'],$result['output']);
            Http::assertSent(fn ($request) => $request->method()==='POST' && $request->url()==='https://salesbot.test/api/v4/bots/7/run'
                && $request->data()===['entity_id'=>42,'entity_type'=>$entity]);
        }
        Http::assertSentCount(3);
    }

    public function test_launch_uses_the_visible_context_entity_when_id_is_not_configured(): void
    {
        Http::fake(['https://salesbot.test/*' => Http::response('', 202)]);
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $method = new \ReflectionMethod($executor, 'startSalesbot');
        $account = (new Account)->forceFill(['endpoint' => 'https://salesbot.test', 'access_token' => 'test']);
        $context = (new WorkflowContext)->setTriggerData(['entity' => 'contact', 'item' => ['id' => 55]]);

        $result = $method->invoke($executor, $account, [
            'bot_id' => 7,
            'entity_source' => 'context',
            'target_entity' => 'leads',
            'target_entity_id' => null,
        ], $context);

        $this->assertSame(['bot_id' => 7, 'entity_id' => 55, 'entity_type' => 'contacts', 'status' => 'accepted'], $result['output']);
        Http::assertSent(fn($request) => $request->data() === ['entity_id' => 55, 'entity_type' => 'contacts']);
    }

    public function test_launch_rejects_unsupported_entities_bad_ids_and_api_errors(): void
    {
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $method = new \ReflectionMethod($executor,'startSalesbot');
        $account = (new Account)->forceFill(['endpoint'=>'https://salesbot.test','access_token'=>'test']);
        foreach ([['bot_id'=>0],['target_entity'=>'companies'],['target_entity_id'=>'{{missing}}']] as $invalid) {
            try {
                $method->invoke($executor,$account,array_merge(['bot_id'=>7,'target_entity'=>'leads','target_entity_id'=>42],$invalid));
                $this->fail('Invalid input was accepted');
            } catch (\InvalidArgumentException $exception) { $this->assertNotEmpty($exception->getMessage()); }
        }
        Http::assertNothingSent();
        foreach ([403,404,302,200] as $status) {
            Http::fake(['https://salesbot.test/*'=>Http::response(['detail'=>'Test error'],$status)]);
            try {
                $method->invoke($executor,$account,['bot_id'=>7,'target_entity'=>'leads','target_entity_id'=>42]);
                $this->fail('Unexpected API status was accepted');
            } catch (\RuntimeException $exception) { $this->assertNotEmpty($exception->getMessage()); }
        }
    }

    public function test_dry_run_never_launches_a_real_bot(): void
    {
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $result = $executor->execute('amocrm_start_salesbot',['bot_id'=>7,'target_entity'=>'leads','target_entity_id'=>42],(new WorkflowContext)->setVariable('_dry_run',true));
        $this->assertTrue($result['success']);
        $this->assertTrue($result['output']['dry_run']);
        Http::assertNothingSent();
    }
}
