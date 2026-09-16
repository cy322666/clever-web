<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Services\Workflows\WorkflowRunReplay;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

class ReplayWorkflow extends CreateWorkflow
{
    protected string $view = 'filament.workflow-builder.workflow-replay-page';

    #[Locked]
    public int $replayRunId;

    #[Locked]
    public int $replayWorkflowId;

    #[Locked]
    public ?string $replayStartedAt = null;

    #[Locked]
    public array $replayContext = [];

    public function mount(int|string|null $run = null): void
    {
        parent::mount();
        $this->loadHistoricalRun((int) $run);
    }

    protected function loadHistoricalRun(int $runId): void
    {
        abort_unless(Auth::id(), 403);
        $replay = WorkflowRunReplay::load($runId, (int) Auth::id());
        $this->replayRunId = $replay['run_id'];
        $this->replayWorkflowId = $replay['workflow_id'];
        $this->replayStartedAt = $replay['started_at'];
        $this->replayContext = $replay['context'];
        $this->definition = $replay['definition'];
        $this->loadFromDefinition();
        $this->data = array_merge($this->data ?? [], ['name' => $replay['name'], 'is_active' => false]);
        $this->debugInputMode = 'json';
        $this->debugInput = json_encode($replay['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->debugStartNodeId = $replay['input']['_workflow_start_node_id'] ?? 'trigger';
        $this->debugOpen = true;
        $this->debugReal = $this->debugRealConfirmed = false;
        $this->debugState = ['status' => 'historical', 'real' => false, 'results' => $replay['results'], 'next_id' => null, 'error' => null];
        $this->nodeRunResults = collect($replay['results'])->keyBy('id')->all();
        $this->nodeRunResults[$this->debugStartNodeId] = ['historical' => true, 'status' => 'completed', 'output' => $replay['input']];
    }

    public function hydrate(): void
    {
        parent::hydrate();
        abort_unless(Auth::id() && WorkflowRunReplay::ownedRuns((int) Auth::id())->whereKey($this->replayRunId)->exists(), 404);
    }

    public function isWorkflowReplay(): bool
    {
        return true;
    }

    public function getTitle(): string
    {
        return 'Редактор сохранённого запуска';
    }

    public function getReplayBackUrl(): string
    {
        return WorkflowResource::getUrl('history', ['record' => $this->replayWorkflowId, 'run' => $this->replayRunId]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->hydrate();
        // Creating an inactive copy is the only persistence path on this page.
        $data['name'] = $this->data['name'] ?? $data['name'] ?? 'Копия запуска #'.$this->replayRunId;
        $data['is_active'] = false;
        $data = parent::mutateFormDataBeforeCreate($data);
        $data['is_active'] = false;
        $data['user_id'] = Auth::id();

        return $data;
    }

    protected function workflowDebugContext(): array
    {
        // A node may use the saved inputs; later node plays use the newly produced outputs.
        return parent::workflowDebugContext()
            ?: (($this->debugState['status'] ?? null) === 'historical' ? $this->replayContext : []);
    }

    public function startWorkflowDebug(): bool
    {
        $started = parent::startWorkflowDebug();
        if ($started) {
            $this->nodeRunResults = [];
            $this->replayContext = [];
        }

        return $started;
    }

}
