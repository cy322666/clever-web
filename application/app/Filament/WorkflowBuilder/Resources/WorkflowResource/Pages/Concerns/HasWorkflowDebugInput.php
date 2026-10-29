<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use App\Models\Workflows\WorkflowRun;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait HasWorkflowDebugInput
{
    public string $debugInputMode = 'json';
    public ?int $debugRunId = null;
    #[Locked] public string $debugInputSource = '';

    public function updatedDebugStartNodeId(): void
    {
        $this->editWorkflowDebugInput();
        $this->debugInput = '{}';
    }

    public function updatedDebugInputMode(): void
    {
        $this->debugInputSource = '';
        $this->resetValidation('debugInput');
    }

    #[\Livewire\Attributes\Renderless]
    public function recentDebugRuns(): array
    {
        $record = method_exists($this, 'getRecord') ? $this->getRecord() : null;
        if (!$record?->exists || !auth()->id()) return [];
        return WorkflowRun::query()->where('user_id', auth()->id())->where('workflow_id', $record->getKey())
            ->latest('id')->limit(25)->get(['id', 'status', 'started_at', 'created_at'])->mapWithKeys(fn ($run) => [
                $run->id => ($run->started_at ?? $run->created_at)?->timezone('Europe/Moscow')->format('d.m.Y H:i:s').' · '.($run->status?->getLabel() ?? 'Запуск'),
            ])->all();
    }

    protected function prepareWorkflowDebugInput(): void
    {
        $this->resetValidation('debugInput');
        if ($this->debugInputMode === 'json') return;
        if ($this->debugInputMode !== 'history') {
            throw ValidationException::withMessages(['debugInput' => 'Выберите JSON или прошлый запуск.']);
        }
        $record = method_exists($this, 'getRecord') ? $this->getRecord() : null;
        if (!$record?->exists || !auth()->id() || !$this->debugRunId) throw ValidationException::withMessages(['debugInput' => 'Выберите прошлый запуск этого сценария.']);
        $run = WorkflowRun::query()->where('user_id', auth()->id())->where('workflow_id', $record->getKey())->find($this->debugRunId);
        $data = data_get($run?->context_data, 'trigger_data');
        if (!is_array($data)) throw ValidationException::withMessages(['debugInput' => 'Запуск недоступен или его входные данные не сохранены.']);
        unset($data['_workflow_start_node_id']);
        $this->debugInputSource = 'Данные прошлого запуска #'.$run->id.'. Выполнение пойдёт по текущей схеме.';
        $this->debugInput = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function previewDebugInput(): void
    {
        $this->prepareWorkflowDebugInput();
    }
}
