@php
    $nodeId = $action->getName() === 'configureTrigger' ? $this->editingTriggerNodeId : 'action:'.$this->editingActionId;
    $nodeName = $this->workflowNodeNavigation()['name'];
@endphp
<form class="workflow-inspector__form" wire:key="workflow-inspector-node-{{ $nodeId }}"
    data-inspector-node="{{ $nodeId }}" data-inspector-title="{{ $nodeName }}"
    x-init="inspectorMounted($el)" wire:submit.prevent="callMountedAction">
    <div class="workflow-inspector__fields">{{ $this->getMountedActionSchema(mountedAction: $action) }}</div>
    <footer class="workflow-inspector__footer">
        <button type="button" x-on:click="closeInspector(false)" x-bind:disabled="loading">Отменить</button>
        <button type="button" x-on:click="closeInspector()" x-bind:disabled="loading">Применить</button>
    </footer>
</form>
