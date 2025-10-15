<form wire:submit.prevent="create" class="max-w-2xl mx-auto space-y-6">
    <x-filament::card>
        <x-slot name="heading">
            Создание заказа
        </x-slot>

        {{ $this->form }}
    </x-filament::card>

    <div class="text-right">
        <x-filament::button type="submit">
            Сохранить
        </x-filament::button>
    </div>
</form>
