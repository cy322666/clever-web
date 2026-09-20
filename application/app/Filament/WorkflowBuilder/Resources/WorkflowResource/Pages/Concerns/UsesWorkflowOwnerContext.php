<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use App\Services\Workflows\WorkflowAdminAccess;
use Livewire\Attributes\Locked;

trait UsesWorkflowOwnerContext
{
    #[Locked]
    public bool $workflowAdminAccess = false;

    #[Locked]
    public ?int $workflowAdminOwnerId = null;

    #[Locked]
    public ?string $workflowAdminOwnerLabel = null;

    protected function initializeWorkflowOwnerContext(): void
    {
        $record = $this->getRecord();

        if (! WorkflowAdminAccess::isRoot() || (int) $record->user_id === (int) auth()->id()) {
            return;
        }

        $this->workflowAdminAccess = true;
        $this->workflowAdminOwnerId = (int) $record->user_id;

        $owner = WorkflowAdminAccess::useWorkflowOwner($record);
        $domain = $owner->accounts()->whereNotNull('subdomain')->latest('id')->value('subdomain');
        $this->workflowAdminOwnerLabel = trim($owner->email.($domain ? ' · '.$domain : ''));
    }

    protected function restoreWorkflowOwnerContext(): void
    {
        if (! $this->workflowAdminAccess) {
            return;
        }

        abort_unless(WorkflowAdminAccess::isRoot(), 403);
        abort_unless((int) $this->getRecord()->user_id === $this->workflowAdminOwnerId, 403);

        WorkflowAdminAccess::useWorkflowOwner($this->getRecord());
    }
}
