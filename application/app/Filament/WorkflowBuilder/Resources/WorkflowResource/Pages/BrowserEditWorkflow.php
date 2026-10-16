<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasBrowserWorkflowCredentials;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\UsesWorkflowOwnerContext;
use App\Services\Workflows\WorkflowBrowserDocument;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

/** Keep the resource's authorization/credentials, without mounting its server-side builder. */
class BrowserEditWorkflow extends EditRecord
{
    use HasBrowserWorkflowCredentials;
    use UsesWorkflowOwnerContext;

    protected static string $resource = WorkflowResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected string $view = 'filament.workflow-builder.workflow-browser-page';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();
        $this->initializeWorkflowOwnerContext();
        $this->previousUrl = url()->previous();
    }

    public function browserBoot(): array
    {
        return app(WorkflowBrowserDocument::class)->boot($this->getRecord());
    }

    public function hydrate(): void
    {
        $this->restoreWorkflowOwnerContext();
    }

    public function getTitle(): string
    {
        return (string) ($this->getRecord()->name ?: 'Процесс');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return null;
    }
}
