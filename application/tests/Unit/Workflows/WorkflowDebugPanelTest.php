<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowDebugFixture;
use Tests\TestCase;

class WorkflowDebugPanelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        config(['cache.default' => 'array']);
        Http::preventStrayRequests();
    }

    public function test_steps_keep_server_side_context_and_stop_when_the_definition_changes(): void
    {
        $executor = $this->createMock(WorkflowAmoCrmActionExecutor::class);
        $executor->expects($this->once())->method('execute')->willReturn(['success' => true, 'output' => ['count' => 1]]);
        $this->app->instance(WorkflowAmoCrmActionExecutor::class, $executor);

        $page = Livewire::test(WorkflowDebugFixture::class)
            ->call('openWorkflowDebugger')->assertSet('debugOpen', true)
            ->call('startWorkflowDebug')->assertSet('debugState.real', false)
            ->assertSet('debugState.status', 'ready')->assertSet('debugState.next_id', 'fetch')
            ->call('nextWorkflowDebugStep')->assertSet('debugState.results.0.output.count', 1)
            ->call('nextWorkflowDebugStep')->assertSet('debugState.next_id', 'yes')
            ->assertSet('debugState.results.1.explanation.details.0.actual', '1')
            ->assertSet('debugState.results.1.explanation.details.0.expected', '0')
            ->assertSet('debugState.results.1.explanation.details.0.passed', true);

        $page->set('workflowActions.0.config.limit', 30)
            ->call('nextWorkflowDebugStep')->assertSet('debugState.status', 'stale');
        $this->assertCount(2, $page->get('debugState.results'));
        $page->call('closeWorkflowDebugger')->assertSet('debugOpen', false)
            ->call('selectWorkflowCanvasNode', 'fetch')->assertSet('editingActionId', 'fetch');
        Http::assertNothingSent();
    }

    public function test_real_execution_requires_confirmation_and_invalid_input_does_not_start_a_session(): void
    {
        Livewire::test(WorkflowDebugFixture::class)->set('debugReal', true)
            ->call('startWorkflowDebug')->assertSet('debugSessionId', null)
            ->set('debugReal', false)->set('debugInput', 'not json')
            ->call('startWorkflowDebug')->assertSet('debugSessionId', null);
        Http::assertNothingSent();
    }

    public function test_workspace_renders_dedicated_toolbar_side_inspector_and_path_without_changing_node_settings(): void
    {
        Livewire::test(WorkflowDebugFixture::class)->call('openWorkflowDebugger')
            ->assertSee('Результаты отладки')->assertSee('Управление отладкой')
            ->assertSee('Выполненные шаги')->assertSee('Проход ноды')
            ->assertSee('Открыть настройки ноды')->assertDontSee('workflow-debugger__stepbar', false)
            ->call('startWorkflowDebug')->call('closeWorkflowDebugger')->assertSet('debugOpen', false)
            ->call('openWorkflowDebugger')->assertSet('debugState.status', 'ready');
    }
}
