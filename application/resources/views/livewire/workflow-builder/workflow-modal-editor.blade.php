<div class="workflow-editor-modal-content">
    <form wire:submit="save">
        <x-filament-workflows::workflow-builder :submit-label="__('filament-workflows::workflows.actions.save_changes.label')" />
    </form>

    {{ $this->content }}

    <x-filament-actions::modals />
</div>
