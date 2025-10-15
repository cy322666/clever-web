<form wire:submit.prevent="create" class="max-w-2xl mx-auto">
    <x-filament::card>
        <x-slot name="heading">
            Создание заказа
        </x-slot>

        {{ $this->form }}

        @if (View::exists('filament::components.card.footerActions'))
            <x-slot name="footerActions">
                <x-filament::button type="submit">Сохранить</x-filament::button>
            </x-slot>
        @else
            <x-slot name="footer">
                <x-filament::button type="submit">Сохранить</x-filament::button>
            </x-slot>
        @endif
    </x-filament::card>
</form>
