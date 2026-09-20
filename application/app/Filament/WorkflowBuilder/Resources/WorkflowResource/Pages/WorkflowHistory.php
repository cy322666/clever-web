<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\UsesWorkflowOwnerContext;
use App\Models\Workflows\WorkflowRun;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Leek\FilamentWorkflows\Models\Workflow;

class WorkflowHistory extends ViewRecord
{
    use UsesWorkflowOwnerContext;

    #[\Livewire\Attributes\Url(as: 'run')]
    public ?int $historyRunId = null;

    #[\Livewire\Attributes\Url(as: 'errors')]
    public bool $historyErrorsOnly = false;

    public function updatedHistoryErrorsOnly(): void
    {
        $this->historyRunId = null;
    }

    protected static string $resource = WorkflowResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected string $view = 'filament.workflow-builder.workflow-history-page';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();
        $this->initializeWorkflowOwnerContext();

        if (! $this->hasInfolist()) {
            $this->fillForm();
        }
    }

    public function hydrate(): void
    {
        $this->restoreWorkflowOwnerContext();
        parent::hydrate();
    }

    public function getTitle(): string
    {
        return 'История · '.(string) ($this->record?->name ?? 'Процесс');
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getHistoryWorkflow(): ?Workflow
    {
        return $this->record;
    }

    public function getHistoryTitle(): string
    {
        return (string) ($this->record?->name ?: 'Процесс');
    }

    public function getHistoryBackUrl(): string
    {
        return WorkflowResource::getUrl('edit', ['record' => $this->record]);
    }

    public function getHistoryActionUrl(): string
    {
        return $this->getHistoryBackUrl();
    }

    public function getHistoryActionLabel(): string
    {
        return 'Отладка';
    }

    public function getHistoryRunUrl(WorkflowRun $run): string
    {
        return WorkflowResource::getUrl('history', [
            'record' => $this->record,
            'run' => $run->getKey(),
            ...($this->historyErrorsOnly ? ['errors' => 1] : []),
        ]);
    }

    /**
     * @return Collection<int, WorkflowRun>
     */
    public function getWorkflowRuns(): Collection
    {
        $query = WorkflowRun::query()
            ->where('user_id', Auth::id())
            ->where('workflow_id', $this->record->getKey())
            ->when($this->historyErrorsOnly, fn ($query) => $query->where('status', 'failed'))
            ->with(['workflow', 'latestStep', 'triggeredBy'])
            ->withCount('steps')
            ->latest('created_at')
            ->limit(75);

        if (Schema::hasTable('workflow_run_entities')) {
            $query->with('entityLinks');
        }

        return $query->get();
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
}
