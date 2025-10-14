<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Форма заявки</title>

    {{-- Подключаем стили Filament --}}
    @filamentStyles
</head>
<body class="antialiased bg-gray-50">

<div class="max-w-lg mx-auto mt-10 p-6 bg-white rounded-xl shadow-md">
    <form wire:submit="submit">
        {{ $this->form }}
        <x-filament::button type="submit" class="mt-4">
            Отправить
        </x-filament::button>
    </form>
</div>

{{-- Подключаем скрипты Filament --}}
{{--@filamentScripts--}}
{{--@livewireScripts--}}
</body>
</html>


{{--<x-filament::layouts.app>--}}
{{--    <div class="max-w-2xl mx-auto mt-10">--}}
{{--        {{ $this->form }}--}}
{{--    </div>--}}
{{--</x-filament::layouts.app>--}}

