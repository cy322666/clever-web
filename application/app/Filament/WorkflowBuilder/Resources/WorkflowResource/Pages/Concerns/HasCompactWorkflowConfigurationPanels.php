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
            ->modalWidth('3xl');
    }
}
