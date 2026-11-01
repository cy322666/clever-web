@php
    $nodeId = $action->getName() === 'configureTrigger' ? $this->editingTriggerNodeId : 'action:'.$this->editingActionId;
    $nodeName = $this->workflowNodeNavigation()['name'];
    $documentationNode = $action->getName() === 'configureTrigger' ? $this->editingTriggerNode() : $this->getEditingWorkflowAction();
    $documentationConfig = $this->mountedActions[0]['data'] ?? $documentationNode['config'] ?? [];
    $documentationUrl = \App\Services\Workflows\WorkflowDocumentationLinks::forNode((string) ($documentationNode['type'] ?? ''), (array) $documentationConfig);
@endphp
<form class="workflow-inspector__form" wire:key="workflow-inspector-node-{{ $nodeId }}"
    data-inspector-node="{{ $nodeId }}" data-inspector-title="{{ $nodeName }}"
    x-init="inspectorMounted($el)" wire:submit.prevent="callMountedAction">
    <x-filament::link :href="$documentationUrl" target="_blank" rel="noopener noreferrer" size="sm" icon="heroicon-o-question-mark-circle">Справка о ноде</x-filament::link>
    <div class="workflow-inspector__fields">{{ $this->getMountedActionSchema(mountedAction: $action) }}</div>
    <footer class="workflow-inspector__footer">
        <button type="button" x-on:click="closeInspector(false)" x-bind:disabled="loading">Отменить</button>
        <button type="button" x-on:click="closeInspector()" x-bind:disabled="loading">Применить</button>
    </footer>
</form>
