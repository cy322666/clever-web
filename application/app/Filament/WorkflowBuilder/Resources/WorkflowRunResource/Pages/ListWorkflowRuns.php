<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowRunResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowRunResource;
use App\Models\Workflows\WorkflowRun;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Leek\FilamentWorkflows\Enums\RunStatus;

class ListWorkflowRuns extends ListRecords
{
    #[\Livewire\Attributes\Url(as: 'run')]
    public ?int $historyRunId = null;

    #[\Livewire\Attributes\Url(as: 'errors')]
    public bool $historyErrorsOnly = false;

    protected static string $resource = WorkflowRunResource::class;

    protected static ?string $title = 'История';

    protected ?string $subheading = null;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected string $view = 'filament.workflow-builder.workflow-account-history-page';

    public function getHeading(): string|Htmlable|null
    {
        return 'История';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getTableQuery(): Builder
    {
        return WorkflowRunResource::getEloquentQuery()
            ->when($this->historyErrorsOnly, fn (Builder $query): Builder => $query->where('status', RunStatus::FAILED));
    }

    public function getSelectedRun(): ?WorkflowRun
    {
        $selectedId = $this->historyRunId ?? request()->integer('run');
        if ($selectedId <= 0) {
            return null;
        }

        return WorkflowRunResource::getEloquentQuery()
            ->with('steps')
            ->withCount('steps')
            ->whereKey($selectedId)
            ->firstOrFail();
    }

    public function getHistoryIndexUrl(): string
    {
        $parameters = [];
        if ($this->historyErrorsOnly) {
            $parameters['errors'] = 1;
        }
        $workflowId = request()->integer('workflow_id');

        if ($workflowId > 0) {
            $parameters['workflow_id'] = $workflowId;
        }

        return WorkflowRunResource::getUrl('index', $parameters);
    }

    public function restartSelectedRun(): void
    {
        $run = $this->getSelectedRun();

        if ($run !== null) {
            WorkflowRunResource::restartRun($run);
        }
    }
}
