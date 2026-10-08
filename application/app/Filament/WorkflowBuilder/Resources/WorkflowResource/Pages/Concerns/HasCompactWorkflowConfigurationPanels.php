<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\View;

trait HasCompactWorkflowConfigurationPanels
{
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
        // Use the same validation, dehydration and save handler as the Done button.
        // This updates the editor draft only, not the saved/published workflow.
        $this->callMountedAction();
        if ($this->mountedActions !== []) return;
        // Mounting another node uses the same action name and nesting index.
        // Discard the previous schema, as Filament's replaceMountedAction() does.
        $this->cachedMountedActions = null;
        foreach (array_keys($this->cachedSchemas) as $schemaName) {
            if (str_starts_with($schemaName, 'mountedActionSchema')) unset($this->cachedSchemas[$schemaName]);
        }
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
            ->visible(fn (): bool => in_array($this->editingTriggerNode()['type'] ?? '', ['schedule', 'generic-webhook'], true))
            ->modalHeading(fn () => new \Illuminate\Support\HtmlString(view('filament.workflow-builder.workflow-node-navigation', $this->workflowNodeNavigation())->render()))
            ->fillForm(fn () => ($this->editingTriggerNode()['type'] ?? '') === 'schedule'
                ? \App\Workflows\Triggers\ScheduleTrigger::normalize($this->editingTriggerNode()['config'] ?? [])
                : ($this->editingTriggerNode()['config'] ?? []))
            ->form(fn () => ($this->editingTriggerNode()['type'] ?? '') === 'generic-webhook'
                ? \App\Workflows\Triggers\GenericWebhookTrigger::configSchema()
                : $this->nodeWorkspace(\App\Workflows\Triggers\ScheduleTrigger::configSchema()))
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
        $step = $this->getEditingWorkflowAction();
        $referenceType = $step['type'] ?? '';
        $referencePlan = \App\Services\Workflows\WorkflowNodeReferences::plan($referenceType, $this->mountedActions[array_key_last($this->mountedActions)]['data'] ?? $step['config'] ?? []);
        return [Grid::make(3)->extraAttributes(['class' => 'workflow-node-settings__workspace'])->schema([
            View::make('filament.workflow-builder.workflow-expression-picker')->viewData(['sources' => $this->getWorkflowExpressionSources()]),
            Group::make([View::make('filament.workflow-builder.workflow-node-run')->viewData(compact('referenceType','referencePlan')), ...$schema])->extraAttributes(['class' => 'workflow-node-settings__parameters']),
            View::make('filament.workflow-builder.workflow-node-output'),
        ])];
    }
}
