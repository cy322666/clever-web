<?php

namespace Tests\Unit\Workflows;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\EditWorkflow;
use App\Models\User;
use App\Models\Workflows\Workflow;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\WorkflowBrowserDatabase;
use Tests\TestCase;

class WorkflowLocalInspectorSaveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowBrowserDatabase::prepare();
        \Illuminate\Support\Facades\Schema::table('workflow_runs',fn($table)=>$table->json('context_data')->nullable());
        $this->actingAs(User::findOrFail(1));
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function flow(string $type='http_request'): Workflow
    {
        return Workflow::create(['user_id'=>1,'name'=>'Local forms','is_active'=>false,'failure_strategy'=>'stop',
            'definition'=>['trigger'=>['type'=>'manual','config'=>[]],'actions'=>[
                ['id'=>'a','type'=>$type,'name'=>'Нода','config'=>$type==='http_request'
                    ? ['method'=>'GET','url'=>'https://example.test','headers'=>'{"Authorization":"test-only"}','vendor_config'=>['keep'=>true]]
                    : ['chat_id'=>'123','text'=>'Test','parse_mode'=>'plain']],
            ],'connections'=>[['sourceId'=>'trigger','sourcePort'=>'output','targetId'=>'action:a']],'vendor_root'=>'keep']]);
    }

    public function test_save_validates_the_browser_draft_and_keeps_unknown_keys_and_credentials(): void
    {
        $flow=$this->flow();
        $page=Livewire::test(EditWorkflow::class,['record'=>$flow->id])->assertSet('replayRecordedOnly',false);
        $actions=$page->get('workflowActions');$actions[0]['config']['url']='https://updated.test';
        $page->set('workflowActions',$actions)->set('workflowLocalInspectorDirty',['action:a'=>true])->call('save')->assertHasNoErrors()->assertSet('workflowLocalInspectorDirty',[]);
        $definition=$flow->fresh()->definition;
        $this->assertSame('https://updated.test',$definition['actions'][0]['config']['url']);
        $this->assertSame('{"Authorization":"test-only"}',$definition['actions'][0]['config']['headers']);
        $this->assertSame(['keep'=>true],$definition['actions'][0]['config']['vendor_config']);
        $this->assertSame('keep',$definition['vendor_root']);
        Http::assertNothingSent();Queue::assertNothingPushed();
    }

    public function test_invalid_save_keeps_the_draft_and_sends_field_errors_to_the_browser(): void
    {
        $flow=$this->flow();
        $page=Livewire::test(EditWorkflow::class,['record'=>$flow->id]);
        $actions=$page->get('workflowActions');$actions[0]['config']['url']='';
        $page->set('workflowActions',$actions)->set('workflowLocalInspectorDirty',['action:a'=>true])->call('save')
            ->assertDispatched('workflow-local-inspector-validation',fn($event,$data)=>$data['nodeId']==='action:a' && isset($data['errors']['url']))
            ->assertSet('workflowActions.0.config.url','')->assertSet('mountedActions',[]);
        $this->assertSame('https://example.test',$flow->fresh()->definition['actions'][0]['config']['url']);
        Http::assertNothingSent();Queue::assertNothingPushed();
    }

    public function test_creating_a_dictionary_option_reuses_the_existing_credentials_dialog(): void
    {
        $flow=$this->flow('telegram_send_message');
        $page=Livewire::test(EditWorkflow::class,['record'=>$flow->id])->call('openWorkflowInspectorCreateOption','action:a')
            ->assertSet('mountedActions.1.name','createOption');
        $this->assertSame('Подключить Telegram',$page->instance()->getMountedAction()->getModalHeading());
        Http::assertNothingSent();Queue::assertNothingPushed();
    }
}
