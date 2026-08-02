<?php

namespace App\Livewire\WorkflowBuilder;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\EditWorkflow;

class WorkflowModalEditor extends EditWorkflow
{
    protected string $view = 'livewire.workflow-builder.workflow-modal-editor';

    public function save(bool $shouldRedirect = false, bool $shouldSendSavedNotification = true): void
    {
        parent::save(false, $shouldSendSavedNotification);

        $this->dispatch('workflow-modal-saved');
    }
}
