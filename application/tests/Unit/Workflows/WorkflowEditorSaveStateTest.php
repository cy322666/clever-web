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
        $this->assertNotSame($initial, $saved);
        $this->assertSame($saved, $page->instance()->workflowEditorState());
        $this->assertSame(['action:delay'=>['x'=>120, 'y'=>80]], $flow->fresh()->definition['canvas_layout']);
        $page->call('save')->assertHasNoErrors()->assertSet('savedWorkflowEditorState', $saved);
        $page->set('definition.canvas_layout.action:delay.x', 300)
            ->set('rejectSave', true)
            ->call('save')
            ->assertHasErrors('name')
            ->assertSet('savedWorkflowEditorState', $saved);
        $this->assertSame(120, $flow->fresh()->definition['canvas_layout']['action:delay']['x']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function prepareDatabase(): void
    {
        WorkflowListDatabase::prepare();
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
