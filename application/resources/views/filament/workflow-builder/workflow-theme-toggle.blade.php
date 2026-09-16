<button type="button" class="workflow-workbench__quick-action workflow-theme-toggle" x-data
    x-on:click="$dispatch('theme-changed', document.documentElement.classList.contains('dark') ? 'light' : 'dark')"
    aria-label="Переключить тему" title="Переключить тему">
    <x-filament::icon icon="heroicon-o-sun" class="h-5 w-5 hidden dark:block"/>
    <x-filament::icon icon="heroicon-o-moon" class="h-5 w-5 dark:hidden"/>
</button>
