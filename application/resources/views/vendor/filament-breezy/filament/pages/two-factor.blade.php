<x-filament-panels::page.simple>
    <form wire:submit="authenticate">
        {{ $this->form }}

        <x-filament::actions :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()" />
    </form>
</x-filament-panels::page.simple>
