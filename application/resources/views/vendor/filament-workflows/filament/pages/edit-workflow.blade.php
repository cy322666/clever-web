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
    @unless (request()->boolean('embedded'))
        <x-slot name="headerActions">
            {{ $this->deleteAction }}
        </x-slot>
    @endunless

    <form wire:submit="save">
        {{ $this->form }}

        <x-filament-workflows::workflow-builder :submit-label="__('filament-workflows::workflows.actions.save_changes.label')" />
    </form>

    {{ $this->content }}

    <x-filament-actions::modals />
</x-filament-panels::page>
