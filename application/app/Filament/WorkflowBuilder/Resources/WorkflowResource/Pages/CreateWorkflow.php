<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasCompactWorkflowConfigurationPanels;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasWorkflowPageActions;
use Filament\Actions\Action;
use Leek\FilamentWorkflows\Resources\WorkflowResource\Pages\CreateWorkflow as BaseCreateWorkflow;

class CreateWorkflow extends BaseCreateWorkflow
{
    use HasCompactWorkflowConfigurationPanels;
    use HasWorkflowPageActions;

    protected static string $resource = WorkflowResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->workflowHelpAction(),
            $this->backToWorkflowListAction(),
        ];
    }
}
