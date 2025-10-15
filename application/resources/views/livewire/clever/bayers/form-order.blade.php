<form wire:submit.prevent="create" class="max-w-2xl mx-auto">
    <x-filament::card>
        <x-slot name="heading">
            Создание заказа
        </x-slot>

        {{ $this->form }}

        <x-slot name="footerActions">
            <x-filament::button type="submit" class="ml-auto">
                Сохранить
            </x-filament::button>
        </x-slot>
    </x-filament::card>
</form>
