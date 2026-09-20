<x-filament-panels::page>
    @include('filament.workflow-builder.workflow-admin-context')

    <form wire:submit="save" class="workflow-editor-page">
        {{ $this->form }}

        <x-filament-workflows::workflow-builder
            :submit-label="__('filament-workflows::workflows.actions.save_changes.label')"
            page-mode
        />
    </form>

    {{ $this->content }}

    <x-filament-actions::modals />
</x-filament-panels::page>
