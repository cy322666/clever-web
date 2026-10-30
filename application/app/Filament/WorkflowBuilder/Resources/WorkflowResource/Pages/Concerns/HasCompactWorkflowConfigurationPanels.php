<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait HasCompactWorkflowConfigurationPanels
{
    public array $workflowLocalInspectorDirty = [];

    private ?array $localInspectorBootstrap = null;

    public function workflowInspectorCacheKey(): string
    {
        return 'workflow-inspector:'.auth()->id().':'.$this->getId();
    }

    #[\Livewire\Attributes\Renderless]
    public function pollWorkflowWebhookPreview(): ?array
    {
        $record = method_exists($this,'getRecord') ? $this->getRecord() : null;
        if (!$record?->exists) return null;
        abort_unless((int)$record->user_id === (int)auth()->id(),403);
        return app(\App\Services\Workflows\WorkflowGenericWebhookService::class)->latestPreview($record);
    }

    public function workflowInspectorBootstrap(): array
    {
        if ($this->localInspectorBootstrap !== null) return $this->localInspectorBootstrap;
        $cached=\Illuminate\Support\Facades\Cache::get($this->workflowInspectorCacheKey());
        if (is_array($cached)) return $this->localInspectorBootstrap=$cached;
        $registry = app(\Leek\FilamentWorkflows\Actions\ActionRegistry::class);
        $compiler = app(\App\Services\Workflows\WorkflowInspectorSchema::class);
        $schemas = [];
        $catalog = $this->workflowDraftCatalog();
        foreach ($catalog['actions'] as $type => $item) {
            $schemas['action:'.$type] = $compiler->describe($this, $registry->getConfigSchema($type), $item['defaults']);
        }
        foreach (\App\Services\Workflows\WorkflowGraph::nodes($this->workflowActions) as $entry) {
            $type = $entry['step']['type'];
            if (!isset($schemas['action:'.$type]) && $registry->has($type)) $schemas['action:'.$type] = $compiler->describe($this, $registry->getConfigSchema($type), $registry->getDefaultConfig($type));
        }
        $schemas['trigger:schedule'] = $compiler->describe($this, \App\Workflows\Triggers\ScheduleTrigger::configSchema(), \App\Workflows\Triggers\ScheduleTrigger::defaultConfig());
        $schemas['trigger:generic-webhook'] = $compiler->describe($this, \App\Workflows\Triggers\GenericWebhookTrigger::configSchema(), ['source'=>'webhook']);
        try { $context = $this->workflowActions!==[] || $this->debugState!==[] ? $this->workflowNodeInputContext() : []; } catch (\JsonException|\InvalidArgumentException) { $context=[]; }
        $sources = \App\Services\Workflows\WorkflowExpressionCatalog::sources([...$this->workflowActions,['id'=>'__inspector_end__','type'=>'workflow_delay','config'=>[]]], '__inspector_end__', $context, null, $this->definition);
        $with = [];
        foreach (\App\Services\Workflows\WorkflowAmoReadCatalog::operations() as $key=>$operation) $with[$key] = \App\Services\Workflows\WorkflowAmoReadCatalog::withOptions($key);
        $conditionFields = auth()->id() ? \App\Services\Workflows\WorkflowEditorReferenceQuery::for(\App\Models\amoCRM\Field::class)->where('user_id', auth()->id())->where('active',true)->get()->map(fn($field)=>['id'=>$field->field_id,'entity'=>$field->entity_type,'type'=>$field->type,'enums'=>$field->enums])->all() : [];
        $this->localInspectorBootstrap = ['schemas'=>$schemas,
            'sources'=>\App\Services\Workflows\WorkflowExpressionCatalog::forPicker($sources),
            'customFields'=>\App\Services\Workflows\WorkflowExpressionCatalog::customFieldSuggestions(),
            'conditionFields'=>$conditionFields,
            'queryOperations'=>['leads.list','contacts.list','companies.list','customers.list','tasks.list'], 'withOptions'=>$with,
            'timezone'=>config('app.timezone'),
            'selectUrl'=>\Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('select','filament/forms'),
            'codeEditorUrl'=>\Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('code-editor','filament/forms')];
        \Illuminate\Support\Facades\Cache::put($this->workflowInspectorCacheKey(),$this->localInspectorBootstrap,now()->addMinutes(15));
        return $this->localInspectorBootstrap;
    }

    public ?array $workflowInspectorInitialData = null;

    #[Locked]
    public array $workflowInspectorDrafts = [];

    public function isWorkflowInspectorAction(Action $action): bool
    {
        return in_array($action->getName(), ['configureWorkflowAction', 'configureTrigger'], true);
    }

    protected function applyWorkflowInspectorDraft(bool $allowInvalid = false): bool
    {
        if (!$allowInvalid && !$this->applyLocalWorkflowInspectorDrafts()) return false;
        if ($this->mountedActions !== [] && $this->isWorkflowInspectorAction($this->getMountedAction())) {
            $nodeId = $this->currentWorkflowInspectorNodeId();
            $deferred = false;
            try {
                $this->callMountedAction();
            } catch (ValidationException $exception) {
                if (!$allowInvalid) throw $exception;
                $deferred = true;
                $data = $this->mountedActions[0]['data'] ?? [];
                if ($data === ($this->workflowInspectorInitialData ?? [])) {
                    unset($this->workflowInspectorDrafts[$nodeId]);
                } else {
                    $this->workflowInspectorDrafts[$nodeId] = [
                        'data' => $data,
                        'initial' => $this->workflowInspectorInitialData,
                    ];
                }
                $this->unmountAction();
                $this->resetErrorBag();
            }
            if ($this->mountedActions !== []) return false;
            if (!$deferred) unset($this->workflowInspectorDrafts[$nodeId]);
        }

        if ($allowInvalid) return true;

        foreach (array_keys($this->workflowInspectorDrafts) as $nodeId) {
            if (!isset(\App\Services\Workflows\WorkflowGraph::nodes($this->workflowActions)[$nodeId])
                && !isset(\App\Services\Workflows\WorkflowStartNodes::all($this->definition)[$nodeId])) {
                unset($this->workflowInspectorDrafts[$nodeId]);
                continue;
            }
            $this->resetWorkflowInspectorSchema();
            if (str_starts_with($nodeId, 'action:')) $this->openWorkflowActionEditor(substr($nodeId, 7));
            else $this->editTriggerNode($nodeId);
            $this->callMountedAction();
            if ($this->mountedActions !== []) return false;
            unset($this->workflowInspectorDrafts[$nodeId]);
        }

        return true;
    }

    protected function applyLocalWorkflowInspectorDrafts(?array $ids = null): bool
    {
        foreach ($ids ?? array_keys($this->workflowLocalInspectorDirty) as $nodeId) {
            if (!isset($this->workflowLocalInspectorDirty[$nodeId])) continue;
            $entry = \App\Services\Workflows\WorkflowGraph::nodes($this->workflowActions)[$nodeId] ?? null;
            $start = \App\Services\Workflows\WorkflowStartNodes::all(array_merge($this->definition,['trigger'=>$this->trigger]))[$nodeId] ?? null;
            if (!$entry && !in_array($start['type'] ?? null,['schedule','generic-webhook'],true)) { unset($this->workflowLocalInspectorDirty[$nodeId]); continue; }
            if ($this->mountedActions !== []) $this->unmountAction();
            $this->resetWorkflowInspectorSchema();
            if ($entry) $this->openWorkflowActionEditor(substr($nodeId,7));
            else $this->editTriggerNode($nodeId);
            $original = $entry['step']['config'] ?? $start['config'] ?? [];
            try { $this->callMountedAction(); }
            catch (ValidationException $exception) {
                $errors=[];
                foreach ($exception->errors() as $path=>$messages) {
                    $parts=explode('.',preg_replace('/^mountedActions\.0\.data\./','',$path));
                    $cursor=$this->mountedActions[0]['data'] ?? [];
                    foreach ($parts as &$part) {
                        $value=$cursor[$part] ?? null;
                        if (is_array($cursor) && preg_match('/^[a-f0-9-]{36}$/i',$part)) $part=(string)array_search($part,array_keys($cursor),true);
                        $cursor=$value;
                    }
                    unset($part);
                    $errors[implode('.',$parts)]=$messages;
                }
                $this->dispatch('workflow-local-inspector-validation',componentId:$this->getId(),nodeId:$nodeId,errors:$errors);
                $this->unmountAction();
                throw $exception;
            }
            if ($this->mountedActions !== []) return false;
            // Keep unrecognized settings and credentials in imported flows.
            if ($entry) {
                $saved=\App\Services\Workflows\WorkflowGraph::nodes($this->workflowActions)[$nodeId];
                data_set($this->workflowActions,$saved['path'].'.config',array_replace($original,$saved['step']['config']));
            } elseif ($nodeId==='trigger') {
                $this->trigger['config']=array_replace($original,$this->trigger['config']);
            } else {
                foreach ($this->definition['additional_triggers'] as &$item) if ($item['id']===$nodeId) $item['config']=array_replace($original,$item['config']);
                unset($item);
            }
            unset($this->workflowLocalInspectorDirty[$nodeId]);
        }
        $this->syncDefinition();
        return true;
    }

    public function openWorkflowInspectorCreateOption(string $nodeId): void
    {
        $step=\App\Services\Workflows\WorkflowGraph::nodes($this->workflowActions)[$nodeId]['step'] ?? null;
        abort_unless(($step['type'] ?? null)==='telegram_send_message',422);
        $this->openWorkflowInspector($nodeId);
        $schema=$this->getMountedActionSchema(mountedAction:$this->getMountedAction());
        $field=$schema->getComponentByStatePath('mountedActions.0.data.credential_id',withHidden:true,withAbsoluteStatePath:true);
        abort_unless($field instanceof \Filament\Forms\Components\Select && $field->hasCreateOptionActionFormSchema(),422);
        $this->mountAction('createOption',[],['schemaComponent'=>$field->getKey()]);
    }

    private function currentWorkflowInspectorNodeId(): string
    {
        return ($this->mountedActions[0]['name'] ?? '') === 'configureTrigger'
            ? $this->editingTriggerNodeId
            : 'action:'.$this->editingActionId;
    }

    private function restoreWorkflowInspectorDraft(Action $action): void
    {
        $nodeId = $this->currentWorkflowInspectorNodeId();
        $draft = $this->workflowInspectorDrafts[$nodeId] ?? null;
        if ($draft !== null) {
            $this->mountedActions[$action->getNestingIndex()]['data'] = $draft['data'];
        }
        $this->workflowInspectorInitialData = $draft['initial']
            ?? ($this->mountedActions[$action->getNestingIndex()]['data'] ?? []);
    }

    protected function resetWorkflowInspectorSchema(): void
    {
        $this->cachedMountedActions = null;
        foreach (array_keys($this->cachedSchemas) as $schemaName) {
            if (str_starts_with($schemaName, 'mountedActionSchema')) unset($this->cachedSchemas[$schemaName]);
        }
    }

    public function openWorkflowInspector(string $nodeId): void
    {
        $isAction = str_starts_with($nodeId, 'action:');
        $exists = $isAction
            ? isset(\App\Services\Workflows\WorkflowGraph::nodes($this->workflowActions)[$nodeId])
            : in_array(\App\Services\Workflows\WorkflowStartNodes::all($this->definition)[$nodeId]['type'] ?? '', ['schedule', 'generic-webhook'], true);
        abort_unless($exists, 422);

        if ($this->mountedActions !== []) {
            if (count($this->mountedActions) !== 1 || !$this->isWorkflowInspectorAction($this->getMountedAction())) return;
            if (($isAction && ($this->mountedActions[0]['name'] ?? '') === 'configureWorkflowAction' && $this->editingActionId === substr($nodeId, 7))
                || (!$isAction && ($this->mountedActions[0]['name'] ?? '') === 'configureTrigger' && $this->editingTriggerNodeId === $nodeId)) return;
            if (!$this->applyWorkflowInspectorDraft(allowInvalid: true)) return;
        }
        $this->resetWorkflowInspectorSchema();
        if ($isAction) $this->openWorkflowActionEditor(substr($nodeId, 7));
        else $this->editTriggerNode($nodeId);
        // Switching forms does not change graph geometry. Reuse Filament's
        // partial action rendering instead of rebuilding every card and catalog.
        app(\Filament\Support\Livewire\Partials\PartialsComponentHook::class)->forceRender($this, false);
    }

    public function workflowNodeNavigation(): array
    {
        $definition = array_merge($this->definition, ['actions' => $this->workflowActions, 'trigger' => $this->trigger]);
        $isTrigger = ($this->mountedActions[0]['name'] ?? '') === 'configureTrigger';
        $current = $isTrigger ? $this->editingTriggerNodeId : 'action:'.$this->editingActionId;
        $names = \App\Services\Workflows\WorkflowExpressionCatalog::referenceNames($this->workflowActions, $definition);
        $starts = \App\Services\Workflows\WorkflowStartNodes::all($definition);
        $nodes = \App\Services\Workflows\WorkflowGraph::nodes($this->workflowActions);
        $navigation = ['name' => $names[$isTrigger ? $current : $this->editingActionId] ?? 'Нода', 'previous' => [], 'next' => []];
        foreach (\App\Services\Workflows\WorkflowGraph::connections($definition) as $edge) {
            $direction = $edge['sourceId'] === $current ? 'next' : ($edge['targetId'] === $current ? 'previous' : null);
            if ($direction === null) continue;
            $id = $direction === 'next' ? $edge['targetId'] : $edge['sourceId'];
            if (!isset($nodes[$id]) && !in_array($starts[$id]['type'] ?? '', ['schedule', 'generic-webhook'], true)) continue;
            $name = $names[str_starts_with($id, 'action:') ? substr($id, 7) : $id] ?? 'Нода';
            $branch = ['yes' => 'Да', 'no' => 'Нет'][$edge['sourcePort']] ?? null;
            $navigation[$direction][$id] = ['id' => $id, 'name' => $name, 'label' => $branch ? $branch.' · '.$name : $name];
        }
        $navigation['previous'] = array_values($navigation['previous']);
        $navigation['next'] = array_values($navigation['next']);
        return $navigation;
    }

    public function navigateWorkflowNode(string $target): void
    {
        if (count($this->mountedActions) !== 1 || !in_array($this->mountedActions[0]['name'] ?? '', ['configureWorkflowAction', 'configureTrigger'], true)) return;
        $navigation = $this->workflowNodeNavigation();
        if (!in_array($target, array_column(array_merge($navigation['previous'], $navigation['next']), 'id'), true)) return;
        if (!$this->applyWorkflowInspectorDraft(allowInvalid: true)) return;
        // Mounting another node uses the same action name and nesting index.
        // Discard the previous schema, as Filament's replaceMountedAction() does.
        $this->resetWorkflowInspectorSchema();
        if (str_starts_with($target, 'action:')) $this->openWorkflowActionEditor(substr($target, 7));
        else $this->editTriggerNode($target);
    }

    public function refreshEditingNodeReferences(): void
    {
        $step = $this->getEditingWorkflowAction();
        abort_unless(str_starts_with($step['type'] ?? '', 'amocrm_'), 422);
        $config = $this->mountedActions[array_key_last($this->mountedActions)]['data'] ?? $step['config'] ?? [];
        try {
            app(\App\Services\Workflows\WorkflowNodeReferences::class)->refresh($step['type'], $config);
            \Filament\Notifications\Notification::make()->success()->title('Справочники обновлены')->send();
        } catch (\Throwable $error) {
            report($error);
            \Filament\Notifications\Notification::make()->danger()->title('Не удалось обновить справочники')->body('Проверьте подключение и права в amoCRM. Введённые настройки сохранены.')->send();
        }
    }

    public function selectTriggerAction(): Action
    {
        return parent::selectTriggerAction()
            ->slideOver(false)
            ->modalWidth('lg');
    }

    public function addWorkflowActionAction(): Action
    {
        return parent::addWorkflowActionAction()
            ->slideOver(false)
            ->modalWidth('lg');
    }

    public function configureTriggerAction(): Action
    {
        return parent::configureTriggerAction()
            ->afterFormFilled(fn (Action $action) => $this->restoreWorkflowInspectorDraft($action))
            ->visible(fn (): bool => in_array($this->editingTriggerNode()['type'] ?? '', ['schedule', 'generic-webhook'], true))
            ->modalHeading(fn () => new \Illuminate\Support\HtmlString(view('filament.workflow-builder.workflow-node-navigation', $this->workflowNodeNavigation())->render()))
            ->fillForm(fn () => ($this->editingTriggerNode()['type'] ?? '') === 'schedule'
                ? \App\Workflows\Triggers\ScheduleTrigger::normalize($this->editingTriggerNode()['config'] ?? [])
                : ($this->editingTriggerNode()['config'] ?? []))
            ->form(fn () => $this->nodeWorkspace(($this->editingTriggerNode()['type'] ?? '') === 'generic-webhook'
                ? \App\Workflows\Triggers\GenericWebhookTrigger::configSchema()
                : \App\Workflows\Triggers\ScheduleTrigger::configSchema()))
            ->action(fn (array $data) => $this->saveTriggerNodeConfig($data))
            ->slideOver(false)
            ->extraModalWindowAttributes(['class' => 'workflow-node-settings'])
            ->modalDescription(null)
            ->modalIcon(null)
            ->modalIconColor('gray')
            ->modalWidth('screen');
    }

    public function configureWorkflowActionAction(): Action
    {
        return parent::configureWorkflowActionAction()
            ->afterFormFilled(fn (Action $action) => $this->restoreWorkflowInspectorDraft($action))
            ->modalHeading(fn () => new \Illuminate\Support\HtmlString(view('filament.workflow-builder.workflow-node-navigation', $this->workflowNodeNavigation())->render()))
            ->fillForm(function () {
                $step = $this->getEditingWorkflowAction();
                $config = $step['config'] ?? [];
                if (str_starts_with((string)($step['type'] ?? ''), 'amocrm_')) {
                    $configuredId = $config['target_entity_id'] ?? $config['entity_id'] ?? null;
                    if (!in_array($config['entity_source'] ?? null, ['context', 'manual'], true)) {
                        $config['entity_source'] = filled($configuredId) ? 'manual' : 'context';
                    }

                    [$contextEntity, $contextEntityId] = $this->workflowEntityPreview((string)$step['type'], $config);
                    $config['__context_entity'] = $contextEntity;
                    $config['__context_entity_id'] = $contextEntityId;

                    if (($config['entity_source'] ?? 'context') === 'context' && filled($contextEntity)) {
                        $plural = str_ends_with((string)($config['target_entity'] ?? ''), 's');
                        $config['target_entity'] = $plural
                            ? ['lead' => 'leads', 'contact' => 'contacts', 'company' => 'companies', 'customer' => 'customers'][$contextEntity] ?? $contextEntity
                            : $contextEntity;
                    }
                }
                if (($step['type'] ?? '') === 'amocrm_read') {
                    $config = \App\Services\Workflows\WorkflowAmoReadCatalog::editorConfig($config);
                }
                if (in_array($step['type'] ?? '', ['amocrm_update_lead_fields', 'amocrm_update_contact_fields', 'amocrm_update_company_fields'], true)) {
                    foreach ($config['fields'] ?? [] as $i => $field) {
                        if (str_starts_with($field['field'] ?? '', 'system:') && ($field['value'] ?? null) !== null && $field['value'] !== '') {
                            $config['standard_fields'][substr($field['field'], 7)] ??= $field['value'];
                            unset($config['fields'][$i]);
                        }
                    }
                    $config['fields'] = array_values($config['fields'] ?? []);
                }
                unset($config['bot_token'], $config['bot_token_encrypted']);
                $names = \App\Services\Workflows\WorkflowExpressionCatalog::referenceNames($this->workflowActions, $this->definition);
                return \App\Services\Workflows\WorkflowExpressionCatalog::remapReferences($config, array_combine(array_keys($names), array_keys($names)), $names);
            })
            ->slideOver(false)
            ->modalContent(null)
            ->modalDescription(null)
            ->modalIcon(null)
            ->modalSubmitAction(fn (Action $action) => $action->label('Готово')->icon(null))
            ->after(function (): void {
                // The package sends this toast directly, outside successNotification().
                // Keep its save handler intact and retain all errors and warnings.
                session()->put('filament.notifications', array_values(array_filter(
                    session('filament.notifications', []),
                    fn (array $notification): bool => ($notification['status'] ?? null) !== 'success'
                        || ($notification['title'] ?? null) !== __('filament-workflows::workflows.notifications.action_updated.title'),
                )));
            })
            ->form(fn () => $this->nodeWorkspace($this->getWorkflowActionConfigSchema()))
            ->extraModalWindowAttributes(['class' => 'workflow-node-settings'])
            ->modalIconColor('gray')
            ->modalWidth('screen');
    }

    /**
     * @param array<string, mixed> $config
     * @return array{0: string, 1: int|null}
     */
    private function workflowEntityPreview(string $actionType, array $config): array
    {
        $fixed = [
            'amocrm_copy_lead' => 'lead',
            'amocrm_get_contact' => 'contact',
            'amocrm_update_lead_fields' => 'lead',
            'amocrm_update_contact_fields' => 'contact',
            'amocrm_update_company_fields' => 'company',
            'amocrm_change_lead_status' => 'lead',
            'amocrm_distribution_queue' => 'lead',
        ][$actionType] ?? null;
        $entity = $fixed ?: $this->singularWorkflowEntity((string)($config['target_entity'] ?? 'lead'));
        $contextEntity = null;
        $contextId = null;
        $source = $this->getWorkflowExpressionSources()[0] ?? [];
        $fields = collect($source['fields'] ?? [])->keyBy('path');
        $candidate = $fields->get('.entity')['value'] ?? null;

        if (is_string($candidate)) {
            $candidate = $this->singularWorkflowEntity($candidate);
            if (in_array($candidate, ['lead', 'contact', 'company', 'customer', 'task'], true)) {
                $contextEntity = $candidate;
            }
        }

        if ($contextEntity === null) {
            foreach (['lead', 'contact', 'company', 'customer', 'task'] as $candidateEntity) {
                if (filled($fields->get('.' . $candidateEntity . '.id')['value'] ?? null)) {
                    $contextEntity = $candidateEntity;
                    break;
                }
            }
        }

        if ($fixed === null && $contextEntity !== null) {
            $entity = $contextEntity;
        }

        foreach (['.' . $entity . '.id', '.item.id', '.id'] as $path) {
            $value = $fields->get($path)['value'] ?? null;
            if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
                $contextId = (int)$value;
                break;
            }
        }

        if ($contextEntity !== null && $contextEntity !== $entity) {
            $contextId = null;
        }

        return [$entity, $contextId];
    }

    private function singularWorkflowEntity(string $entity): string
    {
        return [
            'leads' => 'lead',
            'contacts' => 'contact',
            'companies' => 'company',
            'customers' => 'customer',
            'tasks' => 'task',
        ][$entity] ?? $entity;
    }

    private function nodeWorkspace(array $schema): array
    {
        return [Group::make()->extraAttributes(['class' => 'workflow-inspector__workspace'])->schema([
            Group::make([View::make('filament.workflow-builder.workflow-expression-picker')->viewData(['sources' => \App\Services\Workflows\WorkflowExpressionCatalog::forPicker($this->getWorkflowExpressionSources())])])
                ->extraAttributes(['x-show' => "tab === 'input'", 'class' => 'workflow-inspector__pane', 'role' => 'tabpanel', 'aria-label' => 'Вход']),
            Group::make($schema)
                ->extraAttributes(['x-show' => "tab === 'settings'", 'class' => 'workflow-inspector__pane workflow-node-settings__parameters', 'role' => 'tabpanel', 'aria-label' => 'Настройки']),
            Group::make([View::make('filament.workflow-builder.workflow-node-output')])
                ->extraAttributes(['x-show' => "tab === 'output'", 'class' => 'workflow-inspector__pane', 'role' => 'tabpanel', 'aria-label' => 'Выход']),
        ])];
    }
}
