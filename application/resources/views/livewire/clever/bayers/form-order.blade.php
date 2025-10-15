<form wire:submit.prevent="create" class="max-w-2xl mx-auto">
    <x-filament::section>
        <x-slot name="heading">Создание заказа</x-slot>

        {{ $this->form }}

        <x-slot name="footer">
            <x-filament::button type="submit">
                Сохранить
            </x-filament::button>
        </x-slot>
    </x-filament::section>
</form>
