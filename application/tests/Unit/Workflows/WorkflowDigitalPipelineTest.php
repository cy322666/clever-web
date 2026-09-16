<?php

namespace Tests\Unit\Workflows;

use App\Http\Controllers\Api\WorkflowManualAmoCrmController;
use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\Billing\WidgetSubscriptionAccessService;
use App\Services\Workflows\WorkflowManualAmoCrmRunService;
use App\Services\Workflows\WorkflowTransfer;
use App\Workflows\Triggers\DigitalPipelineTrigger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Leek\FilamentWorkflows\Engine\WorkflowExecutor;
use Leek\FilamentWorkflows\Enums\TriggerType;
use Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob;
use Leek\FilamentWorkflows\Models\WorkflowRun;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasFixture;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowDigitalPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); WorkflowListDatabase::prepare(); Http::preventStrayRequests(); Bus::fake();
        DB::table('accounts')->insert(['id'=>1,'user_id'=>1,'widget'=>'workflows','subdomain'=>'dp-test','active'=>true,'refresh_token'=>'test']);
    }

    private function seedFlow(int $id, string $type, array $starts = [], int $owner = 1, bool $active = true): void
    {
        DB::table('workflows')->insert(['id'=>$id,'user_id'=>$owner,'name'=>'Поток '.$id,'trigger_type'=>'manual','is_active'=>$active,
            'definition'=>json_encode(['trigger'=>['type'=>$type,'config'=>[]],'additional_triggers'=>$starts,'actions'=>[]])]);
    }

    private function access(): WidgetSubscriptionAccessService
    {
        $access = $this->createMock(WidgetSubscriptionAccessService::class);
        $access->method('canUse')->willReturn(true);
        return $access;
    }

    public function test_dp_list_contains_only_active_owned_flows_with_primary_or_additional_dp_start(): void
    {
        $this->seedFlow(1,'manual'); $this->seedFlow(2,'digital-pipeline');
        $this->seedFlow(3,'manual',[['id'=>'trigger:dp','type'=>'digital-pipeline','config'=>[]]]);
        $this->seedFlow(4,'digital-pipeline',owner:2); $this->seedFlow(5,'digital-pipeline',active:false); $this->seedFlow(6,'schedule');
        $controller = new WorkflowManualAmoCrmController;
        foreach (['digital-pipeline'=>[2,3], 'manual'=>[1,3]] as $source=>$ids) {
            $response = $controller->index(Request::create('/test','GET',['subdomain'=>'dp-test','source'=>$source]),$this->access());
            $this->assertSame($ids,array_column($response->getData(true)['workflows'],'id'));
        }
        $this->assertSame([], $controller->index(Request::create('/test','GET',['subdomain'=>'dp-test','lead_id'=>42]),$this->access())->getData(true)['workflows']);
    }

    public function test_button_selector_and_run_exclude_manual_and_dp_flows(): void
    {
        $this->seedFlow(1,'manual'); $this->seedFlow(2,'digital-pipeline'); $this->seedFlow(3,'amo-button');
        $this->seedFlow(4,'manual',[['id'=>'trigger:button','type'=>'amo-button','config'=>[]]]);
        $this->seedFlow(5,'amo-button',owner:2); $this->seedFlow(6,'amo-button',active:false);
        $controller = new WorkflowManualAmoCrmController;
        $response = $controller->index(Request::create('/test','GET',['subdomain'=>'dp-test','source'=>'amo-button']),$this->access());
        $this->assertSame([3,4],array_column($response->getData(true)['workflows'],'id'));
        $card = $controller->index(Request::create('/test','GET',['subdomain'=>'dp-test','lead_id'=>42]),$this->access());
        $this->assertSame([3,4],array_column($card->getData(true)['workflows'],'id'));
        $runs = $this->createMock(WorkflowManualAmoCrmRunService::class);
        $runs->expects($this->never())->method('startForLead');
        $runs->expects($this->once())->method('startButtonForLead')->willReturn(['run_id'=>9,'run_ulid'=>'test']);
        foreach ([1,2,3,5,6] as $id) {
            $response = $controller->run(Request::create('/test','POST',['subdomain'=>'dp-test','workflow_id'=>$id,'lead_id'=>42]),$this->access(),$runs);
            $this->assertSame($id===3 ? 202 : 404,$response->status());
        }
    }

    public function test_dp_callback_rejects_manual_and_cross_account_flows(): void
    {
        $this->seedFlow(1,'manual'); $this->seedFlow(2,'digital-pipeline',owner:2); $this->seedFlow(3,'digital-pipeline',active:false);
        $runs = $this->createMock(WorkflowManualAmoCrmRunService::class);
        $runs->expects($this->never())->method('startDigitalPipelineForLead');
        $runs->expects($this->never())->method('startForLead');
        foreach ([1,2,3] as $id) {
            $response = (new WorkflowManualAmoCrmController)->digitalPipeline(Request::create('/test','POST',[
                'subdomain'=>'dp-test','workflow_id'=>$id,'lead_id'=>42,
            ]),$this->access(),$runs);
            $this->assertFalse($response->getData(true)['ok']);
        }
        Bus::assertNothingDispatched();
    }

    public function test_callback_uses_dp_service_and_never_manual_entry_point(): void
    {
        $this->seedFlow(2,'digital-pipeline');
        $runs = $this->createMock(WorkflowManualAmoCrmRunService::class);
        $runs->expects($this->never())->method('startForLead');
        $runs->expects($this->once())->method('startDigitalPipelineForLead')
            ->with($this->callback(fn($flow)=>$flow->id===2),$this->callback(fn($account)=>$account->id===1),42,$this->callback(fn($input)=>$input['pipeline_id']===10 && $input['status_id']===20))
            ->willReturn(['run_id'=>9,'run_ulid'=>'test']);
        $response = (new WorkflowManualAmoCrmController)->digitalPipeline(Request::create('/test','POST',[
            'account'=>['subdomain'=>'dp-test'],'settings'=>['workflow_id'=>2],'event'=>['data'=>['id'=>42,'pipeline_id'=>10,'status_id'=>20]],
        ]),$this->access(),$runs);
        $this->assertTrue($response->getData(true)['queued']);
    }

    public function test_only_dp_starts_are_dispatched_with_webhook_context(): void
    {
        $workflow = (new Workflow)->forceFill(['id'=>1,'user_id'=>1,'definition'=>[
            'trigger'=>['type'=>'manual','config'=>[]], 'additional_triggers'=>[
                ['id'=>'trigger:dp','type'=>'digital-pipeline','config'=>[]], ['id'=>'trigger:timer','type'=>'schedule','config'=>[]],
            ],
        ]]);
        $run = $this->getMockBuilder(WorkflowRun::class)->onlyMethods(['update'])->getMock();
        $run->forceFill(['id'=>9]);
        $run->expects($this->once())->method('update')->with($this->callback(function($data) {
            $trigger = $data['context_data']['trigger_data'];
            return $trigger['_workflow_start_node_id']==='trigger:dp' && $trigger['event']==='digital-pipeline'
                && !$trigger['is_manual'] && $trigger['lead']['id']===42;
        }))->willReturn(true);
        $executor = $this->createMock(WorkflowExecutor::class);
        $executor->expects($this->once())->method('start')->with($workflow,null,TriggerType::WEBHOOK,1)->willReturn($run);
        (new WorkflowManualAmoCrmRunService($executor))->startDigitalPipelineForLead($workflow,(new Account)->forceFill(['user_id'=>1]),42);
        Bus::assertDispatchedTimes(ExecuteWorkflowJob::class,1);
    }

    public function test_dp_trigger_can_be_selected_and_transferred_without_configuration_modal(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)->call('beginTriggerAdd')->call('selectTriggerType','digital-pipeline')->assertSet('mountedActions',[]);
        $this->assertSame('digital-pipeline',$page->get('definition')['additional_triggers'][0]['type']);
        $this->assertFalse((new DigitalPipelineTrigger)->shouldTrigger([],null,['is_manual'=>true]));
        $this->actingAs(\App\Models\User::findOrFail(1));
        $flow = WorkflowTransfer::import(WorkflowTransfer::encode('DP',['trigger'=>['type'=>'digital-pipeline','config'=>[]],'actions'=>[]]));
        $this->assertSame(TriggerType::WEBHOOK,$flow->trigger_type);
        $this->assertFalse($flow->is_active);
    }
}
