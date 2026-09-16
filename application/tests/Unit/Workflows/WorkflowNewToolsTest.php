<?php
namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowGraph;
use App\Workflows\Engine\WorkflowDebugger;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowCanvasFixture;
use Tests\TestCase;

class WorkflowNewToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); WorkflowCanvasDatabase::prepare(); Http::preventStrayRequests();
    }
    public function test_new_tools_are_registered_and_have_working_editor_forms(): void
    {
        foreach (['workflow_filter_list','http_request','amocrm_contact_leads','amocrm_create_lead'] as $type) {
            $page = Livewire::test(WorkflowCanvasFixture::class)->call('openDetachedActionPalette')->call('selectActionType',$type);
            $nodes = WorkflowGraph::nodes($page->get('workflowActions'));
            $last = end($nodes)['step'];
            $this->assertSame($type,$last['type']);
            $page->call('openWorkflowActionEditor',$last['id'])->assertStatus(200)->assertHasNoErrors();
        }
    }
    public function test_filter_feeds_count_to_the_condition_and_runs_only_the_correct_branch(): void
    {
        $definition = ['trigger'=>['type'=>'manual'],'actions'=>[
            ['id'=>'filter','type'=>'workflow_filter_list','config'=>['items'=>'{{ $json.items }}','rules'=>[['field'=>'status_id','operator'=>'eq','value'=>'20']]]],
            ['id'=>'if','type'=>'control-condition','config'=>['conditions'=>[['left'=>'{{ $json.count }}','operator'=>'gt','right'=>'0']]]],
            ['id'=>'yes','type'=>'http_request','config'=>['method'=>'POST','url'=>'https://public.example']],
            ['id'=>'no','type'=>'http_request','config'=>['method'=>'POST','url'=>'https://public.example']],
        ],'connections'=>[
            ['sourceId'=>'trigger','sourcePort'=>'output','targetId'=>'action:filter'],
            ['sourceId'=>'action:filter','sourcePort'=>'output','targetId'=>'action:if'],
            ['sourceId'=>'action:if','sourcePort'=>'yes','targetId'=>'action:yes'],
            ['sourceId'=>'action:if','sourcePort'=>'no','targetId'=>'action:no'],
        ]];
        Http::fake();
        foreach ([[['id'=>1,'status_id'=>21],['id'=>2,'status_id'=>20]], []] as $items) {
            $debugger = app(WorkflowDebugger::class);
            $session = $debugger->start($definition,['items'=>$items],null,null);
            for ($i=0; $i<5 && $session['status']==='ready'; $i++) $session=$debugger->advance($session);
            $this->assertSame('completed',$session['status']);
            $this->assertSame(['filter','if',$items ? 'yes' : 'no'],array_column($session['results'],'id'));
        }
        Http::assertNothingSent();
    }
}
