<?php

namespace App\Filament\WorkflowBuilder\Resources;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Schemas\WorkflowForm;
use Filament\Schemas\Schema;
use Leek\FilamentWorkflows\Resources\WorkflowResource as BaseWorkflowResource;

class WorkflowResource extends BaseWorkflowResource
{
    public static function form(Schema $schema): Schema
    {
        return WorkflowForm::configure($schema);
    }

    /**
     * @return array<class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkflows::route('/'),
            'create' => Pages\CreateWorkflow::route('/create'),
            'edit' => Pages\EditWorkflow::route('/{record}/edit'),
        ];
    }
}
