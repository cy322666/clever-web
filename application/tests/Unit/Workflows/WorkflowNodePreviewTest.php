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

    public function test_canvas_and_modal_run_only_the_loop_then_the_next_manual_node_receives_one_item(): void
    {
        foreach ([['canvas', 'all'], ['canvas', 'once'], ['canvas', 'limit'], ['modal', 'all'], ['modal', 'once'], ['modal', 'limit']] as [$button, $mode]) {
            Queue::fake();
            $definition = $this->definition();
            $definition['actions'][0]['config']['mode'] = $mode;
            if ($mode === 'limit') $definition['actions'][0]['config']['limit'] = 2;
            $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => $definition['actions']])->set('definition', $definition);
            if ($button === 'modal') $page->call('openWorkflowInspector', 'action:loop')->call('runEditingWorkflowNode');
            else $page->call('runWorkflowCanvasNode', 'loop');
            $loop = $page->get('nodeRunResults')['loop'];
            $this->assertSame('completed', $loop['status'], $button.' '.$mode.': '.($loop['error'] ?? ''));
            $page->assertHasNoErrors()->assertSet('nodePreviewTaskId', null)
                ->assertSet('nodePreviewProgress', [])
                ->assertSet('nodeRunResults.loop.output.item.id', 1)->assertSet('nodeRunResults.loop.display_count', 1)
                ->assertDispatched('workflow-debug-updated');
            $this->assertSame(['loop'], array_keys($page->get('nodeRunResults')));
            $this->assertSame($mode, $page->get('workflowActions')[0]['config']['mode']);
            if ($mode === 'limit') $this->assertSame(2, $page->get('workflowActions')[0]['config']['limit']);
            Queue::assertNothingPushed();

            if ($button === 'modal') $page->call('openWorkflowInspector', 'action:assign')->call('runEditingWorkflowNode');
            else $page->call('runWorkflowCanvasNode', 'assign');
            $page->assertHasNoErrors();
            $assign = $page->get('nodeRunResults')['assign'];
            $this->assertSame('completed', $assign['status'], $button.' '.$mode.': '.($assign['error'] ?? ''));
            $this->assertSame(1, $page->get('nodeRunResults')['assign']['resolved_input']['assignments'][0]['value']);
            $this->assertSame(['loop', 'assign'], array_keys($page->get('nodeRunResults')));
            $this->assertSame(1, $page->get('nodeRunResults')['loop']['display_count']);
            Queue::assertNothingPushed();
        }
        Http::assertNothingSent();
    }

    public function test_loop_node_play_never_calls_external_body_or_done_actions(): void
    {
        $executor = $this->createMock(\App\Services\Workflows\WorkflowAmoCrmActionExecutor::class);
        $executor->expects($this->never())->method('execute');
        $this->app->instance(\App\Services\Workflows\WorkflowAmoCrmActionExecutor::class, $executor);
        foreach (['canvas', 'modal'] as $button) {
            $definition = $this->definition();
            $definition['actions'][1] = ['id' => 'assign', 'type' => 'amocrm_create_contact', 'config' => ['name' => 'Must not create']];
            $definition['actions'][] = ['id' => 'after', 'type' => 'http_request', 'config' => ['url' => 'https://example.invalid/write', 'method' => 'POST']];
            $definition['connections'][] = ['sourceId' => 'action:loop', 'sourcePort' => 'done', 'targetId' => 'action:after'];
            $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => $definition['actions']])->set('definition', $definition);
            if ($button === 'modal') $page->call('openWorkflowActionEditor', 'loop')->call('runEditingWorkflowNode');
            else $page->call('runWorkflowCanvasNode', 'loop');
            $page->assertHasNoErrors()->assertSet('nodeRunResults.loop.status', 'completed');
            $this->assertArrayNotHasKey('assign', $page->get('nodeRunResults'));
            $this->assertArrayNotHasKey('after', $page->get('nodeRunResults'));
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_already_queued_preview_is_delivered_once_without_running_the_branch(): void
    {
        $id = $this->start();
        $job = Queue::pushed(RunNodePreview::class)->first();
        $this->assertSame(1, $job->tries);
        $this->assertTrue($job->failOnTimeout);
        $job->handle($this->previews);
        $state = $this->previews->status(7, $id);
        $this->assertSame('completed', $state['status'], $state['error'] ?? '');
        $this->assertSame(1, $state['sequence']);
        $this->assertSame('Каждый заказ', $state['current_node']);
        $this->assertSame(['loop'], array_column($this->previews->result(7, $id)['results'], 'id'));
        $job->handle($this->previews);
        $this->assertSame($state, $this->previews->status(7, $id));
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
