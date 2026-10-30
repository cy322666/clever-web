<x-filament-panels::page>
    @include('filament.workflow-builder.workflow-admin-context')

    @if($this->replayRecordedOnly)
        <p class="workflow-execution-note">Исходная схема запуска не сохранена. Показаны записанные шаги.</p>
    @endif

    <form wire:submit="save" class="workflow-editor-page">
        {{ $this->form }}
        <x-filament-workflows::workflow-builder submit-label="Сохранить" page-mode />
    </form>
    <x-filament-actions::modals />
</x-filament-panels::page>
