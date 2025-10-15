<form wire:submit.prevent="create">
    {{ $this->form }}
    <x-filament::button type="submit">Сохранить</x-filament::button>
</form>
