<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasCompactWorkflowConfigurationPanels;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasWorkflowPageActions;
use App\Workflows\Triggers\WorkflowCompletedTrigger;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
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
            $this->workflowDocumentationAction(),
            $this->workflowHelpAction(),
            $this->backToWorkflowListAction(),
            $this->deleteAction(),
        ];
    }

    public function getTitle(): string
    {
        return (string)($this->record?->name ?? 'Процесс');
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = parent::mutateFormDataBeforeSave($data);
        $data = WorkflowResource::forceInactiveWhenActivationInvalid($data, $this->record, notify: true);

        if (data_get($data, 'definition.trigger.type') !== WorkflowCompletedTrigger::type()) {
            return $data;
        }

        data_set($data, 'definition.trigger.config', []);

        return $data;
    }
}
