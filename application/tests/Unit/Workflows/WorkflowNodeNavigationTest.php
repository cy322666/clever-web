<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use Livewire\Livewire;
use Tests\Support\{WorkflowCanvasDatabase, WorkflowCanvasFixture, WorkflowDebugFixture};
use Tests\TestCase;

class WorkflowNodeNavigationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        config(['cache.default'=>'array']);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }

    private function linearPage()
    {
        $actions = [
            ['id'=>'a','name'=>'Первая','type'=>'workflow_delay','config'=>['seconds'=>1]],
            ['id'=>'b','name'=>'Вторая','type'=>'amocrm_add_note','config'=>['text'=>'До правки']],
            ['id'=>'c','name'=>'Третья','type'=>'workflow_delay','config'=>['seconds'=>2]],
        ];
        return Livewire::test(WorkflowCanvasFixture::class, ['workflowActions'=>$actions]);
    }

    public function test_arrows_follow_connections_save_drafts_and_do_not_execute_any_node(): void
    {
        $executor = $this->createMock(WorkflowAmoCrmActionExecutor::class);
        $executor->expects($this->never())->method('execute');
        $this->app->instance(WorkflowAmoCrmActionExecutor::class,$executor);
        $page = $this->linearPage()->call('openWorkflowActionEditor','a');
        $heading = $page->instance()->getMountedAction()->getModalHeading()->toHtml();
        $this->assertStringContainsString('Предыдущая нода', $heading);
        $this->assertStringContainsString('Следующая нода', $heading);
        $this->assertStringContainsString("navigateWorkflowNode('action:b')", html_entity_decode($heading));
        $this->assertStringNotContainsString('@js(', $heading);
        $page->set('mountedActions.0.data.seconds',7)
            ->call('navigateWorkflowNode','action:b')->assertHasNoErrors()
            ->assertSet('editingActionId','b')->assertSet('workflowActions.0.config.seconds',7)
            ->assertSet('mountedActions.0.data.text','До правки')
            ->set('mountedActions.0.data.text','После правки')
            ->call('navigateWorkflowNode','action:a')->assertHasNoErrors()
            ->assertSet('workflowActions.1.config.text','После правки')
            ->assertSet('mountedActions.0.data.seconds',7)->assertSet('nodeRunResults',[]);
        $this->assertCount(1,$page->get('mountedActions'));
        $this->assertSame([], $page->instance()->workflowNodeNavigation()['previous']);
        $page->call('navigateWorkflowNode','action:b')->call('navigateWorkflowNode','action:c')->assertSet('editingActionId','c');
        $this->assertSame([], $page->instance()->workflowNodeNavigation()['next']);
    }

    public function test_invalid_form_stays_open_without_losing_input(): void
    {
        $this->linearPage()->call('openWorkflowActionEditor','b')
            ->set('mountedActions.0.data.text','')
            ->call('navigateWorkflowNode','action:c')->assertHasErrors()
            ->assertSet('editingActionId','b')->assertSet('mountedActions.0.data.text','')
            ->assertSet('workflowActions.1.config.text','До правки');
    }

    public function test_navigation_rebuilds_the_schema_when_node_types_differ(): void
    {
        $page = $this->linearPage()->call('openWorkflowActionEditor','a');
        $component = $page->instance();
        $this->assertArrayHasKey('seconds', $component->getSchema('mountedActionSchema0')->getFlatFields());
        $component->navigateWorkflowNode('action:b');
        $fields = $component->getSchema('mountedActionSchema0')->getFlatFields();
        $this->assertArrayNotHasKey('seconds', $fields);
        $this->assertArrayHasKey('text', $fields);
        $component->navigateWorkflowNode('action:c');
        $this->assertArrayHasKey('seconds', $component->getSchema('mountedActionSchema0')->getFlatFields());
    }

    public function test_forks_offer_each_branch_and_unconnected_targets_are_rejected(): void
    {
        $page = Livewire::test(WorkflowDebugFixture::class)->call('openWorkflowActionEditor','if');
        $navigation = $page->instance()->workflowNodeNavigation();
        $this->assertSame(['action:yes','action:no'],array_column($navigation['next'],'id'));
        $this->assertSame(['Да · Сделки найдены','Нет · Сделок нет'],array_column($navigation['next'],'label'));
        $this->assertSame(['action:fetch'],array_column($navigation['previous'],'id'));
        $page->call('navigateWorkflowNode','action:missing')->assertSet('editingActionId','if');
        $page->call('navigateWorkflowNode','action:no')->assertHasNoErrors()->assertSet('editingActionId','no');
        $page->call('navigateWorkflowNode','action:yes')->assertSet('editingActionId','no');
        $page->call('navigateWorkflowNode','action:if')->assertSet('editingActionId','if');
        $this->assertCount(1,$page->get('mountedActions'));
    }

    public function test_canvas_play_preserves_an_open_form_and_returns_errors_without_opening_one(): void
    {
        $executor = $this->createMock(WorkflowAmoCrmActionExecutor::class);
        $executor->expects($this->never())->method('execute');
        $this->app->instance(WorkflowAmoCrmActionExecutor::class,$executor);
        $page = $this->linearPage()->call('openWorkflowActionEditor','b')->set('mountedActions.0.data.text','Черновик');
        $mounted = $page->get('mountedActions');
        $page->set('debugInput','broken json')->call('runWorkflowCanvasNode','a')
            ->assertSet('nodeRunResults.a.status','error')->assertSet('editingActionId','b');
        $this->assertSame($mounted,$page->get('mountedActions'));
        $page->call('unmountAction')->call('runWorkflowCanvasNode','a')->assertSet('mountedActions',[]);
    }
}
