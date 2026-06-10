<!DOCTYPE html>
<html class="fi" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />

        <meta name="application-name" content="{{ config('app.name') }}" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />

        <title>{{ config('app.name') }}</title>

        <style>
            [x-cloak] {
                display: none !important;
            }
        </style>

        @livewireStyles
        @filamentStyles
        {{ filament()->getTheme()->getHtml() }}
        @vite('resources/css/app.css')
    </head>

    <body class="fi-body antialiased">
        {{ $slot }}

        @livewire('notifications')

        @livewireScripts
        @filamentScripts
        @vite('resources/js/app.js')
        <x-impersonate::banner/>
    </body>
</html>
