<div class="workflow-node-run">
    @if(str_starts_with($referenceType ?? '', 'amocrm_'))
        <button type="button" @disabled(!$referencePlan) wire:click="refreshEditingNodeReferences" wire:loading.attr="disabled" wire:target="refreshEditingNodeReferences" class="workflow-workbench__quick-action" aria-label="Обновить справочники amoCRM" title="{{ $referencePlan ? 'Обновить справочники этой ноды' : 'Эта нода не использует справочники' }}">
            <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4"/>
        </button>
    @endif
    <button type="button" wire:click="runEditingWorkflowNode" wire:loading.attr="disabled" wire:target="runEditingWorkflowNode" class="workflow-node-run__button" title="Реально выполнить ноду в текущем контексте">
        <x-filament::icon icon="heroicon-m-play" class="h-4 w-4"/> Запустить
    </button>
</div>
