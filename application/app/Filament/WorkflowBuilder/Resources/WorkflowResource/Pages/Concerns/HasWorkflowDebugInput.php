<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use App\Models\Core\Account;
use App\Models\Workflows\WorkflowRun;
use App\Services\Workflows\WorkflowDebugInput;
use App\Services\Workflows\WorkflowStartNodes;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait HasWorkflowDebugInput
{
    public string $debugInputMode = 'json';
    public string $debugEntity = 'lead';
    public string $debugEvent = 'manual';
    public string $debugEntityId = '';
    public array $debugFields = [];
    public ?int $debugRunId = null;
    #[Locked] public bool $debugBuilderInitialized = false;
    #[Locked] public array $debugLoadedEntity = [];
    #[Locked] public array $debugLoadedAccount = [];
    #[Locked] public string $debugInputSource = '';

    protected function initializeWorkflowDebugInput(): void
    {
        if ($this->debugBuilderInitialized) return;
        $this->debugBuilderInitialized = true;
        if (trim($this->debugInput) !== '{}') return;
        $this->debugInputMode = 'builder';
        $this->selectDebugStartDefaults();
    }

    private function selectDebugStartDefaults(): void
    {
        $start = WorkflowStartNodes::all(array_merge($this->definition, ['trigger' => $this->trigger]))[$this->debugStartNodeId] ?? $this->trigger ?? [];
        $entity = $start['config']['entity'] ?? (($start['type'] ?? '') === 'generic-webhook' ? 'payload' : 'lead');
        $this->debugEntity = isset(WorkflowDebugInput::entities()[$entity]) ? $entity : 'lead';
        $this->updatedDebugEntity();
        $event = $start['config']['event'] ?? ($this->debugEntity === 'payload' ? 'webhook' : 'manual');
        if (isset(WorkflowDebugInput::events($this->debugEntity)[$event])) $this->debugEvent = $event;
    }

    public function updatedDebugEntity(): void
    {
        $this->debugLoadedEntity = $this->debugLoadedAccount = [];
        $this->debugEntityId = $this->debugInputSource = '';
        $this->debugEvent = $this->debugEntity === 'payload' ? 'webhook' : 'manual';
        $this->debugFields = array_values(array_filter(WorkflowDebugInput::defaults($this->debugEntity), fn ($row) => $row['key'] !== 'id'));
        $this->resetValidation('debugInputBuilder');
    }

    public function updatedDebugStartNodeId(): void
    {
        if ($this->debugInputMode === 'builder') $this->selectDebugStartDefaults();
    }

    public function updatedDebugInputMode(): void
    {
        $this->debugInputSource = '';
        $this->resetValidation('debugInputBuilder');
    }

    public function addDebugInputField(): void
    {
        if (count($this->debugFields) >= 49) return;
        $this->debugFields[] = ['key' => '__custom', 'path' => '', 'type' => 'text', 'value' => ''];
    }

    public function removeDebugInputField(int $index): void
    {
        unset($this->debugFields[$index]);
        $this->debugFields = array_values($this->debugFields);
    }

    public function loadDebugEntity(): void
    {
        $this->resetValidation('debugInputBuilder');
        try {
            $user = auth()->user();
            $account = $user?->resolveAmoAccountForWidget('workflows');
            if (!$account instanceof Account) throw ValidationException::withMessages(['debugInputBuilder' => 'Подключите amoCRM или задайте пример вручную.']);
            $item = app(WorkflowDebugInput::class)->load($account, (int) $user->id, $this->debugEntity, trim($this->debugEntityId));
            $this->debugLoadedEntity = $item;
            $this->debugLoadedAccount = $account->only(['id', 'user_id', 'subdomain']);
            $this->debugFields = array_values(array_filter(WorkflowDebugInput::defaults($this->debugEntity, $item), fn ($row) => $row['key'] !== 'id'));
            $this->debugInputSource = 'Загружено из amoCRM: '.($item['name'] ?? WorkflowDebugInput::entities()[$this->debugEntity]).' #'.$item['id'].'. Поля ниже можно изменить для проверки.';
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            report($error);
            $this->addError('debugInputBuilder', 'Не удалось загрузить сущность. Проверьте ID и подключение amoCRM; можно задать пример вручную.');
        }
    }

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
        $this->resetValidation('debugInputBuilder');
        if ($this->debugInputMode === 'json') return;
        if ($this->debugInputMode === 'history') {
            $record = method_exists($this, 'getRecord') ? $this->getRecord() : null;
            if (!$record?->exists || !auth()->id() || !$this->debugRunId) throw ValidationException::withMessages(['debugInputBuilder' => 'Выберите прошлый запуск этого сценария.']);
            $run = WorkflowRun::query()->where('user_id', auth()->id())->where('workflow_id', $record->getKey())->find($this->debugRunId);
            $data = data_get($run?->context_data, 'trigger_data');
            if (!is_array($data)) throw ValidationException::withMessages(['debugInputBuilder' => 'Запуск недоступен или его входные данные не сохранены.']);
            unset($data['_workflow_start_node_id']);
            $this->debugInputSource = 'Данные прошлого запуска #'.$run->id.'. Выполнение пойдёт по текущей схеме.';
        } elseif ($this->debugInputMode === 'builder') {
            if ($this->debugLoadedEntity && (string) ($this->debugLoadedEntity['id'] ?? '') !== trim($this->debugEntityId)) {
                throw ValidationException::withMessages(['debugInputBuilder' => 'ID изменён. Загрузите новую сущность или сбросьте загруженные данные.']);
            }
            $rows = $this->debugFields;
            if ($this->debugEntity !== 'payload') array_unshift($rows, ['key' => 'id', 'type' => 'number', 'value' => trim($this->debugEntityId)]);
            $data = WorkflowDebugInput::build($this->debugEntity, $this->debugEvent, $rows, $this->debugLoadedEntity, $this->debugLoadedAccount);
        } else {
            throw ValidationException::withMessages(['debugInputBuilder' => 'Выберите источник данных.']);
        }
        $this->debugInput = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function resetLoadedDebugEntity(): void
    {
        $this->debugLoadedEntity = $this->debugLoadedAccount = [];
        $this->debugInputSource = '';
        $this->resetValidation('debugInputBuilder');
    }

    public function previewDebugInput(): void
    {
        $this->prepareWorkflowDebugInput();
    }
}
