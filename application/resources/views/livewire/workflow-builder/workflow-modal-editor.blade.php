<div class="workflow-editor-modal-content">
    <form wire:submit="save">
        <x-filament-workflows::workflow-builder submit-label="Сохранить" />
    </form>

    {{ $this->content }}

    <x-filament-actions::modals />
</div>
