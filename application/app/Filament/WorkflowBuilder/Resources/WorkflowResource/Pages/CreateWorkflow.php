<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasCompactWorkflowConfigurationPanels;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasWorkflowPageActions;
use App\Workflows\Triggers\WorkflowCompletedTrigger;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Leek\FilamentWorkflows\Resources\WorkflowResource\Pages\CreateWorkflow as BaseCreateWorkflow;

class CreateWorkflow extends BaseCreateWorkflow
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
            $this->workflowHelpAction(),
            $this->backToWorkflowListAction(),
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = parent::mutateFormDataBeforeCreate($data);

        if (data_get($data, 'definition.trigger.type') === WorkflowCompletedTrigger::type()) {
            data_set($data, 'definition.trigger.config', []);
            $data['is_active'] = false;
        }

        return WorkflowResource::forceInactiveWithoutActions($data, notify: true);
    }
}
