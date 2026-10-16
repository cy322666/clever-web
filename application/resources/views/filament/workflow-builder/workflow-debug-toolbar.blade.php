<section class="workflow-debug-toolbar workflow-debug-ui" x-bind:hidden="!$wire.debugOpen" hidden aria-label="Управление отладкой" :aria-busy="busy">
    <div class="workflow-debug-toolbar__row">
        <strong class="workflow-debug-toolbar__title"><x-filament::icon icon="heroicon-o-beaker" class="h-4 w-4"/>Отладка</strong>
        <span class="workflow-debug-status" role="status" aria-live="polite"><span class="workflow-run-spinner" x-show="busy" aria-hidden="true"></span><span x-text="debugRunLabel()"></span></span>
        <span class="workflow-debug-mode" :data-real="debugConfigured() ? $wire.debugState.real : $wire.debugReal" x-text="$wire.debugState.status === 'historical' ? 'Сохранённые данные' : ((debugConfigured() ? $wire.debugState.real : $wire.debugReal) ? 'Реальные действия' : 'Без записи в CRM')" title="В режиме без записи запросы могут читать внешние сервисы"></span>
        <span class="workflow-debug-progress" x-show="debugConfigured()" x-text="'Выполнено шагов: ' + debugResults().length + ($wire.debugState.next_id ? ' · Далее: ' + debugNodeName($wire.debugState.next_id) : '')"></span>
        <div class="workflow-debug-toolbar__actions">
            <button type="button" x-show="debugConfigured()" x-on:click="resetDebug()" :disabled="busy || playing" title="Настроить новый запуск">Новый запуск</button>
            <button type="button" x-show="!debugConfigured()" x-on:click="startDebug(false)" :disabled="busy || playing">Пошагово</button>
            <button type="button" class="workflow-debug-primary" x-show="$wire.debugState.status === 'ready'" x-on:click="nextDebug()" :disabled="busy || playing || !!debugError"><x-filament::icon icon="heroicon-s-play" class="h-4 w-4"/>Следующий шаг</button>
            <button type="button" x-show="$wire.debugState.status === 'ready' && !playing" x-on:click="runDebug()" :disabled="busy || !!debugError">Продолжить</button>
            <button type="button" x-show="playing" x-on:click="playing = false" title="Остановиться после текущего шага"><x-filament::icon icon="heroicon-s-pause" class="h-4 w-4"/>Пауза</button>
            <button type="button" x-on:click="closeDebug()" :disabled="busy || playing" aria-label="Закрыть отладку" title="Закрыть отладку"><x-filament::icon icon="heroicon-o-x-mark" class="h-4 w-4"/></button>
        </div>
    </div>
    <p class="workflow-debug-notice" x-show="busy && debugRequestName === 'nextWorkflowDebugStep'" role="status">Выполняется запрос. Пауза остановит следующие шаги; начатое действие или цикл завершится.</p>
    <p class="workflow-debug-error" role="alert" x-show="debugError || $wire.debugState.error" x-text="debugError || $wire.debugState.error"></p>
</section>
