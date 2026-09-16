<?php

namespace Tests\Unit\Workflows;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\EditWorkflow;
use App\Models\User;
use App\Models\Workflows\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowEditorActivationTest extends TestCase
{
    public function test_editor_toggle_saves_current_definition_and_keeps_connection_guard(): void
    {
        WorkflowListDatabase::prepare(); Http::preventStrayRequests();
        $this->actingAs(User::findOrFail(2));
        $flow = (new Workflow)->forceFill(['name'=>'Поток','user_id'=>2,'is_active'=>false,'definition'=>[
            'trigger'=>['type'=>'manual','config'=>[]], 'actions'=>[['id'=>'delay','type'=>'workflow_delay','config'=>['seconds'=>5]]],
        ]]);
        $flow->save();
        // Exercise the page mutation and model guards without mounting unrelated panel services.
        $page = new class extends EditWorkflow {
            protected function authorizeAccess(): void {}
            public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
            {
                if ($shouldRedirect || $shouldSendSavedNotification) throw new \LogicException('Toggle must stay in the editor without a save toast.');
                $data = $this->mutateFormDataBeforeSave([]); // Real empty canvas schema dehydrates no fields.
                $this->getRecord()->forceFill($data)->save();
            }
        };
        $page->record = $flow; $page->data = ['is_active'=>false];
        $page->definition = $flow->definition; $page->trigger = $flow->definition['trigger'];
        $page->definition['actions'][0]['config']['seconds'] = 15;
        $page->toggleWorkflowActivation();
        $this->assertFalse($flow->fresh()->is_active);
        DB::table('accounts')->insert(['user_id'=>2,'widget'=>'workflows','subdomain'=>'test','active'=>true,'refresh_token'=>'test']);
        $page->toggleWorkflowActivation();
        $this->assertTrue($flow->fresh()->is_active);
        $this->assertSame(15,$flow->fresh()->definition['actions'][0]['config']['seconds']);
        $page->definition['trigger']['type'] = 'amo-button';
        $page->trigger = $page->definition['trigger'];
        $page->toggleWorkflowActivation();
        $page->toggleWorkflowActivation();
        $this->assertTrue($flow->fresh()->is_active);
        DB::table('accounts')->update(['active'=>false]);
        $page->toggleWorkflowActivation();
        $this->assertFalse($flow->fresh()->is_active);
        Http::assertNothingSent();
    }
}
