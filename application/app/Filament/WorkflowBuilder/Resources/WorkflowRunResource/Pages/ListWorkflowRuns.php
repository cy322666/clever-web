<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowRunResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowRunResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListWorkflowRuns extends ListRecords
{
    protected static string $resource = WorkflowRunResource::class;

    protected static ?string $title = 'Исполнения процессов';

    protected ?string $subheading = null;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('workflows')
                ->label('К списку процессов')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(WorkflowResource::getUrl()),
        ];
    }
}
