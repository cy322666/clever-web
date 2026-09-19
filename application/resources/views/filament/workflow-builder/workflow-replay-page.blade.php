<x-filament-panels::page>
    <form wire:submit="save" class="workflow-editor-page">
        {{ $this->form }}
        <x-filament-workflows::workflow-builder submit-label="Сохранить" page-mode />
    </form>
    <x-filament-actions::modals />
</x-filament-panels::page>
