<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasWorkflowCredentials;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Url;

class WorkflowConnections extends Page
{
    use HasWorkflowCredentials;

    protected static string $resource = WorkflowResource::class;
    protected string $view = 'filament.workflow-builder.workflow-connections-page';
    protected static ?string $title = 'Подключения';
    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url(as: 'folder')]
    public ?string $workflowGroupFilter = null;

    #[Url(as: 'launch')]
    public ?string $workflowLaunchFilter = null;

    public function mount(): void
    {
        abort_unless(WorkflowResource::canViewAny(), 403);
        $this->resetWorkflowCredentialManager();
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
