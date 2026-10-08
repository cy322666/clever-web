<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use App\Workflows\Engine\WorkflowDebugger;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Throwable;

trait HasWorkflowDebugger
{
    use HasWorkflowDebugInput;
    public bool $debugOpen = false;

    public bool $debugReal = false;

    public bool $debugRealConfirmed = false;

    public string $debugInput = '{}';
    public string $debugStartNodeId = 'trigger';

    #[Locked]
    public ?string $debugSessionId = null;

    #[Locked]
    public array $debugState = [];

    #[Locked]
    public array $nodeRunResults = [];

    #[Locked]
    public ?string $nodePreviewSessionId = null;

    public function runWorkflowCanvasNode(string $actionId): void
    {
        $node = \App\Services\Workflows\WorkflowGraph::nodes($this->workflowActions)['action:'.$actionId] ?? null;
        abort_unless($node, 404);
        Cache::lock('workflow-node-run:'.(Auth::id() ?? 'guest').':'.$this->getId(), 120)->get(function () use ($actionId, $node): void {
            try {
                if ($this->debugOpen) $this->prepareWorkflowDebugInput();
                $this->syncDefinition();
                // Canvas play must not mount a form, change selection or discard a draft.
                $this->executeWorkflowNodePreview($node['step'], $this->workflowNodeRunInput());
            } catch (Throwable $exception) {
                $this->nodeRunResults[$actionId] = ['id' => $actionId, 'status' => 'error', 'error' => $exception->getMessage()];
                $this->publishNodePreviewResults();
            }
            $result = $this->nodeRunResults[$actionId] ?? [];
            if (in_array($result['status'] ?? '', ['error', 'failed', 'validation_error'], true)) {
                $names = \App\Services\Workflows\WorkflowExpressionCatalog::referenceNames($this->workflowActions, $this->definition);
                Notification::make()->danger()->title($names[$actionId] ?? 'Нода')
                    ->body($result['error'] ?? 'Не удалось выполнить ноду.')->send();
            }
        });
    }

    public function runEditingWorkflowNode(): void
    {
        try {
            $data = $this->getMountedActionSchema(0)?->getState() ?? [];
            $input = $this->workflowNodeRunInput();
            if (($this->mountedActions[0]['name'] ?? '') === 'configureTrigger') {
                $this->saveTriggerNodeConfig($data);
                $this->nodeRunResults[$this->editingTriggerNodeId] = ['status' => 'success', 'output' => $input + ['_workflow_start_node_id' => $this->editingTriggerNodeId]];
                return;
            }
            $id = $this->editingActionId;
            if (!$id) return;
            $name = $data['_action_name'] ?? null;
            unset($data['_action_name']);
            $this->updateWorkflowActionConfig($id, $data, $name);
            $this->executeWorkflowNodePreview($this->getEditingWorkflowAction(), $input);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->nodeRunResults[$this->editingActionId ?? $this->editingTriggerNodeId] = ['status' => 'error', 'error' => $exception->getMessage()];
        }
    }

    private function workflowNodeRunInput(): array
    {
        $input = $this->workflowNodeInputContext()['trigger_data'] ?? [];
        $this->debugInput = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $input;
    }

    /** A single source of truth for both the data picker and node execution. */
    private function workflowNodeInputContext(): array
    {
        $context = $this->workflowDebugContext();
        if ($context !== []) return $context;
        $record = method_exists($this, 'getRecord') ? $this->getRecord() : null;
        $input = json_decode($this->debugInput, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($input) || ($input !== [] && array_is_list($input))) throw new \InvalidArgumentException('Нужен JSON-объект входных данных.');
        $startId = (($this->mountedActions[0]['name'] ?? '') === 'configureTrigger')
            ? $this->editingTriggerNodeId : $this->debugStartNodeId;
        $starts = \App\Services\Workflows\WorkflowStartNodes::all($this->definition);
        if (!isset($starts[$startId])) $startId = 'trigger';
        if ($input !== []) return ['trigger_data' => $input + ['_workflow_start_node_id' => $startId]];
        if ($record?->exists && (int) $record->user_id === (int) Auth::id()) {
            // Only this selected webhook start may use webhook preview data.
            if (($starts[$startId]['type'] ?? '') === 'generic-webhook') {
                $preview = app(\App\Services\Workflows\WorkflowGenericWebhookService::class)->latestPreview($record);
                if ($preview) return ['trigger_data' => [
                    '_workflow_start_node_id' => $startId,
                    'body' => $preview['payload'], 'payload' => $preview['payload'],
                    'query' => $preview['query'], 'headers' => $preview['headers'],
                    'method' => $preview['method'], 'received_at' => $preview['received_at'],
                ]];
            }
            $lastRun = \App\Services\Workflows\WorkflowRunReplay::ownedRuns((int) Auth::id())
                ->where('workflow_id', $record->getKey())
                ->where(function ($query) use ($startId): void {
                    $query->where('context_data->trigger_data->_workflow_start_node_id', $startId);
                    if ($startId === 'trigger') $query->orWhereNull('context_data->trigger_data->_workflow_start_node_id');
                })->latest('id')->first();
            $input = $lastRun?->context_data['trigger_data'] ?? [];
            if ($input !== []) {
                $this->debugInputSource = 'Данные запуска #'.$lastRun->getKey();
                return ['trigger_data' => $input + ['_workflow_start_node_id' => $startId]];
            }
        }
        return ['trigger_data' => ['_workflow_start_node_id' => $startId]];
    }

    private function executeWorkflowNodePreview(array $step, array $input): void
    {
        $record = method_exists($this, 'getRecord') ? $this->getRecord() : null;
        // A loop preview executes its body once; ordinary play still executes only one node.
        $session = app(WorkflowDebugger::class)->executeNode($step, $this->workflowDebugContext(), $input, $record?->exists ? $record->getKey() : null, Auth::id(), true, $this->definition);
        foreach ($session['results'] as $result) $this->nodeRunResults[$result['id']] = $result;
        if ($session['error'] ?? null) $this->nodeRunResults[$step['id']] = ['id' => $step['id'], 'status' => 'error', 'error' => $session['error']];
        $this->nodePreviewSessionId ??= (string) Str::uuid();
        Cache::put($this->nodePreviewCacheKey(), $session['context'], now()->addHour());
        $this->publishNodePreviewResults();
    }

    private function publishNodePreviewResults(): void
    {
        $results = collect($this->debugState['results'] ?? [])->keyBy('id')->all();
        foreach ($this->nodeRunResults as $nodeId => $result) {
            if (isset($result['id'])) $results[$nodeId] = $result;
        }
        $this->dispatch('workflow-debug-updated', state: ['results' => array_values($results)]);
    }

    public function editingNodeResult(): array
    {
        $id = ($this->mountedActions[0]['name'] ?? '') === 'configureTrigger' ? $this->editingTriggerNodeId : $this->editingActionId;
        return $this->nodeRunResults[$id] ?? collect($this->debugState['results'] ?? [])->firstWhere('id', $id) ?? [];
    }

    public function openWorkflowDebugger(): void
    {
        $this->debugOpen = true;
        $this->unmountAction();
        $this->initializeWorkflowDebugInput();
    }

    public function closeWorkflowDebugger(): void
    {
        $this->debugOpen = false;
    }

    public function editWorkflowDebugInput(): void
    {
        if ($this->debugSessionId) Cache::forget($this->debugCacheKey());
        if ($this->nodePreviewSessionId) Cache::forget($this->nodePreviewCacheKey());
        $this->debugSessionId = $this->nodePreviewSessionId = null;
        $this->nodeRunResults = $this->debugState = [];
        $this->debugReal = $this->debugRealConfirmed = false;
        $this->resetValidation('debugInputBuilder');
        $this->dispatch('workflow-debug-updated', state: ['results' => []]);
    }

    public function selectWorkflowCanvasNode(string $actionId): void
    {
        if ($this->debugOpen) {
            $this->dispatch('workflow-debug-select', id: $actionId);

            return;
        }
        $this->openWorkflowActionEditor($actionId);
    }

    public function startWorkflowDebug(): bool
    {
        try {
            $this->prepareWorkflowDebugInput();
            if ($this->debugReal && ! $this->debugRealConfirmed) {
                throw new \InvalidArgumentException('Подтвердите, что действия будут реально изменять данные.');
            }
            $input = json_decode($this->debugInput, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($input) || (array_is_list($input) && $input !== [])) {
                throw new \InvalidArgumentException('Входные данные должны быть JSON-объектом.');
            }
            $this->syncDefinition();
            $input['_workflow_start_node_id'] = \App\Services\Workflows\WorkflowStartNodes::selected($this->definition, ['_workflow_start_node_id' => $this->debugStartNodeId]);
            $record = method_exists($this, 'getRecord') ? $this->getRecord() : null;
            $session = app(WorkflowDebugger::class)->start($this->definition, $input, $record?->exists ? $record->getKey() : null, Auth::id(), $this->debugReal);
            $this->debugSessionId = (string) Str::uuid();
            $this->nodePreviewSessionId = null;
            Cache::put($this->debugCacheKey(), $session, now()->addHour());
            $this->publishDebugState($session);
            return true;
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Не удалось начать отладку')->body($exception->getMessage())->send();
            return false;
        }
    }

    public function nextWorkflowDebugStep(): bool
    {
        if (! $this->debugSessionId) {
            return false;
        }

        return Cache::lock($this->debugCacheKey().':lock', 120)->get(function (): bool {
            $session = Cache::get($this->debugCacheKey());
            if (! is_array($session)) {
                $this->debugState = ['status' => 'expired', 'results' => [], 'error' => 'Отладка истекла. Начните новый запуск.'];

                return false;
            }
            $this->syncDefinition();
            if ($session['definition'] !== $this->definition) {
                $session['status'] = 'stale';
                $session['error'] = 'Схема изменилась. Начните новый запуск отладки.';
            }
            $session = app(WorkflowDebugger::class)->advance($session);
            Cache::put($this->debugCacheKey(), $session, now()->addHour());
            $this->publishDebugState($session);

            return $session['status'] === 'ready';
        }) ?: false;
    }

    protected function workflowDebugContext(): array
    {
        if ($this->nodePreviewSessionId) return Cache::get($this->nodePreviewCacheKey(), []);
        return $this->debugSessionId ? (Cache::get($this->debugCacheKey())['context'] ?? []) : [];
    }

    private function nodePreviewCacheKey(): string
    {
        return 'workflow-node-preview:'.(Auth::id() ?? 'guest').':'.$this->nodePreviewSessionId;
    }

    public function getWorkflowExpressionSources(): array
    {
        try {
            $context = $this->workflowNodeInputContext();
        } catch (\JsonException|\InvalidArgumentException) {
            // The run button reports invalid draft JSON; the editor must remain usable.
            $context = [];
        }

        return \App\Services\Workflows\WorkflowExpressionCatalog::sources($this->workflowActions, $this->editingActionId, $context, $this->definition['connections'] ?? null, $this->definition);
    }

    private function debugCacheKey(): string
    {
        return 'workflow-debug:'.(Auth::id() ?? 'guest').':'.$this->debugSessionId;
    }

    private function publishDebugState(array $session): void
    {
        $this->debugState = [
            'status' => $session['status'], 'real' => $session['real'], 'results' => $session['results'],
            'next_id' => $session['pending'][0]['step']['id'] ?? null,
            'error' => $session['error'],
        ];
        $this->dispatch('workflow-debug-updated', state: $this->debugState);
    }
}
