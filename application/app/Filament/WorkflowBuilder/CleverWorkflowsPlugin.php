<?php

namespace App\Filament\WorkflowBuilder;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowRunResource;
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
            WorkflowRunResource::class,
            WorkflowSecretResource::class,
        ];
    }
}
