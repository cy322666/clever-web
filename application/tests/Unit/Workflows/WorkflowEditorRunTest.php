<?php

namespace Tests\Unit\Workflows;

use App\Models\User;
use App\Models\Workflows\{Workflow, WorkflowRun};
use App\Services\Workflows\{WorkflowEditorRunService, WorkflowSubscriptionAccess};
use Illuminate\Support\Facades\{Bus, Http, Queue, Schema};
use Illuminate\Validation\ValidationException;
use Leek\FilamentWorkflows\Engine\WorkflowExecutor;
use Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob;
use Livewire\Livewire;
use Tests\TestCase;

require_once __DIR__.'/WorkflowEditorSaveStateTest.php';

class WorkflowEditorRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowEditorSaveStateTest::prepareDatabase();
        Schema::drop('workflow_runs'); // Isolated in-memory test database only.
        (require database_path('migrations/2026_06_09_160733_create_workflow_runs_table.php'))->up();
        (require database_path('migrations/2026_06_09_160734_create_workflow_run_steps_table.php'))->up();
        config(['filament-workflows.queue.connection'=>'redis']);
        Http::preventStrayRequests();
        Queue::fake();
        $this->actingAs(User::findOrFail(2));
    }

    private function flow(array $overrides = []): Workflow
    {
        return Workflow::create(['name'=>'Manual test', 'user_id'=>2, 'is_active'=>false, 'definition'=>array_replace([
            'version'=>2, 'trigger'=>['type'=>'manual', 'config'=>[]],
            'actions'=>[['id'=>'assign', 'type'=>'workflow_set_variable', 'config'=>['assignments'=>[['name'=>'value', 'value'=>17]]]]],
            'connections'=>[['sourceId'=>'trigger', 'sourcePort'=>'output', 'targetId'=>'action:assign']],
        ], $overrides)]);
    }

    public function test_real_run_uses_fresh_context_and_frozen_definition_without_enabling_schedule(): void
    {
        $flow = $this->flow();
        $run = app(WorkflowEditorRunService::class)->start($flow->id);
        $this->assertSame('pending', $run->status->value);
        $this->assertSame(2, $run->user_id);
        $this->assertSame('manual', $run->trigger_source->value);
        $this->assertSame('trigger', $run->context_data['trigger_data']['_workflow_start_node_id']);
        $this->assertSame([], $run->context_data['step_outputs']);
        $this->assertSame($flow->definition, $run->context_data['variables']['_definition_snapshot']);
        $changed = $flow->definition;
        $changed['actions'][0]['config']['assignments'][0]['value'] = 99;
        $flow->update(['definition'=>$changed]);
        (new ExecuteWorkflowJob($run->id))->handle(app(WorkflowExecutor::class));
        $run->refresh();
        $this->assertSame('completed', $run->status->value, $run->error_message ?? '');
        $this->assertSame(17, $run->context_data['variables']['_workflow_values']['value']);
        $this->assertFalse($flow->fresh()->is_active);
        Queue::assertPushed(ExecuteWorkflowJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_double_click_returns_same_run_but_finished_run_can_be_started_again(): void
    {
        $flow = $this->flow();
        $service = app(WorkflowEditorRunService::class);
        $first = $service->start($flow->id);
        foreach (['pending', 'running', 'paused'] as $status) {
            $first->update(['status'=>$status]);
            $this->assertSame($first->id, $service->start($flow->id)->id);
        }
        Queue::assertPushed(ExecuteWorkflowJob::class, 1);
        $first->update(['status'=>'completed']);
        $this->assertNotSame($first->id, $service->start($flow->id)->id);
        Queue::assertPushed(ExecuteWorkflowJob::class, 2);
    }

    public function test_entire_loop_runs_all_items_and_only_selected_manual_start(): void
    {
        $edge = fn ($from, $port, $to) => ['sourceId'=>$from, 'sourcePort'=>$port, 'targetId'=>$to];
        $set = fn ($id, $value) => ['id'=>$id, 'type'=>'workflow_set_variable', 'config'=>['assignments'=>[['name'=>'value', 'value'=>$value]]]];
        $flow = $this->flow([
            'additional_triggers'=>[['id'=>'trigger:second', 'type'=>'manual', 'config'=>[]]],
            'actions'=>[
                ['id'=>'loop', 'type'=>'workflow_loop', 'config'=>['items'=>[1, 2, 3], 'mode'=>'all']],
                $set('body', 17), $set('done', 42), $set('other', 99),
            ],
            'connections'=>[$edge('trigger', 'output', 'action:other'), $edge('trigger:second', 'output', 'action:loop'),
                $edge('action:loop', 'each', 'action:body'), $edge('action:body', 'output', 'action:loop'), $edge('action:loop', 'done', 'action:done')],
        ]);
        $run = app(WorkflowEditorRunService::class)->start($flow->id, 'trigger:second');
        (new ExecuteWorkflowJob($run->id))->handle(app(WorkflowExecutor::class));
        $run->refresh();
        $this->assertSame('completed', $run->status->value, $run->error_message ?? '');
        $steps = $run->steps()->orderBy('id')->get();
        $this->assertSame(['loop', 'body', 'body', 'body', 'done'], $steps->map(fn ($step) => $step->input_data['_node_id'] ?? $step->step_id)->all());
        $this->assertSame(42, $run->context_data['variables']['_workflow_values']['value']);
        Http::assertNothingSent();
    }

    public function test_invalid_unconnected_or_ambiguous_starts_never_queue_a_run(): void
    {
        foreach ([['connections'=>[]], ['additional_triggers'=>[['id'=>'trigger:second', 'type'=>'manual']]],
            ['trigger'=>['type'=>'schedule', 'config'=>[]]],
            ['actions'=>[['id'=>'assign', 'type'=>'http_request', 'config'=>[]]]]] as $definition) {
            try { app(WorkflowEditorRunService::class)->start($this->flow($definition)->id); $this->fail('Must reject invalid start'); }
            catch (ValidationException $error) { $this->assertArrayHasKey('manualRun', $error->errors()); }
        }
        $this->assertSame(0, WorkflowRun::count());
        Queue::assertNothingPushed();
    }

    public function test_cross_owner_access_and_subscription_denial_never_queue(): void
    {
        $flow = $this->flow();
        $this->actingAs(User::findOrFail(1));
        try { app(WorkflowEditorRunService::class)->start($flow->id); $this->fail('Must reject other owner'); }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {}
        $this->actingAs(User::findOrFail(2));
        app()->instance(WorkflowSubscriptionAccess::class, new class {
            public function assertCanExecute(int $owner): void { throw new \Leek\FilamentWorkflows\Exceptions\NonRetryableWorkflowException('No subscription'); }
        });
        try { app(WorkflowEditorRunService::class)->start($flow->id); $this->fail('Must reject subscription'); }
        catch (\Leek\FilamentWorkflows\Exceptions\NonRetryableWorkflowException) {}
        $this->assertSame(0, WorkflowRun::count());
        Queue::assertNothingPushed();
    }

    public function test_sync_queue_and_rate_limits_are_rejected(): void
    {
        $flow = $this->flow();
        foreach ([['filament-workflows.queue.connection'=>'sync'], ['filament-workflows.queue.connection'=>'redis', 'filament-workflows.rate_limiting.max_runs_per_minute'=>0]] as $settings) {
            config($settings);
            try { app(WorkflowEditorRunService::class)->start($flow->id); $this->fail('Must reject unsafe start'); }
            catch (ValidationException) {}
        }
        Queue::assertNothingPushed();
        $this->assertSame(0, WorkflowRun::count());
    }

    public function test_queue_failure_marks_the_record_failed_instead_of_leaving_it_pending(): void
    {
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        try { app(WorkflowEditorRunService::class)->start($this->flow()->id); $this->fail('Must surface queue failure'); }
        catch (\RuntimeException $error) { $this->assertSame('Queue unavailable', $error->getMessage()); }
        $this->assertSame('failed', WorkflowRun::firstOrFail()->status->value);
    }

    public function test_editor_saves_before_run_polls_without_rebuilding_canvas_and_blocks_invalid_save(): void
    {
        $flow = $this->flow();
        $page = Livewire::test(SaveStateEditor::class, ['record'=>$flow->id]);
        $page->set('definition.actions.0.config.assignments.0.value', 23)->call('runWorkflowManually')->assertHasNoErrors();
        $run = WorkflowRun::firstOrFail();
        $this->assertSame(23, $run->context_data['variables']['_definition_snapshot']['actions'][0]['config']['assignments'][0]['value']);
        $page->assertSet('manualRunId', $run->id)->assertSet('manualRunStatus', 'pending')->assertSet('manualRunError', null);
        $this->assertStringContainsString('/history', $page->get('manualRunUrl'));
        $this->assertArrayNotHasKey('html', $page->effects);
        $run->update(['status'=>'completed']);
        $page->call('pollManualWorkflowRun')->assertSet('manualRunStatus', 'completed');
        $this->assertArrayNotHasKey('html', $page->effects);
        $page->set('rejectSave', true)->call('runWorkflowManually')->assertSet('manualRunError', 'Rejected by test validation');
        Queue::assertPushed(ExecuteWorkflowJob::class, 1);
    }

    public function test_halted_save_and_history_replay_cannot_start_a_real_run(): void
    {
        $flow = $this->flow();
        Livewire::test(HaltedManualRunEditor::class, ['record'=>$flow->id])->call('runWorkflowManually')
            ->assertSet('manualRunError', 'Сначала исправьте ошибки сохранения потока.');
        $history = new HistoryManualRunEditor;
        $history->record = $flow;
        try { $history->runWorkflowManually(); $this->fail('History must not start production'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        Queue::assertNothingPushed();
        $this->assertSame(0, WorkflowRun::count());
    }
}

class HaltedManualRunEditor extends SaveStateEditor
{
    protected function beforeSave(): void { throw new \Filament\Support\Exceptions\Halt; }
}

class HistoryManualRunEditor extends SaveStateEditor
{
    public function isWorkflowReplay(): bool { return true; }
}
