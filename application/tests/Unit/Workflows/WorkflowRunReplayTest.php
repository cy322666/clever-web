<?php

namespace Tests\Unit\Workflows;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\ReplayWorkflow;
use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowRunReplay;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\WorkflowDebugFixture;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowRunReplayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        Schema::create('amocrm_fields', function ($table): void {
            $table->id(); $table->integer('user_id'); $table->string('entity_type');
            $table->integer('field_id'); $table->string('name'); $table->string('code')->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::table('workflow_runs', function ($table): void {
            $table->timestamp('started_at')->nullable();
            $table->json('context_data')->nullable();
        });
        (require database_path('migrations/2026_06_09_160734_create_workflow_run_steps_table.php'))->up();
        config(['cache.default' => 'array']);
        Http::preventStrayRequests();
        $this->actingAs(User::findOrFail(1));
        foreach ([1, 2] as $owner) {
            $flow = (new Workflow)->forceFill(['id' => $owner, 'user_id' => $owner, 'name' => 'Рабочий поток',
                'trigger_type' => 'manual', 'is_active' => true,
                'definition' => ['trigger' => ['type' => 'manual', 'config' => []], 'actions' => []]]);
            $flow->saveQuietly();
        }
        $definition = WorkflowDebugFixture::sampleDefinition();
        $definition['canvas_layout'] = ['trigger' => ['x' => 45, 'y' => 92]];
        foreach ([[1, 1, 1], [2, 2, 2], [3, 1, 2]] as [$id, $owner, $workflow]) {
            DB::table('workflow_runs')->insert(['id' => $id, 'user_id' => $owner, 'workflow_id' => $workflow,
                'created_at' => now(), 'context_data' => json_encode([
                    'trigger_data' => ['lead' => ['id' => 77]],
                    'variables' => ['_definition_snapshot' => $definition, '_resolved_inputs' => ['fetch' => ['limit' => 50]]],
                    'step_outputs' => ['fetch' => ['count' => 1, 'items' => [['id' => 77]]]],
                ])]);
        }
        DB::table('workflow_run_steps')->insert([
            ['workflow_run_id' => 1, 'step_id' => 'fetch', 'step_type' => 'action', 'action_type' => 'amocrm_query_leads',
                'status' => 'completed', 'input_data' => '{"limit":50}', 'output_data' => '{"count":1,"items":[{"id":77}]}', 'attempt_number' => 1],
            ['workflow_run_id' => 1, 'step_id' => 'yes', 'step_type' => 'action', 'action_type' => 'amocrm_add_note',
                'status' => 'failed', 'input_data' => '{}', 'output_data' => '[]', 'attempt_number' => 1],
            ['workflow_run_id' => 1, 'step_id' => 'yes', 'step_type' => 'action', 'action_type' => 'amocrm_add_note',
                'status' => 'completed', 'input_data' => '{}', 'output_data' => '{"id":99}', 'attempt_number' => 2],
        ]);
    }

    public function test_snapshot_and_every_step_attempt_are_loaded_instead_of_todays_definition(): void
    {
        $before = DB::table('workflows')->where('id', 1)->first();
        $replay = WorkflowRunReplay::load(1, 1);
        $this->assertCount(2, $replay['definition']['actions']);
        $this->assertSame(['x' => 45, 'y' => 92], $replay['definition']['canvas_layout']['trigger']);
        $this->assertSame(['fetch', 'yes', 'yes'], array_column($replay['results'], 'id'));
        $this->assertSame(['completed', 'error', 'completed'], array_column($replay['results'], 'status'));
        $this->assertNull($replay['results'][0]['resolved_input']); // Legacy context is not an attempt-specific input.
        $this->assertSame(['limit' => 50], $replay['results'][0]['input']);
        $this->assertSame(99, $replay['results'][2]['output']['id']);
        $this->assertSame(77, $replay['input']['lead']['id']);
        $this->assertEquals($before, DB::table('workflows')->where('id', 1)->first());
        Http::assertNothingSent();
    }

    public function test_both_the_run_and_its_workflow_must_belong_to_the_viewer(): void
    {
        foreach ([2, 3, 999] as $id) {
            try { WorkflowRunReplay::load($id, 1); $this->fail('Foreign or missing execution loaded.'); }
            catch (ModelNotFoundException) { $this->assertTrue(true); }
        }
    }

    public function test_missing_snapshot_is_rejected_without_using_current_schema(): void
    {
        DB::table('workflow_runs')->where('id', 1)->update(['context_data' => '{"trigger_data":{"lead":{"id":77}}}']);
        try { WorkflowRunReplay::load(1, 1); $this->fail('Missing snapshot loaded.'); }
        catch (HttpException $exception) { $this->assertSame(409, $exception->getStatusCode()); }
    }

    public function test_editor_opens_without_executing_or_modifying_the_original(): void
    {
        $before = DB::table('workflows')->where('id', 1)->first();
        $page = Livewire::test(ReplayWorkflow::class, ['run' => 1])
            ->assertStatus(200)->assertSet('record.id', 1)->assertSet('debugState.status', 'historical')
            ->assertSet('debugReal', false)->assertSet('debugSessionId', null)
            ->assertSet('debugInputMode', 'json')->assertSet('nodeRunResults.yes.output.id', 99)
            ->assertSee('Сохранить')->assertDontSee('Сохранить как новый поток')
            ->assertDontSee('Рабочий поток не изменяется.')
            ->assertDontSee('Отдельный черновик.')
            ->assertDontSee('wire:click="toggleWorkflowActivation"', false)
            ->call('runEditingWorkflowNode')->assertSet('debugState.status', 'historical')
            ->call('nextWorkflowDebugStep')->assertReturned(false);
        $this->assertEquals($before, DB::table('workflows')->where('id', 1)->first());
        $this->assertSame(2, Workflow::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    public function test_new_debug_session_does_not_reuse_recorded_outputs(): void
    {
        Livewire::test(ReplayWorkflow::class, ['run' => 1])->call('startWorkflowDebug')
            ->assertReturned(true)->assertSet('debugState.real', false)->assertSet('nodeRunResults', [])
            ->assertSet('replayContext', [])->assertSet('debugState.results', []);
        Http::assertNothingSent();
    }

    public function test_node_play_uses_historical_data_then_new_outputs_without_modifying_saved_history(): void
    {
        $beforeRuns = DB::table('workflow_runs')->get()->toJson();
        $beforeSteps = DB::table('workflow_run_steps')->get()->toJson();
        $beforeFlows = DB::table('workflows')->get()->toJson();
        $calls = [];
        $executor = $this->createMock(\App\Services\Workflows\WorkflowAmoCrmActionExecutor::class);
        $executor->expects($this->exactly(3))->method('execute')->willReturnCallback(function ($type, $config, $context) use (&$calls): array {
            $this->assertFalse($context->getVariable('_dry_run'));
            $this->assertFalse($context->getVariable('_test_mode'));
            $this->assertNull($context->getWorkflowRunId());
            $calls[] = [$type, $config];
            return ['success'=>true, 'output'=>$type === 'amocrm_query_leads' ? ['count'=>2] : ['id'=>100]];
        });
        $this->app->instance(\App\Services\Workflows\WorkflowAmoCrmActionExecutor::class, $executor);
        $page = Livewire::test(ReplayWorkflow::class, ['run'=>1])
            ->call('runWorkflowCanvasNode', 'yes')->assertHasNoErrors()
            ->assertSet('nodeRunResults.yes.status', 'completed')->assertSet('nodeRunResults.yes.output.id', 100)
            ->call('unmountAction')->call('runWorkflowCanvasNode', 'fetch')->assertHasNoErrors()
            ->assertSet('nodeRunResults.fetch.output.count', 2)
            ->call('unmountAction')->call('openWorkflowActionEditor', 'yes')->call('runEditingWorkflowNode')->assertHasNoErrors();
        $this->assertSame('Найдено: 1', $calls[0][1]['text']);
        $this->assertSame('Найдено: 2', $calls[2][1]['text']);
        $this->assertSame($beforeRuns, DB::table('workflow_runs')->get()->toJson());
        $this->assertSame($beforeSteps, DB::table('workflow_run_steps')->get()->toJson());
        $this->assertSame($beforeFlows, DB::table('workflows')->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_saving_replaces_the_current_workflow_without_creating_a_copy(): void
    {
        Livewire::test(ReplayWorkflow::class, ['run' => 1])->call('save', false)->assertHasNoErrors();
        $workflow = Workflow::withoutGlobalScopes()->findOrFail(1);
        $this->assertSame(2, Workflow::withoutGlobalScopes()->count());
        $this->assertSame('Рабочий поток', $workflow->name);
        $this->assertFalse($workflow->is_active);
        $this->assertCount(2, $workflow->definition['actions']);
        $this->assertSame(['x' => 45, 'y' => 92], $workflow->definition['canvas_layout']['trigger']);
        Http::assertNothingSent();
    }

    public function test_run_identifier_cannot_be_replaced_in_livewire_updates(): void
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(ReplayWorkflow::class, ['run' => 1])->set('replayRunId', 2);
    }

    public function test_foreign_run_page_is_not_available_even_for_an_authenticated_user(): void
    {
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(ReplayWorkflow::class, ['run' => 2]);
    }

    public function test_history_links_to_the_saved_schema_editor_only_when_a_snapshot_exists(): void
    {
        Livewire::test(\Tests\Support\WorkflowHistoryFixture::class)->assertSee('Отладка')
            ->assertSee('workflow-history-debug-action', false)->assertSee('<svg', false)
            ->assertDontSee('Открыть в редакторе')
            ->assertSee('/history/9/editor', false);
        $page = Livewire::test(ReplayWorkflow::class, ['run' => 1]);
        $this->assertSame(3, count($page->get('debugState.results')));
        $page->call('renameWorkflow', 'Отлаженный поток')->assertSet('data.name', 'Отлаженный поток');
        $this->assertSame('Отлаженный поток', Workflow::findOrFail(1)->name);
    }
}
