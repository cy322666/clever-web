<div
    x-data="{ open: false }"
    x-on:click.stop
    x-on:keydown.escape.window="open = false"
    class="relative inline-flex items-center"
>
    <button
        type="button"
        x-on:click="open = ! open"
        class="inline-flex items-center gap-x-1 text-sm font-semibold text-gray-950 transition hover:text-primary-600 dark:text-white dark:hover:text-primary-400"
    >
        <span>Группа</span>
        <x-filament::icon
            icon="heroicon-m-chevron-down"
            class="h-4 w-4 text-gray-400 transition"
            x-bind:class="{ 'rotate-180': open }"
        />
    </button>

    <div
        x-cloak
        x-show="open"
        x-transition.origin.top.left
        x-on:click.outside="open = false"
        class="absolute left-0 top-full z-50 mt-2 min-w-56 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg ring-1 ring-gray-950/5 dark:border-white/10 dark:bg-gray-900"
    >
        <button
            type="button"
            wire:click="$set('workflowGroupFilter', null)"
            x-on:click="open = false"
            class="block w-full px-3 py-2 text-left text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
        >
            Все группы
        </button>

        <button
            type="button"
            wire:click="$set('workflowGroupFilter', @js($emptyValue))"
            x-on:click="open = false"
            class="block w-full px-3 py-2 text-left text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
        >
            Без группы
        </button>

        @foreach ($options as $value => $label)
            <button
                type="button"
                wire:click="$set('workflowGroupFilter', @js((string) $value))"
                x-on:click="open = false"
                class="block w-full px-3 py-2 text-left text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>
</div>
