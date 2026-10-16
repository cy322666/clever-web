<?php

namespace Tests\Unit\Workflows;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\EditWorkflow;
use App\Models\User;
use App\Models\Workflows\Workflow;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowEditorSaveStateTest extends TestCase
{
    public function test_javascript_editor_draft_survives_main_save_and_reopening(): void
    {
        self::prepareDatabase();
        Http::preventStrayRequests();
        Queue::fake();
        $this->actingAs(User::findOrFail(2));
        $flow = Workflow::create(['name'=>'Code editor','user_id'=>2,'is_active'=>false,'definition'=>[
            'version'=>2,'trigger'=>['type'=>'manual','config'=>[]],
            'actions'=>[['id'=>'code','type'=>'workflow_javascript','config'=>['javascript_code'=>'return $json;']]],
        ]]);
        $code = "const value = \$json.id;\nreturn { value };\n";
        Livewire::test(SaveStateEditor::class, ['record'=>$flow->id])
            ->call('openWorkflowInspector','action:code')
            ->set('mountedActions.0.data.javascript_code', $code)
            ->call('save')->assertHasNoErrors();
        $this->assertSame($code, $flow->fresh()->definition['actions'][0]['config']['javascript_code']);
        Livewire::test(SaveStateEditor::class, ['record'=>$flow->id])
            ->call('openWorkflowInspector','action:code')
            ->assertSet('mountedActions.0.data.javascript_code', $code);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_client_draft_changes_are_saved_without_calling_node_mutation_handlers(): void
    {
        self::prepareDatabase();
        Http::preventStrayRequests();
        Queue::fake();
        $this->actingAs(User::findOrFail(2));
        $flow = Workflow::create(['name'=>'Client draft', 'user_id'=>2, 'is_active'=>false, 'definition'=>[
            'version'=>2, 'trigger'=>['type'=>'manual', 'config'=>[]],
            'actions'=>[['id'=>'a','type'=>'workflow_delay','config'=>['seconds'=>1]], ['id'=>'b','type'=>'workflow_delay','config'=>['seconds'=>2]]],
        ]]);
        $page = Livewire::test(SaveStateEditor::class, ['record'=>$flow->id]);
        $actions = [$page->get('workflowActions')[1]];
        $actions[0]['disabled'] = true;
        $definition = $page->get('definition');
        $definition['actions'] = $actions;
        $definition['connections'] = [['sourceId'=>'trigger','sourcePort'=>'output','targetId'=>'action:b']];
        $definition['canvas_positions'] = ['trigger'=>['x'=>48,'y'=>48], 'action:b'=>['x'=>500,'y'=>320]];
        $page->set('workflowActions', $actions)->set('definition', $definition)->call('save')->assertHasNoErrors();
        $stored = $flow->fresh()->definition;
        $this->assertSame($actions,$stored['actions']);
        $this->assertSame($definition['connections'],$stored['connections']);
        $this->assertSame($definition['canvas_positions'],$stored['canvas_positions']);
        Livewire::test(SaveStateEditor::class, ['record'=>$flow->id])->assertSet('workflowActions',$actions);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_successful_save_updates_the_baseline_and_persists_the_canvas(): void
    {
        self::prepareDatabase();
        Http::preventStrayRequests();
        Queue::fake();
        $this->actingAs(User::findOrFail(2));
        $flow = Workflow::create(['name'=>'Save probe', 'user_id'=>2, 'is_active'=>false, 'definition'=>[
            'version'=>2, 'trigger'=>['type'=>'manual', 'config'=>[]],
            'actions'=>[['id'=>'delay', 'type'=>'workflow_delay', 'config'=>['seconds'=>5]]],
        ]]);
        $page = Livewire::test(SaveStateEditor::class, ['record'=>$flow->id]);
        $initial = $page->get('savedWorkflowEditorState');
        $page->set('definition.canvas_layout', ['action:delay'=>['x'=>120, 'y'=>80]])
            ->set('definition.canvas_edge_routes', [])
            ->call('save')
            ->assertHasNoErrors();
        $saved = $page->get('savedWorkflowEditorState');
        $this->assertArrayNotHasKey('html', $page->effects, 'Saving must acknowledge state without rebuilding the canvas.');
        $this->assertNotSame($initial, $saved);
        $this->assertSame($saved, $page->instance()->workflowEditorState());
        $this->assertSame(['action:delay'=>['x'=>120, 'y'=>80]], $flow->fresh()->definition['canvas_layout']);
        $page->call('save')->assertHasNoErrors()->assertSet('savedWorkflowEditorState', $saved);
        $page->set('definition.canvas_layout.action:delay.x', 300)
            ->set('rejectSave', true)
            ->call('save')
            ->assertHasErrors('name')
            ->assertSet('savedWorkflowEditorState', $saved);
        $this->assertArrayHasKey('html', $page->effects, 'Validation failures must still render their errors.');
        $this->assertSame(120, $flow->fresh()->definition['canvas_layout']['action:delay']['x']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_main_save_applies_open_inspector_fields_before_persisting_workflow(): void
    {
        self::prepareDatabase();
        Http::preventStrayRequests();
        Queue::fake();
        $this->actingAs(User::findOrFail(2));
        $flow = Workflow::create(['name'=>'Inspector draft','user_id'=>2,'is_active'=>false,'definition'=>[
            'version'=>2,'trigger'=>['type'=>'manual','config'=>[]],
            'actions'=>[['id'=>'delay','type'=>'workflow_delay','config'=>['seconds'=>5]]],
        ]]);
        Livewire::test(SaveStateEditor::class,['record'=>$flow->id])
            ->call('openWorkflowInspector','action:delay')->set('mountedActions.0.data.seconds',19)
            ->call('save')->assertHasNoErrors()->assertSet('mountedActions',[]);
        $this->assertSame(19,$flow->fresh()->definition['actions'][0]['config']['seconds']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_inline_node_rename_is_persisted_without_saving_an_open_inspector_draft(): void
    {
        self::prepareDatabase();
        Http::preventStrayRequests();
        Queue::fake();
        $this->actingAs(User::findOrFail(2));
        $flow = Workflow::create(['name' => 'Rename probe', 'user_id' => 2, 'is_active' => false, 'definition' => [
            'version' => 2, 'trigger' => ['type' => 'manual', 'config' => []],
            'actions' => [
                ['id' => 'delay', 'type' => 'workflow_delay', 'name' => 'Пауза', 'config' => ['seconds' => 5]],
                ['id' => 'next', 'type' => 'workflow_delay', 'config' => ['seconds' => '{{ $("Пауза").seconds }}']],
            ],
        ]]);
        $page = Livewire::test(SaveStateEditor::class, ['record' => $flow->id])
            ->call('openWorkflowInspector', 'action:delay')
            ->set('mountedActions.0.data.seconds', 19)
            ->set('definition.canvas_layout.action:delay', ['x' => 700, 'y' => 300])
            ->call('renameWorkflowNode', 'delay', 'Ожидание', true)
            ->assertHasNoErrors()
            ->assertSet('workflowActions.0.name', 'Ожидание');
        $this->assertSame('Ожидание', $page->effects['returns'][0] ?? null);
        $this->assertArrayNotHasKey('html', $page->effects);
        $stored = $flow->fresh()->definition;
        $this->assertSame('Ожидание', $stored['actions'][0]['name']);
        $this->assertSame(5, $stored['actions'][0]['config']['seconds']);
        $this->assertSame('{{ $("Ожидание").seconds }}', $stored['actions'][1]['config']['seconds']);
        $this->assertNotSame(['x' => 700, 'y' => 300], $stored['canvas_layout']['action:delay'] ?? null);
        $this->assertSame($stored, $page->get('savedWorkflowEditorState')['definition']);
        $this->assertSame(19, $page->get('mountedActions.0.data.seconds'));
        $this->assertSame(['x' => 700, 'y' => 300], $page->get('definition.canvas_layout.action:delay'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function prepareDatabase(): void
    {
        WorkflowListDatabase::prepare();
        \Illuminate\Support\Facades\Schema::table('workflow_runs', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->json('context_data')->nullable();
        });
        \Illuminate\Support\Facades\Schema::create('amocrm_fields', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            foreach (['user_id', 'field_id'] as $field) $table->integer($field)->nullable();
            foreach (['name', 'type', 'code', 'entity_type', 'enums'] as $field) $table->string($field)->nullable();
            $table->boolean('active')->default(true);
        });
    }
}

// Exercise the real Filament save lifecycle without unrelated panel chrome.
class SaveStateEditor extends EditWorkflow
{
    public bool $rejectSave = false;

    protected function authorizeAccess(): void {}

    protected function beforeValidate(): void
    {
        if ($this->rejectSave) {
            throw \Illuminate\Validation\ValidationException::withMessages(['name'=>'Rejected by test validation']);
        }
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view()->file(__DIR__.'/../../Support/workflow-save-state.blade.php');
    }
}
