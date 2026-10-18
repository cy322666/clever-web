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
            ->assertSet('debugState.results.1.explanation.details.0.passed', true)
            ->assertSet('debugState.results.1.display_input.count', 1)
            ->assertSet('debugState.results.1.display_output', 'Ветка «Да»')
            ->assertSet('debugState.start_node_id', 'trigger');

        $this->assertArrayNotHasKey('input', $page->get('debugState.results.1'));
        $this->assertArrayNotHasKey('resolved_input', $page->get('debugState.results.1'));

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

    public function test_empty_query_result_keeps_its_count_visible_in_live_debug(): void
    {
        $executor = $this->createMock(WorkflowAmoCrmActionExecutor::class);
        $executor->expects($this->once())->method('execute')->willReturn(['success' => true, 'output' => [
            'items' => [], 'count' => 0, 'has_more' => false,
            'amo_exchange' => [['response' => ['body' => []]]],
        ]]);
        $this->app->instance(WorkflowAmoCrmActionExecutor::class, $executor);

        Livewire::test(WorkflowDebugFixture::class)
            ->call('startWorkflowDebug')->call('nextWorkflowDebugStep')
            ->assertSet('debugState.results.0.display_output.count', 0)
            ->assertSet('debugState.results.0.display_output.items', []);
    }

    public function test_debug_trigger_shows_only_its_business_body(): void
    {
        $page = Livewire::test(WorkflowDebugFixture::class)
            ->set('debugInput', json_encode(['body' => ['id' => 77, 'name' => 'Заявка'],
                'method' => 'POST', 'headers' => ['authorization' => 'private']]))
            ->call('startWorkflowDebug')->assertSet('debugState.start_node_id', 'trigger')
            ->assertSet('debugState.display_trigger_data.id', 77)
            ->assertSet('debugState.display_trigger_data.name', 'Заявка');

        $this->assertArrayNotHasKey('headers', $page->get('debugState.display_trigger_data'));
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
