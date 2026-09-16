<?php

namespace Tests\Unit\Workflows;

use App\Models\Core\Account;
use App\Models\User;
use App\Services\amoCRM\Client;
use App\Services\Workflows\WorkflowNodeReferences;
use App\Services\Workflows\WorkflowAmoCrmSalesBotService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasFixture;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowNodeReferencesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); WorkflowListDatabase::prepare(); config(['cache.default'=>'array']); Http::preventStrayRequests();
        (require database_path('migrations/2023_09_01_120654_create_fields_table.php'))->up();
        Schema::table('amocrm_fields', fn(Blueprint $table)=>$table->boolean('active')->default(true));
        $this->actingAs(User::findOrFail(1));
        DB::table('accounts')->insert(['id'=>1,'user_id'=>1,'widget'=>'workflows','subdomain'=>'test','active'=>true,'refresh_token'=>'test']);
    }
    public function test_reference_plan_depends_on_node_entity(): void
    {
        $this->assertSame(['salesbots'],WorkflowNodeReferences::plan('amocrm_start_salesbot'));
        $this->assertSame(['pipelines'],WorkflowNodeReferences::plan('amocrm_change_lead_status'));
        $this->assertSame(['fields:contacts','tags:contacts','users'],WorkflowNodeReferences::plan('amocrm_update_contact_fields'));
        $this->assertSame(['users','task_types'],WorkflowNodeReferences::plan('amocrm_create_task'));
        $this->assertSame([],WorkflowNodeReferences::plan('http_request'));
    }
    public function test_contact_refresh_updates_only_own_contact_fields_and_tags(): void
    {
        DB::table('amocrm_fields')->insert([
            ['user_id'=>1,'entity_type'=>'contacts','field_id'=>1,'active'=>true],
            ['user_id'=>1,'entity_type'=>'leads','field_id'=>2,'active'=>true],
            ['user_id'=>2,'entity_type'=>'contacts','field_id'=>3,'active'=>true],
        ]);
        $client=$this->createMock(Client::class);
        $client->expects($this->exactly(3))->method('requestV4')->willReturnCallback(function($method,$path) {
            $this->assertSame('GET',$method);
            return match($path) {
                '/api/v4/contacts/custom_fields'=>['_embedded'=>['custom_fields'=>[['id'=>10,'name'=>'Новое поле','type'=>'select','enums'=>[['id'=>7,'value'=>'Да']]]]]],
                '/api/v4/contacts/tags'=>['_embedded'=>['tags'=>[['id'=>11,'name'=>'VIP']]]],
                '/api/v4/users'=>['_embedded'=>['users'=>[['id'=>12,'name'=>'Менеджер','rights'=>['is_active'=>true]]]]],
            };
        });
        $this->service($client)->refresh('amocrm_update_contact_fields',[]);
        $this->assertSame(0,(int)DB::table('amocrm_fields')->where('field_id',1)->value('active'));
        $this->assertSame(1,(int)DB::table('amocrm_fields')->where('field_id',2)->value('active'));
        $this->assertSame(1,(int)DB::table('amocrm_fields')->where('field_id',3)->value('active'));
        $this->assertSame(['VIP'=>'VIP'],WorkflowNodeReferences::options('tags:contacts'));
        $this->assertSame('Новое поле',DB::table('amocrm_fields')->where('field_id',10)->value('name'));
        $this->actingAs(User::findOrFail(2));
        $this->assertSame([],WorkflowNodeReferences::options('tags:contacts'));
    }
    public function test_failed_fetch_preserves_old_reference_data(): void
    {
        DB::table('amocrm_fields')->insert(['user_id'=>1,'entity_type'=>'contacts','field_id'=>1,'active'=>true]);
        $client=$this->createMock(Client::class);
        $client->method('requestV4')->willReturnCallback(fn($method,$path)=>$path==='/api/v4/contacts/custom_fields' ? ['_embedded'=>['custom_fields'=>[]]] : throw new \RuntimeException('Failed request'));
        try { $this->service($client)->refresh('amocrm_update_contact_fields',[]); $this->fail('Refresh accepted'); }
        catch(\RuntimeException) { $this->assertSame(1,(int)DB::table('amocrm_fields')->value('active')); }
    }

    public function test_bot_refresh_replaces_the_cached_bot_options(): void
    {
        $account=Account::findOrFail(1);
        app(WorkflowAmoCrmSalesBotService::class)->replaceOptions($account,[['id'=>1,'name'=>'Старый бот']]);
        $client=$this->createMock(Client::class);
        $client->expects($this->once())->method('requestV4')->with('GET','/api/v4/bots',[],['page'=>1,'limit'=>250])
            ->willReturn(['_embedded'=>['items'=>[['id'=>2,'name'=>'Новый бот']]]]);
        $this->service($client)->refresh('amocrm_start_salesbot',[]);
        $this->assertSame([2=>'Новый бот'],app(WorkflowAmoCrmSalesBotService::class)->options());
    }

    public function test_refresh_reads_all_pages_of_pipeline_statuses(): void
    {
        $client=$this->createMock(Client::class);
        $client->expects($this->exactly(2))->method('requestV4')->willReturnCallback(fn($method,$path,$payload,$query)=>[
            '_page_count'=>2,'_embedded'=>['pipelines'=>[['id'=>$query['page'],'name'=>'Воронка '.$query['page'],
                '_embedded'=>['statuses'=>[['id'=>100+$query['page'],'name'=>'Этап '.$query['page']]]]]]],
        ]);
        $this->service($client)->refresh('amocrm_change_lead_status',[]);
        $this->assertSame(2,DB::table('amocrm_statuses')->where('user_id',1)->count());
    }
    public function test_refresh_does_not_refill_or_erase_unsaved_node_settings(): void
    {
        $service=$this->createMock(WorkflowNodeReferences::class);
        $service->expects($this->once())->method('refresh')->with('amocrm_create_task',$this->callback(fn($config)=>$config['text']==='Не потерять'))->willReturn(['users','task_types']);
        $this->app->instance(WorkflowNodeReferences::class,$service);
        Livewire::test(WorkflowCanvasFixture::class)->call('openWorkflowActionEditor','task')
            ->set('mountedActions.0.data.text','Не потерять')->call('refreshEditingNodeReferences')
            ->assertSet('mountedActions.0.data.text','Не потерять')->assertHasNoErrors();
    }
    private function service(Client $client): WorkflowNodeReferences
    {
        return new class($client) extends WorkflowNodeReferences {
            public function __construct(private Client $api) {}
            protected function client(Account $account): Client { return $this->api; }
        };
    }

    public function test_json_file_can_be_imported_through_the_editor_action(): void
    {
        $json = \App\Services\Workflows\WorkflowTransfer::encode('Из файла',[
            'trigger'=>['type'=>'manual','config'=>[]],
            'actions'=>[['id'=>'delay','type'=>'workflow_delay','config'=>['seconds'=>5]]],
        ]);
        $file=\Illuminate\Http\UploadedFile::fake()->createWithContent('flow.json',$json);
        Livewire::test(WorkflowCanvasFixture::class)->call('mountAction','importWorkflow')
            ->set('mountedActions.0.data.file',$file)->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame('Из файла',\App\Models\Workflows\Workflow::firstOrFail()->name);
        $this->assertFalse(\App\Models\Workflows\Workflow::firstOrFail()->is_active);
    }
}
