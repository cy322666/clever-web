<?php

namespace App\Services\Workflows;

use App\Models\Workflows\Workflow;
use App\Workflows\Actions\TelegramSendMessageAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Triggers\TriggerRegistry;

/** One browser document. No Filament form state or repeated graph in Livewire snapshots. */
final class WorkflowBrowserDocument
{
    public function version(Workflow $workflow): string
    {
        return hash('sha256', json_encode([$workflow->getKey(), $workflow->name, $workflow->group_name,
            $workflow->is_active, $workflow->failure_strategy, $workflow->definition, (string) $workflow->updated_at], JSON_THROW_ON_ERROR));
    }

    public function document(?Workflow $workflow = null, ?string $folder = null): array
    {
        return ['id' => $workflow?->getKey(), 'name' => $workflow?->name ?? 'Новый процесс',
            'group_name' => $workflow?->group_name ?? $folder, 'is_active' => (bool) $workflow?->is_active,
            'failure_strategy' => $workflow?->failure_strategy ?? 'stop',
            'definition' => $this->forBrowser($workflow?->definition ?? ['trigger' => null, 'actions' => [], 'connections' => []])];
    }

    public function boot(?Workflow $workflow = null, ?string $folder = null): array
    {
        $actions = [];
        foreach (app(ActionRegistry::class)->getAllWithMetadata() as $item) {
            if (! ($item['available'] ?? true) || in_array($item['type'], [...\App\Workflows\Actions\WorkflowAmoCrmActionCatalog::unsupportedWorkflowTypes(), 'run_workflow'], true)) {
                continue;
            }
            $appearance = WorkflowNodeAppearance::action($item['type'], [], $item['icon']);
            $actions[$item['type']] = ['type' => $item['type'], 'name' => $item['name'], 'category' => $item['category'],
                'defaults' => app(ActionRegistry::class)->getDefaultConfig($item['type']), 'appearance' => $appearance,
                'icon' => view('components.workflow-node-icon', ['appearance' => $appearance, 'attributes' => new \Illuminate\View\ComponentAttributeBag])->render()];
        }
        $triggers = [];
        foreach (app(TriggerRegistry::class)->getAllWithMetadata() as $item) {
            $appearance = WorkflowNodeAppearance::trigger($item['type'], $item['icon']);
            $triggers[$item['type']] = ['type' => $item['type'], 'name' => $item['name'], 'category' => 'Запуски',
                'defaults' => app(TriggerRegistry::class)->getDefaultConfig($item['type']), 'appearance' => $appearance,
                'icon' => view('components.workflow-node-icon', ['appearance' => $appearance, 'attributes' => new \Illuminate\View\ComponentAttributeBag])->render()];
        }
        $operations = WorkflowAmoReadCatalog::operations();
        foreach ($operations as $key => &$operation) {
            $operation['variants'] = WorkflowAmoReadCatalog::variantOptions($key);
            $operation['with'] = WorkflowAmoReadCatalog::withOptions($key);
        }
        unset($operation);

        return ['document' => $this->document($workflow, $folder), 'version' => $workflow ? $this->version($workflow) : null,
            'actions' => $actions, 'triggers' => $triggers, 'operations' => $operations,
            'readLibrary' => WorkflowAmoReadCatalog::availableOperations(), 'references' => $this->references(),
            'folders' => WorkflowFolders::options(), 'csrf' => csrf_token(),
            'codeEditorUrl' => \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('code-editor', 'filament/forms'),
            'baseUrl' => url('/workflow-editor'), 'listUrl' => \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('index'),
            'historyUrl' => $workflow ? \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('history', ['record' => $workflow]) : null,
            'webhookUrl' => $workflow ? app(WorkflowGenericWebhookService::class)->callbackUrl($workflow) : null];
    }

    public function references(): array
    {
        $userId = (int) auth()->id();
        $pipelines = \App\Models\amoCRM\Status::query()->where('user_id', $userId)->where('active', true)->orderBy('sort')->get();
        $fields = \App\Models\amoCRM\Field::query()->where('user_id', $userId)->where('active', true)->orderBy('sort')->get();
        $query = [];
        foreach (['leads', 'contacts', 'companies', 'customers', 'tasks'] as $entity) {
            $query[$entity.'.list'] = ['fields' => WorkflowEntityQuery::fields($entity.'.list', $userId), 'sorts' => WorkflowEntityQuery::sorts($entity.'.list')];
        }

        return ['users' => \App\Models\amoCRM\Staff::query()->where('user_id', $userId)->where('active', true)->orderBy('name')->pluck('name', 'staff_id'),
            'pipelines' => $pipelines->pluck('pipeline_name', 'pipeline_id'),
            'statuses' => $pipelines->map(fn ($row) => ['id' => $row->status_id, 'pipeline_id' => $row->pipeline_id, 'name' => $row->name])->values(),
            'fields' => $fields->map(fn ($row) => ['id' => $row->field_id, 'entity' => $row->entity_type, 'name' => $row->name, 'type' => $row->type, 'enums' => $row->enums])->values(),
            'task_types' => WorkflowNodeReferences::options('task_types'), 'salesbots' => WorkflowNodeReferences::options('salesbots'),
            'telegram' => WorkflowCredentials::options('telegram'), 'query' => $query];
    }

    public function save(?Workflow $workflow, array $payload, ?Workflow $copySource = null): array
    {
        Validator::make($payload, ['version' => 'nullable|string|size:64', 'document' => 'required|array',
            'document.name' => 'required|string|max:255', 'document.group_name' => 'nullable|string|max:255',
            'document.is_active' => 'required|boolean', 'document.failure_strategy' => 'required|in:stop,continue,telegram_report',
            'document.definition' => 'required|array', 'document.definition.actions' => 'present|array|max:500',
            'document.definition.trigger' => 'nullable|array', 'document.definition.additional_triggers' => 'sometimes|array|max:20',
            'document.definition.connections' => 'sometimes|array|max:2000',
            'document.definition.actions.*' => 'array', 'document.definition.actions.*.id' => 'required|string|max:150',
            'document.definition.actions.*.type' => 'required|string|max:100', 'document.definition.actions.*.config' => 'present|array',
        ])->validate();
        // Nested validation returns only declared children. The graph deliberately
        // carries forward unknown definition keys; never replace it with that projection.
        $data = $payload['document'];

        return DB::transaction(function () use ($workflow, $payload, $data, $copySource): array {
            $locked = $workflow ?? $copySource;
            if ($locked) {
                $locked = Workflow::query()->where('user_id', auth()->id())->lockForUpdate()->findOrFail($locked->getKey());
                abort_unless(hash_equals($this->version($locked), (string) ($payload['version'] ?? '')), 409,
                    'Сценарий изменился в другом окне. Ваш черновик сохранён в этом окне. Откройте свежую версию перед сохранением.');
                if ($workflow) {
                    $workflow = $locked;
                }
            }
            if (filled($data['group_name'] ?? null)) {
                WorkflowFolders::assertFolderExists($data['group_name']);
            }
            $definition = $this->restoreSecrets($data['definition'], $locked?->definition ?? []);
            // Drafts may contain unfinished fields, but never broken graph identities/connections.
            try {
                WorkflowStartNodes::all($definition);
                WorkflowGraph::ordered($definition);
            } catch (\InvalidArgumentException $error) {
                throw ValidationException::withMessages(['document.definition' => $error->getMessage()]);
            }
            $model = $workflow ?? new Workflow(['user_id' => auth()->id(), 'created_by' => auth()->id()]);
            $model->fill(array_intersect_key($data, array_flip(['name', 'group_name', 'is_active', 'failure_strategy'])));
            $model->definition = $definition;
            $model->updated_by = auth()->id();
            // Retain the same activation validation and save hooks as the existing editor.
            $model->save();
            $model->refresh();

            return ['document' => $this->document($model), 'version' => $this->version($model),
                'editUrl' => \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('edit', ['record' => $model]),
                'historyUrl' => \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('history', ['record' => $model]),
                'webhookUrl' => app(WorkflowGenericWebhookService::class)->callbackUrl($model)];
        });
    }

    public function restoreSecrets(array $definition, array $previous): array
    {
        try {
            WorkflowGraph::nodes($definition['actions'] ?? []);
        } catch (\InvalidArgumentException|\TypeError|\ErrorException $error) {
            throw ValidationException::withMessages(['document.definition' => $error instanceof \InvalidArgumentException ? $error->getMessage() : 'Некорректная конфигурация ноды.']);
        }
        $old = WorkflowGraph::nodes($previous['actions'] ?? []);
        $walk = function (array $steps) use (&$walk, $old): array {
            foreach ($steps as &$step) {
                if (! is_array($step) || ! is_array($step['config'] ?? [])) {
                    throw ValidationException::withMessages(['document.definition' => 'Некорректная нода.']);
                }
                $config = $step['config'] ?? [];
                $source = $config['_browser_secret_source'] ?? $step['id'] ?? '';
                if (! is_string($source)) {
                    throw ValidationException::withMessages(['document.definition' => 'Некорректная ссылка на подключение.']);
                }
                unset($config['_browser_secret_source']);
                if (($step['type'] ?? '') === 'telegram_send_message') {
                    unset($config['bot_token_encrypted']);
                    $prior = $old['action:'.$source]['step'] ?? [];
                    $config = TelegramSendMessageAction::protectConfig($config, ($prior['type'] ?? '') === 'telegram_send_message' ? $prior['config'] : []);
                }
                foreach (['true_actions', 'false_actions'] as $branch) {
                    if (isset($config[$branch])) {
                        $config[$branch] = $walk($config[$branch]);
                    }
                }
                $step['config'] = $config;
            }

            return $steps;
        };
        $definition['actions'] = $walk($definition['actions'] ?? []);

        return $definition;
    }

    private function forBrowser(array $definition): array
    {
        $names = WorkflowExpressionCatalog::referenceNames($definition['actions'] ?? [], $definition);
        foreach (WorkflowGraph::nodes($definition['actions'] ?? []) as $entry) {
            data_set($definition, 'actions.'.$entry['path'].'.name', $names[$entry['step']['id']]);
        }
        if (! empty($definition['trigger'])) {
            $definition['trigger']['name'] = $names['trigger'];
        }
        foreach ($definition['additional_triggers'] ?? [] as $index => $start) {
            $definition['additional_triggers'][$index]['name'] = $names[$start['id']];
        }
        $walk = function (array $steps) use (&$walk): array {
            foreach ($steps as &$step) {
                if (($step['type'] ?? '') === 'telegram_send_message' && isset($step['config']['bot_token_encrypted'])) {
                    unset($step['config']['bot_token_encrypted']);
                    $step['config']['_browser_secret_source'] = $step['id'];
                }
                foreach (['true_actions', 'false_actions'] as $branch) {
                    if (isset($step['config'][$branch])) {
                        $step['config'][$branch] = $walk($step['config'][$branch]);
                    }
                }
            }

            return $steps;
        };
        $definition['actions'] = $walk($definition['actions'] ?? []);
        // Materialise legacy tree edges once; flattening in the browser retains their semantics.
        $definition['connections'] = WorkflowGraph::connections($definition);

        return $definition;
    }
}
