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

    #[Locked]
    public bool $replayRecordedOnly = false;

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

    #[Locked]
    public ?string $nodePreviewTaskId = null;

    #[Locked]
    public array $nodePreviewProgress = [];

    public ?int $debugSelectedResultIndex = null;

    #[Locked]
    public ?string $debugHistoryContextId = null;

    protected ?string $debugExecutingNodeId = null;

    public function inspectWorkflowDebugRun(int $runId): bool
    {
        if ($this->workflowPreviewBusy()) return false;
        $record = method_exists($this, 'getRecord') ? $this->getRecord() : null;
        abort_unless($record?->exists && Auth::id(), 404);
        // Check both identities before loading any saved context or replacing UI data.
        $replay = \App\Services\Workflows\WorkflowRunReplay::load($runId, (int) Auth::id(), (int) $record->getKey());
        $this->cacheWorkflowDebugHistory($replay);
        $this->debugSessionId = $this->nodePreviewSessionId = null;
        $this->debugSelectedResultIndex = null;
        $this->nodeRunResults = [];
        $this->debugInputMode = 'json';
        $this->debugRunId = $runId;
        $this->debugInput = json_encode($replay['input'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $this->debugStartNodeId = $replay['input']['_workflow_start_node_id'] ?? 'trigger';
        $this->debugReal = $this->debugRealConfirmed = false;
        $this->debugOpen = true;
        // Switching data never loads the historical definition into the browser draft.
        $this->debugState = ['status' => 'historical', 'real' => false, 'run_id' => $runId,
            'results' => $this->explainDebugResults($replay['results']), 'next_id' => null, 'error' => null,
            'start_node_id' => $this->debugStartNodeId,
            'display_trigger_data' => \App\Services\Workflows\WorkflowOutputView::trigger($replay['input'])];
        $this->dispatch('workflow-debug-updated', state: $this->debugState);
        return true;
    }

    protected function cacheWorkflowDebugHistory(array $replay): void
    {
        if ($this->debugHistoryContextId) Cache::forget($this->debugHistoryCacheKey());
        $this->debugHistoryContextId = (string) Str::uuid();
        Cache::put($this->debugHistoryCacheKey(), ['context' => $replay['context'], 'results' => $replay['results']], now()->addHour());
    }

    private function debugHistoryCacheKey(): string
    {
        return 'workflow-debug-history:'.(Auth::id() ?? 'guest').':'.$this->debugHistoryContextId;
    }

    public function runWorkflowCanvasNode(string $actionId): void
    {
        if ($this->workflowPreviewBusy()) return;
        if (!$this->applyLocalWorkflowInspectorDrafts(['action:'.$actionId])) return;
        $this->debugExecutingNodeId = $actionId;
        // The play icon uses an open form's draft, without requiring Apply or Save.
        if (($this->mountedActions[0]['name'] ?? '') === 'configureWorkflowAction' && $this->editingActionId === $actionId) {
            $this->runEditingWorkflowNode();
            return;
        }
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
                $this->publishNodePreviewResults($actionId);
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
        if ($this->workflowPreviewBusy()) return;
        $this->debugExecutingNodeId = $this->editingActionId;
        try {
            $data = $this->getMountedActionSchema(0)?->getState() ?? [];
            $input = $this->workflowNodeRunInput();
            if (($this->mountedActions[0]['name'] ?? '') === 'configureTrigger') {
                $this->saveTriggerNodeConfig($data);
                $this->nodeRunResults[$this->editingTriggerNodeId] = ['id' => $this->editingTriggerNodeId, 'status' => 'success', 'display_count' => 1, 'output' => $input + ['_workflow_start_node_id' => $this->editingTriggerNodeId]];
                $this->publishNodePreviewResults($this->editingTriggerNodeId);
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
            $id = $this->editingActionId ?? $this->editingTriggerNodeId;
            $this->nodeRunResults[$id] = ['id' => $id, 'status' => 'error', 'display_count' => 0, 'error' => $exception->getMessage()];
            $this->publishNodePreviewResults($id);
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
        // Every node button executes only that node. A loop just exposes its first item.
        $session = app(WorkflowDebugger::class)->executeNode($step, $this->workflowDebugContext(), $input, $record?->exists ? $record->getKey() : null, Auth::id(), true, $this->definition);
        $this->applyWorkflowNodePreview($step['id'], $session);
    }

    private function applyWorkflowNodePreview(string $nodeId, array $session): void
    {
        foreach (\App\Services\Workflows\WorkflowExecutionStats::annotate($session['results']) as $result) {
            $this->nodeRunResults[$result['id']] = array_replace($result, $this->explainDebugResults([$result])[0], ['real' => $session['real'] ?? true]);
        }
        if ($session['error'] ?? null) $this->nodeRunResults[$nodeId] = array_replace($this->nodeRunResults[$nodeId] ?? [], ['id' => $nodeId, 'status' => 'error', 'error' => $session['error']]);
        $this->nodePreviewSessionId ??= (string) Str::uuid();
        Cache::put($this->nodePreviewCacheKey(), $session['context'], now()->addHour());
        $this->publishNodePreviewResults($nodeId);
    }

    public function pollWorkflowNodePreview(): void
    {
        if (!$this->nodePreviewTaskId) return;
        $previews = app(\App\Services\Workflows\WorkflowNodePreview::class);
        $id = $this->nodePreviewTaskId;
        $state = $previews->status((int) Auth::id(), $id);
        if ($state && in_array($state['status'], ['queued', 'running'], true)) {
            $this->nodePreviewProgress = $state;
            return;
        }
        $nodeId = $state['node_id'] ?? $this->nodePreviewProgress['node_id'] ?? '';
        if ($session = $previews->result((int) Auth::id(), $id)) {
            $this->applyWorkflowNodePreview($nodeId, $session);
        }
        if (!$state || !empty($state['error'])) {
            $this->nodeRunResults[$nodeId] = array_replace($this->nodeRunResults[$nodeId] ?? [], ['id' => $nodeId, 'status' => 'error',
                'error' => $state['error'] ?? 'Данные пробного запуска истекли. Проверьте результат перед повторным запуском.']);
            $this->publishNodePreviewResults($nodeId);
        }
        $previews->acknowledge((int) Auth::id(), $id);
        $this->nodePreviewTaskId = null;
        $this->nodePreviewProgress = [];
    }

    public function stopWorkflowNodePreview(): void
    {
        if (!$this->nodePreviewTaskId) return;
        app(\App\Services\Workflows\WorkflowNodePreview::class)->cancel((int) Auth::id(), $this->nodePreviewTaskId);
        $this->pollWorkflowNodePreview();
    }

    private function workflowPreviewBusy(): bool
    {
        $this->nodePreviewTaskId ??= app(\App\Services\Workflows\WorkflowNodePreview::class)->activeId((int) Auth::id(), $this->getId());
        $this->pollWorkflowNodePreview();
        if (!$this->nodePreviewTaskId) return false;
        Notification::make()->warning()->title('Цикл уже выполняется')->body('Дождитесь результата или остановите пробный запуск.')->send();
        return true;
    }

    private function publishNodePreviewResults(string $previewNodeId): void
    {
        $results = collect($this->debugState['results'] ?? [])->keyBy('id')->all();
        foreach ($this->nodeRunResults as $nodeId => $result) {
            if (isset($result['id'])) $results[$nodeId] = $result;
        }
        $this->dispatch('workflow-debug-updated', state: ['results' => array_values($results), 'preview_node_id' => $previewNodeId]);
    }

    public function editingNodeResult(): array
    {
        $id = ($this->mountedActions[0]['name'] ?? '') === 'configureTrigger' ? $this->editingTriggerNodeId : $this->editingActionId;
        return $this->nodeRunResults[$id] ?? collect($this->debugState['results'] ?? [])->firstWhere('id', $id) ?? [];
    }

    public function openWorkflowDebugger(): void
    {
        $this->debugOpen = true;
    }

    public function closeWorkflowDebugger(): void
    {
        $this->debugOpen = false;
    }

    public function editWorkflowDebugInput(): void
    {
        if ($this->workflowPreviewBusy()) return;
        if ($this->debugSessionId) Cache::forget($this->debugCacheKey());
        if ($this->nodePreviewSessionId) Cache::forget($this->nodePreviewCacheKey());
        if ($this->debugHistoryContextId) Cache::forget($this->debugHistoryCacheKey());
        $this->debugHistoryContextId = null;
        $this->debugSelectedResultIndex = null;
        $this->debugSessionId = $this->nodePreviewSessionId = null;
        $this->nodeRunResults = $this->debugState = [];
        $this->debugReal = $this->debugRealConfirmed = false;
        $this->resetValidation('debugInput');
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
        if ($this->workflowPreviewBusy()) return false;
        try {
            if (!$this->applyLocalWorkflowInspectorDrafts()) return false;
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
            $this->debugHistoryContextId = null;
            $this->debugSelectedResultIndex = null;
            $this->nodePreviewSessionId = null;
            $this->nodeRunResults = [];
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
        if ($this->workflowPreviewBusy()) return false;
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
        if ($this->debugSelectedResultIndex !== null) {
            $saved = $this->debugHistoryContextId ? Cache::get($this->debugHistoryCacheKey())
                : ($this->debugSessionId ? Cache::get($this->debugCacheKey()) : null);
            if (!is_array($saved)) throw new \InvalidArgumentException('Данные отладки истекли. Выберите запуск заново.');
            $context = $saved['context'];
            $context['variables']['_definition_snapshot'] ??= $saved['definition'] ?? [];
            return \App\Services\Workflows\WorkflowRunReplay::contextAt($context, $saved['results'], $this->debugSelectedResultIndex, $this->debugExecutingNodeId);
        }
        if ($this->nodePreviewSessionId) return Cache::get($this->nodePreviewCacheKey(), []);
        if ($this->debugHistoryContextId) {
            $saved = Cache::get($this->debugHistoryCacheKey());
            if (!is_array($saved)) throw new \InvalidArgumentException('Данные запуска истекли. Выберите запуск заново.');
            return $saved['context'];
        }
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
        $triggerData = data_get($session, 'context.trigger_data', []);
        $this->debugState = [
            'status' => $session['status'], 'real' => $session['real'], 'results' => $this->explainDebugResults(\App\Services\Workflows\WorkflowExecutionStats::annotate($session['results'])),
            'start_node_id' => $triggerData['_workflow_start_node_id'] ?? 'trigger',
            'display_trigger_data' => \App\Services\Workflows\WorkflowOutputView::trigger($triggerData),
            'next_id' => $session['pending'][0]['step']['id'] ?? null,
            'error' => $session['error'],
        ];
        $this->dispatch('workflow-debug-updated', state: $this->debugState);
    }

    protected function explainDebugResults(array $results): array
    {
        return array_map(static function (array $result): array {
            $result['explanation'] = \App\Services\Workflows\WorkflowExecutionExplanation::forResult($result);
            $result['display_input'] ??= ['message' => 'Входные данные этого шага не записаны.'];
            $branch = \App\Services\Workflows\WorkflowOutputView::conditionBranch($result);
            $result['display_output'] ??= in_array($result['type'] ?? '', ['condition', 'control-condition'], true)
                ? ($branch ? 'Ветка «'.$branch.'»' : ['message' => 'Ветка не записана.'])
                : \App\Services\Workflows\WorkflowOutputView::displayValue($result['output'] ?? []);
            // These are editor settings, not the data that entered the node.
            unset($result['input'], $result['resolved_input']);

            return $result;
        }, $results);
    }
}
