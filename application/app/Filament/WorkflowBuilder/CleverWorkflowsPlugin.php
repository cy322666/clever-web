<?php

namespace App\Filament\WorkflowBuilder;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use Leek\FilamentWorkflows\Resources\WorkflowSecretResource;
use Leek\FilamentWorkflows\WorkflowsPlugin;

class CleverWorkflowsPlugin extends WorkflowsPlugin
{
    /**
     * @return array<class-string>
     */
    protected function getResources(): array
    {
        return [
            WorkflowResource::class,
            WorkflowSecretResource::class,
        ];
    }
}
