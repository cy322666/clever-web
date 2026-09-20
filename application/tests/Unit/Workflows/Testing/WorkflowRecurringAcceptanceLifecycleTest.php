<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows\Testing;

use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\amoCRM\Client;
use App\Services\Workflows\Testing\WorkflowLiveAcceptance;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Services\Workflows\WorkflowAmoCrmLoopGuard;
use App\Workflows\Context\WorkflowContext;
use App\Workflows\Engine\WorkflowDebugger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class WorkflowRecurringAcceptanceLifecycleTest extends TestCase
{
    private function set(WorkflowLiveAcceptance $runner,string $property,mixed $value): void
    {
        (new \ReflectionProperty($runner,$property))->setValue($runner,$value);
    }

    private function runner(string $reportPath): WorkflowLiveAcceptance
    {
        $runner=new WorkflowLiveAcceptance;
        foreach (['account'=>(new Account)->forceFill(['id'=>134,'subdomain'=>'widgetscenario']),
            'source'=>(new Workflow)->forceFill(['id'=>15,'user_id'=>142]),'marker'=>'unit-only',
            'reportPath'=>$reportPath,'report'=>['cases'=>[],'cleanup'=>[],'account'=>['amo_account_id'=>33098322]]] as $key=>$value) $this->set($runner,$key,$value);
        return $runner;
    }

    public function test_unfinished_checkpoint_is_not_overwritten_when_start_is_rejected(): void
    {
        $path=tempnam(sys_get_temp_dir(),'qa-checkpoint-');
        $state=json_encode(['schema_version'=>1,'phase'=>'running','source_workflow_id'=>15,
            'domain'=>'widgetscenario','amo_account_id'=>33098322,'recovery'=>['paused_workflow_ids'=>[11,12]]],JSON_THROW_ON_ERROR);
        file_put_contents($path,$state);
        $runner=$this->runner($path.'.report');
        $this->set($runner,'recurringStatePath',$path);
        try {
            try {
                (new \ReflectionMethod($runner,'beginRecurringState'))->invoke($runner);
                $this->fail('An unfinished checkpoint must block a new run');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('manual recovery',$error->getMessage());
            }
            $this->assertSame($state,file_get_contents($path));
            $this->assertFalse((new \ReflectionProperty($runner,'recurringStateStarted'))->getValue($runner));
        } finally { unlink($path); }
    }

    public function test_cleanup_does_not_mutate_crm_when_preparation_never_finished(): void
    {
        $runner=$this->runner('/unused');
        $client=$this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['requestV4'])->getMock();
        $client->expects($this->never())->method('requestV4');
        $this->set($runner,'client',$client);
        (new \ReflectionMethod($runner,'cleanup'))->invoke($runner);
        $this->assertSame([],((new \ReflectionProperty($runner,'report'))->getValue($runner))['cleanup']);
    }

    public function test_report_can_record_checkpoint_failure_without_retrying_broken_checkpoint_write(): void
    {
        $path=tempnam(sys_get_temp_dir(),'qa-report-');
        $runner=$this->runner($path);
        $this->set($runner,'recurringStateStarted',true);
        $this->set($runner,'recurringStatePath',sys_get_temp_dir()); // A directory cannot be replaced with a state file.
        $this->set($runner,'report',['cases'=>[],'fatal_error'=>'Checkpoint could not be finalized']);
        try {
            (new \ReflectionMethod($runner,'save'))->invoke($runner,false);
            $saved=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
            $this->assertSame('Checkpoint could not be finalized',$saved['fatal_error']);
            $this->assertSame(0600,fileperms($path)&0777);
        } finally { unlink($path); }
    }

    public function test_signal_swallowed_inside_a_node_still_aborts_the_suite_before_the_next_action(): void
    {
        $path=tempnam(sys_get_temp_dir(),'qa-cancelled-');
        $runner=$this->runner($path);
        $debugger=$this->createMock(WorkflowDebugger::class);
        $debugger->expects($this->once())->method('executeNode')->willReturnCallback(function() use($runner):array {
            $this->set($runner,'cancellationRequested',true);
            return ['status'=>'completed','results'=>[['status'=>'completed','output'=>[]]]];
        });
        $this->app->instance(WorkflowDebugger::class,$debugger);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('cancelled');
            (new \ReflectionMethod($runner,'node'))->invoke($runner,'cancelled','amocrm_query_leads',[]);
        } finally { unlink($path); }
    }

    public function test_unknown_session_status_is_not_a_successful_real_test(): void
    {
        $path=tempnam(sys_get_temp_dir(),'qa-incomplete-');
        $runner=$this->runner($path);
        $debugger=$this->createMock(WorkflowDebugger::class);
        $debugger->expects($this->once())->method('executeNode')->willReturn(['status'=>'ready','results'=>[]]);
        $this->app->instance(WorkflowDebugger::class,$debugger);
        try {
            (new \ReflectionMethod($runner,'node'))->invoke($runner,'incomplete','amocrm_query_leads',[]);
            $saved=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
            $this->assertSame('failed',$saved['cases'][0]['status']);
        } finally { unlink($path); }
    }

    public function test_bootstrap_deal_creation_does_not_implicitly_link_the_existing_contact(): void
    {
        Http::preventStrayRequests();
        Http::fake(['workflow-amo-contract.example/api/v4/leads'=>Http::response(['_embedded'=>['leads'=>[['id'=>101]]]],201)]);
        $runner=$this->runner('/unused');
        $this->set($runner,'pipelineId',10);
        $config=(new \ReflectionMethod($runner,'qaLeadCreateConfig'))->invoke($runner,'Clever QA recurring fixture');
        $account=(new Account)->forceFill(['id'=>1,'user_id'=>1,'endpoint'=>'https://workflow-amo-contract.example','access_token'=>'synthetic-only']);
        $executor=new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $client=(new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
        $context=new WorkflowContext(['lead'=>['id'=>0],'contact'=>['id'=>201]]);
        $result=(new \ReflectionMethod($executor,'createEntity'))->invoke($executor,$client,$account,'lead',$config,$context);
        $this->assertTrue($result['success']);
        $this->assertSame(101,$result['output']['entity_id']);
        Http::assertSentCount(1);
        Http::assertSent(fn($request)=>$request->method()==='POST' && parse_url($request->url(),PHP_URL_PATH)==='/api/v4/leads'
            && $request->data()[0]['status_id']===143);
    }

    public function test_report_write_failure_during_cleanup_does_not_prevent_workflow_restoration(): void
    {
        config(['database.default'=>'acceptance_lifecycle','database.connections.acceptance_lifecycle'=>['driver'=>'sqlite','database'=>':memory:']]);
        DB::purge('acceptance_lifecycle');
        Schema::create('workflows',function($table):void {
            $table->id(); $table->integer('user_id'); $table->boolean('is_active'); $table->timestamp('deleted_at')->nullable();
        });
        DB::table('workflows')->insert(['id'=>7,'user_id'=>142,'is_active'=>false]);
        $blocker=tempnam(sys_get_temp_dir(),'qa-not-a-directory-');
        $runner=$this->runner($blocker.'/report.json');
        $client=$this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['requestV4'])->getMock();
        $client->expects($this->exactly(3))->method('requestV4')->willReturnCallback(function($method,$path):array {
            $this->assertSame('GET',$method);
            $this->assertContains($path,['/api/v4/leads','/api/v4/webhooks']);
            return [];
        });
        foreach (['client'=>$client,'mutationsPrepared'=>true,'recurringStatePath'=>$blocker.'/state.json',
            'paused'=>[7],'workflowsPaused'=>true,'hookUrl'=>'https://example.invalid/qa-hook'] as $key=>$value) $this->set($runner,$key,$value);
        try {
            (new \ReflectionMethod($runner,'cleanup'))->invoke($runner);
            $this->assertSame(1,(int)DB::table('workflows')->where('id',7)->value('is_active'));
            $report=(new \ReflectionProperty($runner,'report'))->getValue($runner);
            $this->assertTrue($report['cleanup']['remove_qa_webhook']['ok']);
            $this->assertTrue($report['cleanup']['restore_workflow_activation']['ok']);
            $this->assertNotEmpty($report['report_write_errors']);
        } finally { unlink($blocker); }
    }
}
