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
        $this->assertArrayNotHasKey('resolved_input', $replay['results'][0]);
        $this->assertArrayNotHasKey('input', $replay['results'][0]);
        $this->assertSame(['message' => 'Входные данные этого шага не записаны.'], $replay['results'][0]['display_input']);
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
            ->assertSet('debugState.display_trigger_data.lead.id', 77)
            ->assertSet('debugState.start_node_id', 'trigger')
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
            ->assertDispatched('workflow-debug-updated', fn ($event, $params) => ($params['state']['preview_node_id'] ?? null) === 'yes')
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
        $this->assertSame('Рабочий поток', Workflow::findOrFail(1)->name);
        $page->call('save')->assertHasNoErrors();
        $this->assertSame('Отлаженный поток', Workflow::findOrFail(1)->name);
    }

    public function test_data_selection_preserves_the_current_definition_unsaved_form_and_saved_history(): void
    {
        $before = DB::table('workflow_runs')->get()->toJson();
        $page = Livewire::test(\Tests\Support\WorkflowSavedDebugFixture::class)
            ->set('workflowActions.0.config.limit', 123)->set('definition.description', 'Не сохранено')
            ->call('openWorkflowInspector', 'action:fetch')
            ->set('mountedActions.0.data.limit', 17);
        $definition = $page->get('definition');
        $actions = $page->get('workflowActions');
        $mounted = $page->get('mountedActions');
        $page->call('inspectWorkflowDebugRun', 1)->assertReturned(true)
            ->assertSet('debugState.run_id', 1)->assertSet('debugState.results.0.display_output.count', 1)
            ->assertSet('definition', $definition)->assertSet('workflowActions', $actions)
            ->assertSet('mountedActions', $mounted)->assertSet('debugSessionId', null);
        $this->assertSame($before, DB::table('workflow_runs')->get()->toJson());
        $this->assertSame([], Workflow::findOrFail(1)->definition['actions']);
        Http::assertNothingSent();
    }

    public function test_debug_history_selection_cannot_load_another_workflow_or_user(): void
    {
        foreach ([2, 3, 999] as $id) {
            try {
                Livewire::test(\Tests\Support\WorkflowSavedDebugFixture::class)->call('inspectWorkflowDebugRun', $id);
                $this->fail('Another workflow or user was loaded.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_node_play_uses_the_open_form_without_apply_or_save(): void
    {
        $executor = $this->createMock(\App\Services\Workflows\WorkflowAmoCrmActionExecutor::class);
        $executor->expects($this->once())->method('execute')->willReturnCallback(function ($type, $config): array {
            $this->assertSame(17, (int) $config['limit']);
            return ['success' => true, 'output' => ['count' => 17]];
        });
        $this->app->instance(\App\Services\Workflows\WorkflowAmoCrmActionExecutor::class, $executor);
        Livewire::test(\Tests\Support\WorkflowSavedDebugFixture::class)
            ->call('openWorkflowInspector', 'action:fetch')->set('mountedActions.0.data.limit', 17)
            ->call('openWorkflowDebugger')->assertSet('mountedActions.0.data.limit', 17)
            ->call('inspectWorkflowDebugRun', 1)->set('debugSelectedResultIndex', 0)
            ->call('runWorkflowCanvasNode', 'fetch')->assertHasNoErrors()
            ->assertSet('nodeRunResults.fetch.output.count', 17)
            ->assertSet('debugState.results.0.output.count', 1);
        $this->assertSame([], Workflow::findOrFail(1)->definition['actions']);
        Http::assertNothingSent();
    }

    public function test_selected_loop_pass_does_not_use_last_item_or_values_from_a_later_pass(): void
    {
        $context = ['trigger_data' => ['id' => 7], 'step_outputs' => ['later' => ['id' => 999]],
            'variables' => ['_workflow_values' => ['future' => true]]];
        $results = [
            ['id' => 'loop', 'type' => 'workflow_loop', 'output' => ['items' => [['id' => 10], ['id' => 20]]]],
            ['id' => 'fetch', 'iteration' => [['node' => 'loop', 'index' => 0]], 'output' => ['id' => 100]],
            ['id' => 'save', 'iteration' => [['node' => 'loop', 'index' => 0]], 'output' => []],
            ['id' => 'fetch', 'iteration' => [['node' => 'loop', 'index' => 1]], 'output' => ['id' => 200]],
            ['id' => 'save', 'iteration' => [['node' => 'loop', 'index' => 1]], 'output' => []],
        ];
        $first = WorkflowRunReplay::contextAt($context, $results, 2, 'save');
        $second = WorkflowRunReplay::contextAt($context, $results, 4, 'save');
        $this->assertSame(10, $first['step_outputs']['loop']['item']['id']);
        $this->assertSame(100, $first['step_outputs']['fetch']['id']);
        $this->assertSame(20, $second['step_outputs']['loop']['item']['id']);
        $this->assertSame(200, $second['step_outputs']['fetch']['id']);
        $this->assertArrayNotHasKey('later', $first['step_outputs']);
        $this->assertArrayNotHasKey('save', $first['step_outputs']);
        $this->assertSame([], $first['variables']['_workflow_values']);
        $this->assertSame(100, WorkflowRunReplay::contextAt($context, $results, 1, 'next')['step_outputs']['fetch']['id']);
        $this->assertSame(['later' => ['id' => 999]], $context['step_outputs']);
    }

    public function test_missing_loop_item_is_rejected_instead_of_executing_on_another_item(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkflowRunReplay::contextAt([], [['id' => 'save', 'iteration' => [['node' => 'loop', 'index' => 3]]]], 0, 'save');
    }

    public function test_legacy_condition_and_lookup_variables_do_not_leak_from_future_steps(): void
    {
        $context = ['variables' => ['checked' => ['passed' => true], 'found_contact_1_id' => 999,
            '_definition_snapshot' => ['actions' => [['id' => 'if', 'type' => 'control-condition', 'config' => ['context_key' => 'checked']]]]]];
        $results = [
            ['id' => 'find', 'type' => 'amocrm_find_entity', 'output' => ['action' => 'found', 'context_key' => 'found_contact_1', 'found' => true, 'entity_id' => 77, 'entity_type' => 'contact']],
            ['id' => 'if', 'type' => 'control-condition', 'output' => ['passed' => false, 'branch' => 'false', 'condition_results' => []]],
            ['id' => 'save', 'output' => []],
        ];
        $before = WorkflowRunReplay::contextAt($context, $results, 0, 'find');
        $this->assertArrayNotHasKey('checked', $before['variables']);
        $this->assertArrayNotHasKey('found_contact_1_id', $before['variables']);
        $beforeSave = WorkflowRunReplay::contextAt($context, $results, 2, 'save');
        $this->assertFalse($beforeSave['variables']['checked']['passed']);
        $this->assertSame(77, $beforeSave['variables']['found_contact_1_id']);
        $this->assertNull($beforeSave['variables']['found_contact_1']['data']);
    }
}
