<?php

namespace Tests\Unit\Workflows;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\EditWorkflow;
use Filament\Notifications\Notification;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowCanvasFixture;
use Tests\TestCase;

class WorkflowQuietSaveTest extends TestCase
{
    public function test_workflow_create_is_quiet_and_deletion_has_no_modal(): void
    {
        $create = new \App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\CreateWorkflow;
        $this->assertNull((fn () => $this->getCreatedNotification())->call($create));
        $delete = (fn () => $this->deleteWorkflowAction())->call(new EditWorkflow);
        $this->assertFalse($delete->isConfirmationRequired());
        $this->assertFalse($delete->shouldOpenModal());
        $folder = (new \App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\ListWorkflows)->deleteFolderAction();
        $this->assertFalse($folder->shouldOpenModal());
    }

    public function test_workflow_save_does_not_generate_a_success_toast(): void
    {
        $page = new EditWorkflow;
        $this->assertNull((fn () => $this->getSavedNotification())->call($page));
    }

    public function test_node_save_keeps_data_and_warnings_without_a_saved_toast(): void
    {
        WorkflowCanvasDatabase::prepare();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $page = Livewire::test(WorkflowCanvasFixture::class)->call('openWorkflowActionEditor', 'condition');
        $conditionKey = array_key_first($page->get('mountedActions')[0]['data']['conditions']);
        $page->set("mountedActions.0.data.conditions.{$conditionKey}.right", '400');
        Notification::make()->warning()->title('Keep warning')->send();
        $page->call('callMountedAction')->assertHasNoErrors()->assertSet('mountedActions', []);
        $this->assertSame('400', $page->get('workflowActions')[0]['config']['conditions'][0]['right']);
        $notifications = session('filament.claimed_notifications', []);
        $this->assertContains('Keep warning', array_column($notifications, 'title'));
        $this->assertNotContains(__('filament-workflows::workflows.notifications.action_updated.title'), array_column($notifications, 'title'));
    }
}
