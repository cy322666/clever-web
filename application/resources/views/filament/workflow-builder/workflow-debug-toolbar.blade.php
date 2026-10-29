<section class="workflow-debug-toolbar workflow-debug-ui" x-bind:hidden="!$wire.debugOpen" hidden aria-label="Управление отладкой" :aria-busy="busy">
    <div class="workflow-debug-toolbar__row">
        <strong class="workflow-debug-toolbar__title"><x-filament::icon icon="heroicon-o-beaker" class="h-4 w-4"/>Отладка</strong>
        <span class="workflow-debug-status" role="status" aria-live="polite"><span class="workflow-run-spinner" x-show="busy" aria-hidden="true"></span><span x-text="debugRunLabel()"></span></span>
        <span class="workflow-debug-mode" :data-real="debugModeLabel() === 'Реальные действия'" x-text="debugModeLabel()"></span>
        <div class="workflow-debug-toolbar__actions">
            <button type="button" x-show="!debugConfigured()" x-on:click="startDebug(false)" :disabled="busy || playing" title="Подготовить пошаговую отладку">Пошагово</button>
            <button type="button" x-show="$wire.debugState.status === 'ready'" x-on:click="nextDebug()" :disabled="busy || playing || !!debugError" aria-label="Следующий шаг" title="Следующий шаг"><x-filament::icon icon="heroicon-o-forward" class="h-4 w-4"/></button>
            <button type="button" x-show="$wire.debugState.status === 'ready' && !playing" x-on:click="runDebug()" :disabled="busy || !!debugError" aria-label="Продолжить отладку" title="Продолжить отладку"><x-filament::icon icon="heroicon-s-play" class="h-4 w-4"/></button>
            <button type="button" x-show="playing" x-on:click="playing = false" aria-label="Пауза" title="Остановиться после текущего шага"><x-filament::icon icon="heroicon-s-pause" class="h-4 w-4"/></button>
            <button type="button" x-show="debugConfigured()" x-on:click="resetDebug()" :disabled="busy || playing" aria-label="Изменить входные данные" title="Изменить входные данные"><x-filament::icon icon="heroicon-o-adjustments-horizontal" class="h-4 w-4"/></button>
        </div>
    </div>
    <p class="workflow-debug-notice" x-show="busy && debugRequestName === 'nextWorkflowDebugStep'" role="status">Выполняется текущий шаг…</p>
    <p class="workflow-debug-error" role="alert" x-show="debugError" x-text="debugError"></p>
</section>
