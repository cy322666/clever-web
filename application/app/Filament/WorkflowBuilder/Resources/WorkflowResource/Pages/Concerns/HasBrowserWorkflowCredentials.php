<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use App\Services\Workflows\WorkflowCredentials;

trait HasBrowserWorkflowCredentials
{
    use HasWorkflowCredentials { saveWorkflowCredential as private saveBrowserCredential; }

    public function saveWorkflowCredential(): void
    {
        $this->saveBrowserCredential();
        $this->dispatch('workflow-browser-credentials-updated', options: WorkflowCredentials::options('telegram'));
    }
}
