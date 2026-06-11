<div class="mb-4">
    <x-filament::button
        type="button"
        icon="heroicon-o-variable"
        color="gray"
        size="md"
        class="w-full justify-center"
        x-data
        x-on:click="window.dispatchEvent(new CustomEvent('workflow-masks-open'))"
    >
        Переменные
    </x-filament::button>
</div>
