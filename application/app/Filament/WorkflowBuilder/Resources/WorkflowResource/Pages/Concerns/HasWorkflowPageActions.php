<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use App\Filament\App\Pages\WorkflowHelp;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use Filament\Actions\Action;

trait HasWorkflowPageActions
{
    protected function workflowHelpAction(): Action
    {
        return Action::make('workflow_help')
            ->label('Справка')
            ->icon('heroicon-o-question-mark-circle')
            ->color('gray')
            ->url(WorkflowHelp::getUrl());
    }

    protected function backToWorkflowListAction(): Action
    {
        return Action::make('back_to_workflow_list')
            ->label('К списку процессов')
            ->icon('heroicon-o-arrow-left')
            ->color('gray')
            ->url(WorkflowResource::getUrl('index'));
    }
}
