<form wire:submit.prevent="create" class="max-w-2xl mx-auto">
    <x-filament::card>
        <x-slot name="header">
            <h2 class="text-lg font-bold">Создание заказа</h2>
        </x-slot>

        {{ $this->form }}

        <x-slot name="footer">
            <x-filament::button type="submit">
                Сохранить
            </x-filament::button>
        </x-slot>
    </x-filament::card>
</form>
