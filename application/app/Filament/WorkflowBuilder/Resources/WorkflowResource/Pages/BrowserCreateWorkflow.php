<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasBrowserWorkflowCredentials;
use App\Services\Workflows\WorkflowBrowserDocument;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Url;

class BrowserCreateWorkflow extends CreateRecord
{
    use HasBrowserWorkflowCredentials;

    protected static string $resource = WorkflowResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected string $view = 'filament.workflow-builder.workflow-browser-page';

    public bool $workflowAdminAccess = false;

    #[Url]
    public ?string $folder = null;

    public function browserBoot(): array
    {
        return app(WorkflowBrowserDocument::class)->boot(folder: $this->folder);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTitle(): string
    {
        return 'Новый процесс';
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return null;
    }
}
