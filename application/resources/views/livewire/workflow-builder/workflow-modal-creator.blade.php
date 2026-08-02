<div class="workflow-editor-modal-content">
    <form wire:submit="create">
        <x-filament-workflows::workflow-builder :submit-label="__('filament-workflows::workflows.actions.create.label')" />
    </form>

    <x-filament-actions::modals />
</div>
