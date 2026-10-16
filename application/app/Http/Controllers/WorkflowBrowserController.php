<?php

namespace App\Http\Controllers;

use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowAdminAccess;
use App\Services\Workflows\WorkflowBrowserDocument;
use App\Services\Workflows\WorkflowExecutionStats;
use App\Services\Workflows\WorkflowGraph;
use App\Services\Workflows\WorkflowNodeReferences;
use App\Services\Workflows\WorkflowOutputView;
use App\Services\Workflows\WorkflowStartNodes;
use App\Workflows\Engine\WorkflowDebugger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class WorkflowBrowserController extends Controller
{
    private function owned(int $id): Workflow
    {
        $workflow = Workflow::query()->findOrFail($id);
        if (WorkflowAdminAccess::isRoot()) {
            WorkflowAdminAccess::useWorkflowOwner($workflow);
        }
        abort_unless((int) $workflow->user_id === (int) auth()->id(), 404);

        return $workflow;
    }

    public function save(Request $request, WorkflowBrowserDocument $documents, ?int $workflow = null): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 4 * 1024 * 1024, 413, 'Сценарий превышает 4 МБ.');
        $owned = $workflow ? $this->owned($workflow) : null;

        return response()->json($documents->save($owned, $request->all()));
    }

    public function references(Request $request, WorkflowBrowserDocument $documents, int $workflow): JsonResponse
    {
        $this->owned($workflow);
        if ($request->boolean('refresh')) {
            $data = $request->validate(['type' => 'required|string|max:100', 'config' => 'array']);
            if (WorkflowNodeReferences::plan($data['type'], $data['config'] ?? []) !== []) {
                app(WorkflowNodeReferences::class)->refresh($data['type'], $data['config'] ?? []);
            }
        }

        return response()->json($documents->references());
    }

    public function renameNode(Request $request, WorkflowBrowserDocument $documents, int $workflow): JsonResponse
    {
        return response()->json($documents->renameNode($this->owned($workflow), $request->all()));
    }

    public function duplicate(Request $request, WorkflowBrowserDocument $documents, int $workflow): JsonResponse
    {
        $owned = $this->owned($workflow);
        $payload = $request->all();
        data_set($payload, 'document.is_active', false);

        return response()->json($documents->save(null, $payload, $owned));
    }

    public function delete(Request $request, WorkflowBrowserDocument $documents, int $workflow): JsonResponse
    {
        $owned = $this->owned($workflow);
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $documents, $owned): void {
            $locked = Workflow::query()->where('user_id', auth()->id())->lockForUpdate()->findOrFail($owned->id);
            abort_unless(hash_equals($documents->version($locked), (string) $request->input('version')), 409, 'Сценарий изменился. Обновите страницу перед удалением.');
            $locked->delete();
        });

        return response()->json(['deleted' => true]);
    }

    /** Runtime only. Ordinary graph/form editing never calls this endpoint. */
    public function debug(Request $request, WorkflowBrowserDocument $documents, int $workflow): JsonResponse
    {
        $owned = $this->owned($workflow);
        $data = $request->validate(['command' => 'required|in:start,step,node,input', 'session' => 'nullable|uuid',
            'definition' => 'nullable|array', 'input' => 'nullable|array', 'nodeId' => 'nullable|string|max:150', 'real' => 'boolean']);
        if ($data['command'] === 'input') {
            $startId = $data['nodeId'] ?? 'trigger';
            $start = WorkflowStartNodes::all($owned->definition)[$startId] ?? null;
            abort_unless($start, 422, 'Выбранный запуск не найден.');
            $preview = ($start['type'] ?? '') === 'generic-webhook'
                ? app(\App\Services\Workflows\WorkflowGenericWebhookService::class)->latestPreview($owned) : null;
            $run = \App\Models\Workflows\WorkflowRun::query()->where('user_id', auth()->id())
                ->where('workflow_id', $owned->id)
                ->where(function ($query) use ($startId): void {
                    $query->where('context_data->trigger_data->_workflow_start_node_id', $startId);
                    if ($startId === 'trigger') $query->orWhereNull('context_data->trigger_data->_workflow_start_node_id');
                })->latest('created_at')->first();

            $input = $preview ? ['body' => $preview['payload'], 'headers' => $preview['headers']] : data_get($run?->context_data, 'trigger_data', []);
            return response()->json(['input' => $input, 'display_input' => WorkflowOutputView::trigger($input)]);
        }
        $definition = $documents->restoreSecrets($data['definition'] ?? [], $owned->definition);
        $key = 'workflow-browser-debug:'.auth()->id().':'.$owned->id.':'.($data['session'] ?? Str::uuid());
        // A repeated click must not execute the same step twice concurrently.
        $session = Cache::lock($key.':lock', 120)->block(1, function () use ($key, $data, $definition, $owned): array {
            $engine = app(WorkflowDebugger::class);
            $previous = Cache::get($key);
            if ($data['command'] === 'step') {
                abort_unless($previous && ($previous['input_definition'] ?? $previous['definition']) === $definition, 409, 'Схема изменена или сессия истекла. Начните отладку заново.');
                $session = $engine->advance($previous);
            } elseif ($data['command'] === 'node') {
                $step = WorkflowGraph::nodes($definition['actions'] ?? [])[$data['nodeId'] ?? '']['step'] ?? null;
                abort_unless($step, 422, 'Нода не найдена.');
                $session = $engine->executeNode($step, $previous['context'] ?? [], $data['input'] ?? [], $owned->id, (int) auth()->id(), (bool) ($data['real'] ?? false), $definition);
                $prior = $previous['results'] ?? [];
                foreach ($session['results'] as &$result) {
                    $result['sequence'] = count($prior) + 1;
                }
                unset($result);
                $session['results'] = [...$prior, ...$session['results']];
            } else {
                $session = $engine->start($definition, $data['input'] ?? [], $owned->id, (int) auth()->id(), (bool) ($data['real'] ?? false));
            }
            Cache::put($key, $session, now()->addHour());

            return $session;
        });
        $results = WorkflowExecutionStats::annotate($session['results']);
        foreach ($results as &$result) {
            $result['display_input'] ??= ['message' => 'Входные данные этого шага не записаны.'];
            $result['display_output'] ??= WorkflowOutputView::displayValue($result['output'] ?? null);
            $result['explanation'] = \App\Services\Workflows\WorkflowExecutionExplanation::forResult($result);
            unset($result['input'], $result['resolved_input']);
        }

        return response()->json(['session' => Str::afterLast($key, ':'), 'status' => $session['status'], 'real' => $session['real'], 'results' => $results,
            'start_node_id' => data_get($session, 'context.trigger_data._workflow_start_node_id', 'trigger'),
            'error' => $session['error'], 'next_id' => $session['pending'][0]['step']['id'] ?? null]);
    }
}
