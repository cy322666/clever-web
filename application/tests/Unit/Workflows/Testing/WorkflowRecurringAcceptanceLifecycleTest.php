<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows\Testing;

use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\amoCRM\Client;
use App\Services\Workflows\Testing\WorkflowLiveAcceptance;
use App\Workflows\Engine\WorkflowDebugger;
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
}
