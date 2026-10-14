<nav wire:ignore class="workflow-debug-trail workflow-debug-ui" x-bind:hidden="!$wire.debugOpen" hidden aria-label="Выполненные шаги">
    <span x-show="!debugResults().length">Здесь появится путь выполнения</span>
    <span x-show="debugResults().length > 40">Последние 40 шагов</span>
    <template x-for="(result, index) in debugResults().slice(-40)" :key="index">
        <button type="button" x-on:click="selectDebugResult(Math.max(0, debugResults().length - 40) + index)" :aria-current="selectedIndex === Math.max(0, debugResults().length - 40) + index ? 'step' : null" :title="debugStatusLabel(result.status)"><span class="workflow-debug-dot" :data-status="result.status"></span><span x-text="result.name || debugNodeName(result.id)"></span><x-filament::icon icon="heroicon-o-chevron-right" class="h-3 w-3"/></button>
    </template>
</nav>
