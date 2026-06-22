<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use Filament\Actions\Action;

trait HasCompactWorkflowConfigurationPanels
{
    public function configureTriggerAction(): Action
    {
        return parent::configureTriggerAction()
            ->modalWidth('3xl');
    }

    public function configureWorkflowActionAction(): Action
    {
        return parent::configureWorkflowActionAction()
            ->modalContent(fn() => view('filament.workflow-builder.mask-reference-action-button'))
            ->modalWidth(fn() => ($this->getEditingWorkflowAction()['type'] ?? null) === 'control-condition' ? '6xl' : '3xl');
    }
}
