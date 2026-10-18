{{-- Data and inert clones of the original cards. Settings and debugger remain
     their existing Filament components, not a second renderer. --}}
@php($draftCatalog = $this->workflowDraftCatalog())
<script type="application/json" data-workflow-draft-catalog>{!! json_encode($draftCatalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
<div hidden wire:ignore data-workflow-draft-templates>
    @foreach($draftCatalog['actions'] as $type => $item)
        @php($draftAction = ['id' => '__DRAFT_ID__', 'type' => $type, 'name' => $item['name'], 'config' => $item['defaults']])
        <template data-workflow-draft-template="action:{{ $type }}"><div class="workflow-sortable-item" data-workflow-node-id="action:__DRAFT_ID__" data-workflow-detached="true" wire:key="workflow-sortable-__DRAFT_ID__">
            <x-filament-workflows::workflows.action-card :action="$draftAction" :metadata="$this->getWorkflowActionMetadata($type, $item['defaults'])" wire:key="action-__DRAFT_ID__"/>
        </div></template>
    @endforeach
    @foreach($draftCatalog['operations'] as $operation => $item)
        @php($draftConfig = array_merge($draftCatalog['actions']['amocrm_read']['defaults'] ?? [], ['operation' => $operation]))
        @php($draftAction = ['id' => '__DRAFT_ID__', 'type' => 'amocrm_read', 'name' => $item['node_name'] ?? $item['name'], 'config' => $draftConfig])
        <template data-workflow-draft-template="read:{{ $operation }}"><div class="workflow-sortable-item" data-workflow-node-id="action:__DRAFT_ID__" data-workflow-detached="true" wire:key="workflow-sortable-__DRAFT_ID__">
            <x-filament-workflows::workflows.action-card :action="$draftAction" :metadata="$this->getWorkflowActionMetadata('amocrm_read', $draftConfig)" wire:key="action-__DRAFT_ID__"/>
        </div></template>
    @endforeach
    @foreach($draftCatalog['triggers'] as $type => $item)
        <template data-workflow-draft-template="trigger:{{ $type }}"><div class="workflow-node-shell workflow-node-shell--trigger" data-workflow-node-id="__DRAFT_START__" data-draft-type="{{ $type }}" wire:key="workflow-start-__DRAFT_START__">
            <x-filament-workflows::workflows.trigger-card node-id="__DRAFT_START__" :type="$type" :config="$item['defaults']" :metadata="$item"/>
        </div></template>
    @endforeach
    <template data-workflow-draft-template="empty"><div class="workflow-node-shell workflow-node-shell--trigger" data-workflow-start-add>
        <button type="button" x-on:pointerdown.stop x-on:click="captureTriggerAdd(); workflowEditorCall($wire, 'beginTriggerAdd')" class="workflow-node-empty" data-workflow-empty-start aria-label="Выбрать событие">
            <span class="workflow-node-card__icon"><x-filament::icon icon="heroicon-o-plus" class="h-5 w-5"/></span>
            <span class="workflow-node-card__caption"><strong class="workflow-node-card__title">Выбрать событие</strong></span>
        </button>
    </div></template>
</div>
