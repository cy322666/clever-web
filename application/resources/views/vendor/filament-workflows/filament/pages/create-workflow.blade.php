@if (request()->boolean('embedded'))
    <style>
        .fi-sidebar,
        .fi-topbar,
        .fi-main-ctn > .fi-topbar,
        .fi-breadcrumbs,
        .fi-page-header {
            display: none !important;
        }

        .fi-main,
        .fi-page,
        .fi-page-content {
            max-width: none !important;
            padding: 0 !important;
        }

        body {
            background: transparent !important;
        }
    </style>
@endif

<x-filament-panels::page>
    <form wire:submit="create">
        {{ $this->form }}

        <x-filament-workflows::workflow-builder :submit-label="__('filament-workflows::workflows.actions.create_workflow.label')" />
    </form>

    <x-filament-actions::modals />
</x-filament-panels::page>
