<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasCompactWorkflowConfigurationPanels;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasWorkflowPageActions;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Leek\FilamentWorkflows\Resources\WorkflowResource\Pages\EditWorkflow as BaseEditWorkflow;

class EditWorkflow extends BaseEditWorkflow
{
    use HasCompactWorkflowConfigurationPanels;
    use HasWorkflowPageActions;

    protected static string $resource = WorkflowResource::class;

    protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->workflowMasksAction(),
            $this->workflowDependencyMapAction(),
            $this->workflowHelpAction(),
            $this->backToWorkflowListAction(),
            $this->deleteAction(),
        ];
    }
}
