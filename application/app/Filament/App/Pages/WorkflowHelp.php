<?php

namespace App\Filament\App\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use Filament\Actions\Action;
use Filament\Pages\Page;

class WorkflowHelp extends Page
{
    protected static ?string $title = 'Справка по процессам';

    protected static ?string $navigationLabel = 'Справка по процессам';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $routePath = 'workflow-help';

    protected string $view = 'filament.app.pages.workflow-help';

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back_to_workflow_list')
                ->label('К списку процессов')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(WorkflowResource::getUrl('index')),
        ];
    }
}
