<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\RelationManagers;

use Filament\Tables\Table;
use Leek\FilamentWorkflows\Resources\WorkflowResource\RelationManagers\WorkflowRunsRelationManager as BaseWorkflowRunsRelationManager;

class WorkflowRunsRelationManager extends BaseWorkflowRunsRelationManager
{
    public function table(Table $table): Table
    {
        $table = parent::table($table);

        $table->getColumn('ulid')?->toggleable(isToggledHiddenByDefault: true);

        return $table;
    }
}
