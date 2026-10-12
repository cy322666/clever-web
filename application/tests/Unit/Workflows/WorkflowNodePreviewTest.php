<?php

namespace Tests\Unit\Workflows;

use App\Jobs\Workflows\RunNodePreview;
use App\Models\User;
use App\Services\Workflows\WorkflowNodePreview;
use App\Workflows\Engine\WorkflowDebugger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Auth, Cache, DB, Http, Queue, Schema};
use Livewire\Livewire;
use Tests\Support\{WorkflowCanvasDatabase, WorkflowCanvasFixture};
use Tests\TestCase;

class WorkflowNodePreviewTest extends TestCase
{
    private WorkflowNodePreview $previews;

    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        config(['cache.default' => 'array', 'filament-workflows.queue.connection' => 'redis']);
        Cache::flush();
        (require database_path('migrations/2026_06_09_160732_create_workflows_table.php'))->up();
        (require database_path('migrations/2026_06_13_130000_add_group_name_to_workflows_table.php'))->up();
        (require database_path('migrations/2026_09_11_160000_create_workflow_folders_table.php'))->up();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->boolean('active');
        });
        DB::table('users')->insert(['id' => 7, 'name' => 'Test', 'email' => 'test@example.invalid', 'active' => true]);
        Schema::create('amocrm_fields', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('entity_type');
            $table->integer('field_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('active');
        });
        $this->actingAs(User::findOrFail(7));
        Queue::fake();
        Http::preventStrayRequests();
        $this->previews = app(WorkflowNodePreview::class);
    }

    private function definition(): array
    {
        return ['trigger' => ['type' => 'manual'], 'actions' => [
            ['id' => 'loop', 'name' => 'Каждый заказ', 'type' => 'workflow_loop', 'config' => ['items' => [['id' => 1], ['id' => 2]], 'mode' => 'all']],
            ['id' => 'assign', 'name' => 'Запомнить ID', 'type' => 'workflow_set_variable', 'config' => ['assignments' => [['name' => 'order_id', 'value' => '{{ $json.id }}']]]],
        ], 'connections' => [
            ['sourceId' => 'trigger', 'sourcePort' => 'output', 'targetId' => 'action:loop'],
            ['sourceId' => 'action:loop', 'sourcePort' => 'each', 'targetId' => 'action:assign'],
            ['sourceId' => 'action:assign', 'sourcePort' => 'output', 'targetId' => 'action:loop'],
        ]];
    }

    private function start(string $editor = 'test'): string
    {
        $definition = $this->definition();
        return $this->previews->start(7, $editor, $definition['actions'][0], [], [], null, $definition);
    }

    public function test_canvas_and_modal_enqueue_once_return_immediately_and_poll_all_selected_items(): void
    {
        foreach (['canvas', 'modal'] as $button) {
            Queue::fake();
            $definition = $this->definition();
            $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => $definition['actions']])->set('definition', $definition);
            if ($button === 'modal') $page->call('openWorkflowActionEditor', 'loop')->call('runEditingWorkflowNode');
            else $page->call('runWorkflowCanvasNode', 'loop');
            $page->assertHasNoErrors()->assertSet('nodePreviewProgress.status', 'queued');
            $this->assertStringContainsString('Ожидание обработчика', json_encode($page->effects, JSON_UNESCAPED_UNICODE));
            $this->assertStringContainsString('wire:poll.2s=', $page->html());
            $this->assertArrayNotHasKey('assign', $page->get('nodeRunResults'));
            $id = $page->get('nodePreviewTaskId');
            $this->assertNotNull($id);
            $page->call('runWorkflowCanvasNode', 'loop')->call('runWorkflowCanvasNode', 'assign');
            Queue::assertPushed(RunNodePreview::class, 1);
            $job = Queue::pushed(RunNodePreview::class)->first();
            $this->assertSame(1, $job->tries);
            $this->assertTrue($job->failOnTimeout);
            $this->assertSame('workflows', $job->queue);
            $job->handle($this->previews);
            $state = $this->previews->status(7, $id);
            $this->assertSame('completed', $state['status'], $state['error'] ?? '');
            $this->assertSame(3, $state['sequence']);
            $this->assertSame('Запомнить ID', $state['current_node']);
            $page->call('pollWorkflowNodePreview')->assertSet('nodePreviewTaskId', null)
                ->assertDispatched('workflow-debug-updated');
            $this->assertSame(['id' => 2], \App\Services\Workflows\WorkflowOutputView::value($page->get('nodeRunResults')['loop']['output']));
            $this->assertSame(2, $page->get('nodeRunResults')['loop']['display_count']);
            $this->assertSame(2, $page->get('nodeRunResults')['assign']['display_count']);
            $this->assertArrayHasKey('assign', $page->get('nodeRunResults'));
            $job->handle($this->previews);
            $this->assertSame($state, $this->previews->status(7, $id));
        }
        Http::assertNothingSent();
    }

    public function test_cancellation_before_queue_delivery_runs_nothing_and_is_owner_scoped(): void
    {
        $id = $this->start();
        $this->assertSame($id, $this->start());
        $this->assertNull($this->previews->status(8, $id));
        $this->previews->cancel(8, $id);
        $this->assertSame('queued', $this->previews->status(7, $id)['status']);
        $this->previews->cancel(7, $id);
        $engine = $this->createMock(WorkflowDebugger::class);
        $engine->expects($this->never())->method('executeNode');
        $this->app->instance(WorkflowDebugger::class, $engine);
        $this->previews->execute(7, $id);
        $this->assertSame('cancelled', $this->previews->status(7, $id)['status']);
        $this->assertNull($this->previews->result(7, $id));
        $this->previews->acknowledge(7, $id);
        $this->assertNull($this->previews->activeId(7, 'test'));
        Http::assertNothingSent();
    }

    public function test_running_progress_and_cancellation_stop_before_the_next_action(): void
    {
        $id = $this->start();
        $engine = $this->createMock(WorkflowDebugger::class);
        $engine->expects($this->once())->method('executeNode')->willReturnCallback(function ($step, $context, $input, $workflowId, $userId, $real, $definition, $beforeStep) use ($id): array {
            $this->assertSame(7, Auth::id());
            $beforeStep(['id' => 'one', 'name' => 'Первое действие'], 1, []);
            $this->assertSame('Первое действие', $this->previews->status(7, $id)['current_node']);
            $this->previews->cancel(7, $id);
            $this->assertTrue($this->previews->status(7, $id)['stopping']);
            try { $beforeStep(['id' => 'two'], 2, []); }
            catch (\RuntimeException $error) { return ['status' => 'failed', 'error' => $error->getMessage(), 'results' => [], 'context' => []]; }
            $this->fail('Executed the next action after cancellation');
        });
        $this->app->instance(WorkflowDebugger::class, $engine);
        $otherUser = (new User)->forceFill(['id' => 8]);
        Auth::guard()->setUser($otherUser);
        $this->previews->execute(7, $id);
        $this->assertSame(8, Auth::id(), 'Worker identity must be restored');
        $this->assertSame('cancelled', $this->previews->status(7, $id)['status']);
        $this->assertNotNull($this->previews->result(7, $id));
    }

    public function test_expired_queue_and_failed_worker_are_terminal_and_never_replayed(): void
    {
        $id = $this->start();
        $this->travel(121)->seconds();
        $this->assertSame('failed', $this->previews->status(7, $id)['status']);
        $engine = $this->createMock(WorkflowDebugger::class);
        $engine->expects($this->never())->method('executeNode');
        $this->app->instance(WorkflowDebugger::class, $engine);
        $this->previews->execute(7, $id);
        $this->assertNull($this->previews->result(7, $id));
        $second = $this->start('second');
        (new RunNodePreview(7, $second))->failed(new \RuntimeException('timeout'));
        $this->previews->execute(7, $second);
        $this->assertSame('failed', $this->previews->status(7, $second)['status']);
        $this->assertNull($this->previews->result(7, $second));
    }

    public function test_inactive_actor_and_sync_connection_cannot_run_background_previews(): void
    {
        $id = $this->start();
        DB::table('users')->where('id', 7)->update(['active' => false]);
        $this->previews->execute(7, $id);
        $this->assertSame('failed', $this->previews->status(7, $id)['status']);
        config(['filament-workflows.queue.connection' => 'sync']);
        $this->expectExceptionMessage('фоновая очередь');
        $this->start('sync');
    }

    public function test_other_tenants_workflow_is_not_executed_by_the_worker(): void
    {
        DB::table('users')->insert(['id' => 8, 'name' => 'Other', 'email' => 'other@example.invalid', 'active' => true]);
        DB::table('workflows')->insert(['id' => 1, 'user_id' => 8, 'name' => 'Private', 'trigger_type' => 'manual', 'definition' => json_encode($this->definition()), 'is_active' => false]);
        $definition = $this->definition();
        $id = $this->previews->start(7, 'other-workflow', $definition['actions'][0], [], [], 1, $definition);
        $engine = $this->createMock(WorkflowDebugger::class);
        $engine->expects($this->never())->method('executeNode');
        $this->app->instance(WorkflowDebugger::class, $engine);
        $this->previews->execute(7, $id);
        $this->assertSame('failed', $this->previews->status(7, $id)['status']);
        $this->assertStringContainsString('недоступен', $this->previews->status(7, $id)['error']);
    }

    public function test_watchdog_failure_cannot_be_overwritten_by_a_late_success(): void
    {
        $id = $this->start();
        $engine = $this->createMock(WorkflowDebugger::class);
        $engine->expects($this->once())->method('executeNode')->willReturnCallback(function ($step, $context, $input, $workflowId, $userId, $real, $definition, $beforeStep) use ($id): array {
            $beforeStep(['id' => 'one'], 1, []);
            $this->travel(151)->seconds();
            $this->assertSame('failed', $this->previews->status(7, $id)['status']);
            return ['status' => 'completed', 'error' => null, 'results' => [], 'context' => []];
        });
        $this->app->instance(WorkflowDebugger::class, $engine);
        $this->previews->execute(7, $id);
        $this->assertSame('failed', $this->previews->status(7, $id)['status']);
        $this->assertNull($this->previews->result(7, $id));
        $this->previews->execute(7, $id);
    }
}
