<div class="workflow-node-run">
    @if(str_starts_with($referenceType ?? '', 'amocrm_'))
        <button type="button" @disabled(!$referencePlan) wire:click="refreshEditingNodeReferences" wire:loading.attr="disabled" wire:target="refreshEditingNodeReferences" class="workflow-workbench__quick-action" aria-label="Обновить справочники amoCRM" title="{{ $referencePlan ? 'Обновить справочники этой ноды' : 'Эта нода не использует справочники' }}">
            <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4"/>
        </button>
    @endif
    <button type="button" @disabled($getLivewire()->nodePreviewTaskId) wire:click="runEditingWorkflowNode" x-on:click="tab = 'output'" x-bind:disabled="workflowNodeIsRunning($wire)" x-bind:aria-busy="workflowNodeIsRunning($wire, $wire.editingActionId)" class="workflow-node-run__button" title="{{ ($referenceType ?? '') === 'workflow_loop' ? 'Запустить ветку цикла с выбранными настройками' : 'Реально выполнить ноду в текущем контексте' }}">
        <span class="workflow-node-run__label" x-show="!workflowNodeIsRunning($wire, $wire.editingActionId)"><x-filament::icon icon="heroicon-m-play" class="h-4 w-4"/> Запустить</span>
        <span class="workflow-node-run__label" x-show="workflowNodeIsRunning($wire, $wire.editingActionId)" x-cloak><span class="workflow-run-spinner" aria-hidden="true"></span> Выполняется…</span>
    </button>
</div>
