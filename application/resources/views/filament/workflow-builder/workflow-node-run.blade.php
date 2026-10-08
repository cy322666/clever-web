<div class="workflow-node-run">
    <button type="button" class="workflow-workspace-toggle" x-on:click="drawer = drawer === 'sources' ? null : 'sources'" :aria-expanded="drawer === 'sources'">Данные и переменные</button>
    <button type="button" class="workflow-workspace-toggle" x-on:click="drawer = drawer === 'result' ? null : 'result'" :aria-expanded="drawer === 'result'">Результат</button>
    @if(str_starts_with($referenceType ?? '', 'amocrm_'))
        <button type="button" @disabled(!$referencePlan) wire:click="refreshEditingNodeReferences" wire:loading.attr="disabled" wire:target="refreshEditingNodeReferences" class="workflow-workbench__quick-action" aria-label="Обновить справочники amoCRM" title="{{ $referencePlan ? 'Обновить справочники этой ноды' : 'Эта нода не использует справочники' }}">
            <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4"/>
        </button>
    @endif
    <button type="button" wire:click="runEditingWorkflowNode" x-on:click="drawer = 'result'" wire:loading.attr="disabled" wire:target="runEditingWorkflowNode" class="workflow-node-run__button" title="Реально выполнить ноду в текущем контексте">
        <x-filament::icon icon="heroicon-m-play" class="h-4 w-4"/> Запустить
    </button>
</div>
