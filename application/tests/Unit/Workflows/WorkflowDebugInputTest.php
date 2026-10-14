<?php

namespace Tests\Unit\Workflows;

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowDebugFixture;
use Tests\TestCase;

class WorkflowDebugInputTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); WorkflowCanvasDatabase::prepare(); config(['cache.default' => 'array']); Http::preventStrayRequests(); }

    public function test_debugger_opens_with_json_and_no_event_builder(): void
    {
        $page = Livewire::test(WorkflowDebugFixture::class)->call('openWorkflowDebugger')
            ->assertSet('debugInputMode', 'json')->assertSet('debugInput', '{}')
            ->assertSee('JSON вручную')->assertSee('Прошлый запуск')
            ->assertDontSee('Конструктор события')->assertDontSee('Собрать пример')
            ->assertDontSee('Загрузить из amoCRM')->assertDontSee('workflow-debug-input__field', false);
        foreach (['debugEntity', 'debugEvent', 'debugEntityId', 'debugFields', 'debugBuilderInitialized', 'debugLoadedEntity', 'debugLoadedAccount'] as $property) {
            $this->assertFalse(property_exists($page->instance(), $property));
        }
        foreach (['initializeWorkflowDebugInput', 'updatedDebugEntity', 'addDebugInputField', 'removeDebugInputField', 'loadDebugEntity', 'resetLoadedDebugEntity'] as $method) {
            $this->assertFalse(method_exists($page->instance(), $method));
        }
        $this->assertFileDoesNotExist(app_path('Services/Workflows/WorkflowDebugInput.php'));
        $page->call('startWorkflowDebug')->assertHasNoErrors()->assertSet('debugState.real', false);
        Http::assertNothingSent();
    }

    public function test_json_is_preserved_when_opening_and_preparing_the_debugger(): void
    {
        $input = ['lead' => ['id' => 42], 'paid' => false, 'amount' => 0, 'nullable' => null, 'items' => [['id' => 'message-uuid']]];
        $json = json_encode($input, JSON_THROW_ON_ERROR);
        $page = Livewire::test(WorkflowDebugFixture::class)->set('debugInput', $json)
            ->call('openWorkflowDebugger')->call('previewDebugInput')->assertHasNoErrors()
            ->assertSet('debugInputMode', 'json')->assertSet('debugInput', $json)
            ->call('closeWorkflowDebugger')->call('openWorkflowDebugger')->assertSet('debugInput', $json)
            ->call('startWorkflowDebug')->assertHasNoErrors()->assertSet('debugState.real', false);
        $session = \Illuminate\Support\Facades\Cache::get('workflow-debug:guest:'.$page->get('debugSessionId'));
        $this->assertSame($input + ['_workflow_start_node_id' => 'trigger'], $session['context']['trigger_data']);
        Http::assertNothingSent();
    }

    public function test_removed_builder_mode_is_rejected_and_switching_back_clears_the_error(): void
    {
        Livewire::test(WorkflowDebugFixture::class)
            ->set('debugInputMode', 'builder')->call('previewDebugInput')->assertHasErrors('debugInput')
            ->set('debugInputMode', 'json')->assertHasNoErrors()->call('previewDebugInput')->assertHasNoErrors();
        Http::assertNothingSent();
    }

    public function test_real_run_still_requires_explicit_confirmation(): void
    {
        Livewire::test(WorkflowDebugFixture::class)->call('openWorkflowDebugger')
            ->set('debugReal', true)->call('startWorkflowDebug')
            ->assertSet('debugSessionId', null)->assertSet('debugState', []);
        Http::assertNothingSent();
    }

    public function test_reusing_a_run_is_scoped_to_the_current_workflow_and_user_and_copies_only_input(): void
    {
        \Tests\Support\WorkflowListDatabase::prepare();
        \Illuminate\Support\Facades\Schema::table('workflow_runs', function ($table): void {
            $table->timestamp('started_at')->nullable(); $table->json('context_data');
        });
        $this->actingAs(\App\Models\User::findOrFail(1));
        $flow = (new \App\Models\Workflows\Workflow)->forceFill(['id' => 1, 'user_id' => 1, 'name' => 'Тест', 'trigger_type' => 'manual', 'is_active' => false, 'definition' => WorkflowDebugFixture::sampleDefinition()]);
        $flow->saveQuietly();
        foreach ([[1, 1, 1], [2, 2, 1], [3, 1, 2]] as [$id, $owner, $workflow]) {
            \Illuminate\Support\Facades\DB::table('workflow_runs')->insert(['id' => $id, 'user_id' => $owner, 'workflow_id' => $workflow, 'created_at' => now(),
                'context_data' => json_encode(['trigger_data' => ['lead' => ['id' => 77], '_workflow_start_node_id' => 'trigger:removed'], 'variables' => ['_dry_run' => false], 'step_outputs' => ['old' => ['id' => 99]]])]);
        }
        $page = Livewire::test(\Tests\Support\WorkflowSavedDebugFixture::class)->set('debugInputMode', 'history')->set('debugRunId', 1)
            ->call('previewDebugInput')->assertHasNoErrors();
        $this->assertSame(['lead' => ['id' => 77]], json_decode($page->get('debugInput'), true));
        $this->assertSame([1], array_keys($page->instance()->recentDebugRuns()));
        foreach ([2, 3] as $id) $page->set('debugRunId', $id)->call('previewDebugInput')->assertHasErrors('debugInput');
        $page->set('debugRunId', 1)->call('startWorkflowDebug')->assertHasNoErrors()->assertSet('debugState.real', false)
            ->call('editWorkflowDebugInput')->assertSet('debugSessionId', null)->assertSet('debugState', [])->assertSet('debugInputMode', 'history');
        Http::assertNothingSent();
    }

    public function test_single_condition_check_uses_the_same_owned_trigger_data_as_the_picker(): void
    {
        \Tests\Support\WorkflowListDatabase::prepare();
        (require database_path('migrations/2023_09_01_120654_create_fields_table.php'))->up();
        \Illuminate\Support\Facades\Schema::table('amocrm_fields', fn ($t) => $t->boolean('active')->default(true));
        \Illuminate\Support\Facades\Schema::table('workflow_runs', fn ($t) => $t->json('context_data'));
        $this->actingAs(\App\Models\User::findOrFail(1));
        $step = ['id' => 'if', 'type' => 'control-condition', 'config' => ['logic' => 'and', 'conditions' => [[
            'left' => '{{ $node["Кнопка"].json.item.pipeline_id }}', 'operator' => 'equals', 'right' => '11003486',
        ]]]];
        $definition = ['trigger' => ['type' => 'amo-button', 'config' => []], 'actions' => [$step]];
        $flow = (new \App\Models\Workflows\Workflow)->forceFill(['id' => 1, 'user_id' => 1, 'name' => 'Тест', 'is_active' => false, 'definition' => $definition]);
        $flow->save();
        foreach ([[1, 1, 11003486], [2, 2, 99]] as [$id, $owner, $pipeline]) {
            \Illuminate\Support\Facades\DB::table('workflow_runs')->insert(['id' => $id, 'user_id' => $owner, 'workflow_id' => 1,
                'context_data' => json_encode(['trigger_data' => ['item' => ['pipeline_id' => $pipeline]], 'step_outputs' => ['old' => ['id' => 99]]])]);
        }
        $page = Livewire::test(\Tests\Support\WorkflowSavedDebugFixture::class)
            ->set('trigger', $definition['trigger'])->set('definition', $definition)->set('workflowActions', [$step])
            ->call('openWorkflowActionEditor', 'if')->call('runEditingWorkflowNode')->assertHasNoErrors()
            ->assertSet('nodeRunResults.if.status', 'completed')->assertSet('nodeRunResults.if.output.passed', true)
            ->assertSet('debugInputSource', 'Данные запуска #1');
        $input = json_decode($page->get('debugInput'), true);
        $this->assertSame(['item' => ['pipeline_id' => 11003486], '_workflow_start_node_id' => 'trigger'], $input);
        $context = \Illuminate\Support\Facades\Cache::get('workflow-node-preview:1:'.$page->get('nodePreviewSessionId'));
        $this->assertArrayNotHasKey('old', $context['step_outputs']);
        Http::assertNothingSent();
    }
}
