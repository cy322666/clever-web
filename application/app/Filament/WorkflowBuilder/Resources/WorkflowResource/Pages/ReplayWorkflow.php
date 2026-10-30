<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowRunResource;
use App\Models\Workflows\WorkflowRun;
use App\Services\Workflows\WorkflowRunReplay;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

class ReplayWorkflow extends EditWorkflow
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
        abort_unless(Auth::id(), 403);
        $ownerId = (bool) Auth::user()?->is_root
            ? (int) WorkflowRun::withoutGlobalScope('tenant')->findOrFail((int) $run)->user_id
            : (int) Auth::id();
        $replay = WorkflowRunReplay::load((int) $run, $ownerId);

        parent::mount($replay['workflow_id']);
        $this->loadHistoricalRun($replay);
    }

    protected function loadHistoricalRun(array $replay): void
    {
        $this->replayRunId = $replay['run_id'];
        $this->replayWorkflowId = $replay['workflow_id'];
        $this->replayStartedAt = $replay['started_at'];
        $this->replayContext = $replay['context'];
        $this->replayRecordedOnly = $replay['historical_fallback'] ?? false;
        $this->cacheWorkflowDebugHistory($replay);
        $this->definition = $replay['definition'];
        $this->loadFromDefinition();
        $this->debugInputMode = 'json';
        $this->debugInput = json_encode($replay['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->debugStartNodeId = $replay['input']['_workflow_start_node_id'] ?? 'trigger';
        $this->debugOpen = true;
        $this->debugReal = $this->debugRealConfirmed = false;
        $this->debugState = ['status' => 'historical', 'real' => false, 'run_id' => $replay['run_id'], 'results' => $this->explainDebugResults($replay['results']),
            'start_node_id' => $this->debugStartNodeId,
            'display_trigger_data' => \App\Services\Workflows\WorkflowOutputView::trigger($replay['input']),
            'next_id' => null, 'error' => null];
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

    protected function refreshWorkflowRecordForSave(): void
    {
        abort_if($this->replayRecordedOnly, 409, 'Исходная схема запуска не сохранена. Доступен только просмотр записанных шагов.');
        parent::refreshWorkflowRecordForSave();
    }

    protected function refreshWorkflowRecordForRuntime(): void
    {
        abort_if($this->replayRecordedOnly, 409, 'Исходная схема запуска не сохранена. Доступен только просмотр записанных шагов.');
        parent::refreshWorkflowRecordForRuntime();
    }

    public function getTitle(): string
    {
        return 'Редактор сохранённого запуска';
    }

    public function getReplayBackUrl(): string
    {
        return WorkflowRunResource::getUrl('index', ['workflow_id' => $this->replayWorkflowId]);
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
