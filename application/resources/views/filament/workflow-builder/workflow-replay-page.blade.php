<x-filament-panels::page>
    <form wire:submit="create" class="workflow-editor-page">
        {{ $this->form }}
        <x-filament-workflows::workflow-builder submit-label="Сохранить как новый поток" page-mode />
    </form>
    <x-filament-actions::modals />
</x-filament-panels::page>
