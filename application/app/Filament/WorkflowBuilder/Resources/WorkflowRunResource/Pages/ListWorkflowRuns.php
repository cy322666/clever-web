<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowRunResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowRunResource;
use App\Models\Workflows\WorkflowRun;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

class ListWorkflowRuns extends ListRecords
{
    #[\Livewire\Attributes\Url(as: 'run')] public ?int $historyRunId = null;
    #[\Livewire\Attributes\Url(as: 'errors')] public bool $historyErrorsOnly = false;

    public function updatedHistoryErrorsOnly(): void
    {
        $this->historyRunId = null;
    }
    protected static string $resource = WorkflowRunResource::class;

    protected static ?string $title = 'История';

    protected ?string $subheading = null;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected string $view = 'filament.workflow-builder.workflow-history-page';

    public function getHeading(): string|Htmlable|null
    {
        return 'История';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getHistoryWorkflow(): null
    {
        return null;
    }

    public function getHistoryTitle(): string
    {
        return 'Все исполнения';
    }

    public function getHistoryBackUrl(): string
    {
        return WorkflowResource::getUrl('index');
    }

    public function getHistoryActionUrl(): string
    {
        return WorkflowResource::getUrl('index');
    }

    public function getHistoryActionLabel(): string
    {
        return 'Сценарии';
    }

    /**
     * @return Collection<int, WorkflowRun>
     */
    public function getWorkflowRuns(): Collection
    {
        return WorkflowRunResource::getEloquentQuery()
            ->when($this->historyErrorsOnly, fn ($query) => $query->where('status', 'failed'))
            ->latest('created_at')
            ->limit(100)
            ->get();
    }

    public function getSelectedRun(Collection $runs): ?WorkflowRun
    {
        $selectedId = $this->historyRunId ?? request()->integer('run');
        $run = $selectedId > 0
            ? ($runs->first(fn (WorkflowRun $run): bool => (int) $run->getKey() === $selectedId) ?? $runs->first())
            : $runs->first();

        $run?->loadMissing('steps');

        return $run;
    }

    public function getHistoryRunUrl(WorkflowRun $run): string
    {
        $parameters = ['run' => $run->getKey()];
        if ($this->historyErrorsOnly) $parameters['errors'] = 1;
        $workflowId = request()->integer('workflow_id');

        if ($workflowId > 0) {
            $parameters['workflow_id'] = $workflowId;
        }

        return WorkflowRunResource::getUrl('index', $parameters);
    }
}
