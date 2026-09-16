<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Workflows\Actions\TelegramSendMessageAction;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowDebugFixture;
use Tests\Support\WorkflowCanvasFixture;
use Tests\TestCase;

class WorkflowCanvasRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        config(['cache.default' => 'array']);
        Http::preventStrayRequests();
    }

    public function test_play_runs_only_selected_node_and_next_play_reuses_its_context(): void
    {
        $executor = $this->createMock(WorkflowAmoCrmActionExecutor::class);
        $executor->expects($this->once())->method('execute')->willReturnCallback(function ($type, $config, $context): array {
            $this->assertFalse($context->getVariable('_dry_run'));
            $this->assertFalse($context->getVariable('_test_mode'));
            return ['success' => true, 'output' => ['count' => 2]];
        });
        $this->app->instance(WorkflowAmoCrmActionExecutor::class, $executor);
        $page = Livewire::test(WorkflowDebugFixture::class)
            ->assertSee('Выполнить ноду: Запрос сделок')
            ->call('runWorkflowCanvasNode', 'fetch')->assertHasNoErrors()
            ->assertSet('nodeRunResults.fetch.output.count', 2)
            ->assertSet('editingActionId', 'fetch');
        $this->assertCount(1, $page->get('nodeRunResults'));
        $page->call('unmountAction')->call('runWorkflowCanvasNode', 'if')->assertHasNoErrors()
            ->assertSet('nodeRunResults.if.output.passed', true);
        $this->assertCount(2, $page->get('nodeRunResults'));
        $this->assertArrayNotHasKey('yes', $page->get('nodeRunResults'));
        $page->assertDispatched('workflow-debug-updated');
        Http::assertNothingSent();
    }

    public function test_node_play_does_not_depend_on_whole_flow_confirmation(): void
    {
        $executor = $this->createMock(WorkflowAmoCrmActionExecutor::class);
        $executor->expects($this->once())->method('execute')->willReturnCallback(function ($type, $config, $context): array {
            $this->assertFalse($context->getVariable('_dry_run'));
            $this->assertFalse($context->getVariable('_test_mode'));
            return ['success' => true, 'output' => ['count' => 1]];
        });
        $this->app->instance(WorkflowAmoCrmActionExecutor::class, $executor);
        Livewire::test(WorkflowDebugFixture::class)->set('debugReal', true)
            ->assertSet('debugRealConfirmed', false)
            ->call('runWorkflowCanvasNode', 'fetch')->assertHasNoErrors()
            ->assertSet('debugOpen', false)->assertSet('nodeRunResults.fetch.status', 'completed');
        Http::assertNothingSent();
    }

    public function test_unknown_node_is_rejected(): void
    {
        $executor = $this->createMock(WorkflowAmoCrmActionExecutor::class);
        $executor->expects($this->never())->method('execute');
        $this->app->instance(WorkflowAmoCrmActionExecutor::class, $executor);
        Livewire::test(WorkflowDebugFixture::class)->call('runWorkflowCanvasNode', 'unknown')->assertStatus(404);
        Http::assertNothingSent();
    }

    public function test_both_node_buttons_send_real_requests_even_with_whole_flow_dry_run_selected(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok'=>true, 'result'=>['message_id'=>7]])]);
        $step = ['id'=>'telegram', 'type'=>'telegram_send_message', 'config'=>TelegramSendMessageAction::protectConfig([
            'bot_token'=>'12345:test-token', 'chat_id'=>'-1001', 'text'=>'Тест', 'parse_mode'=>'plain',
        ])];
        foreach (['canvas', 'modal'] as $button) {
            $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions'=>[$step]])
                ->assertSet('debugReal', false)->assertSet('debugRealConfirmed', false);
            if ($button === 'canvas') $page->call('runWorkflowCanvasNode', 'telegram');
            else $page->call('openWorkflowActionEditor', 'telegram')->call('runEditingWorkflowNode');
            $page->assertHasNoErrors()->assertSet('nodeRunResults.telegram.status', 'completed')
                ->assertSet('nodeRunResults.telegram.output.message_id', 7)
                ->assertSet('debugReal', false);
        }
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '-1001' && $request['text'] === 'Тест');
    }
}
