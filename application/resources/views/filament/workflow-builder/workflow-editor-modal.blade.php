<div class="workflow-editor-modal-shell">
    <livewire:workflow-builder.workflow-modal-editor
        :record="$record->getKey()"
        :key="'workflow-editor-modal-' . $record->getKey()"
    />
</div>
