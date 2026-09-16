<section x-data="workflowDebugPanel()" x-show="$wire.debugOpen" x-cloak class="workflow-debugger"
    x-init="selectedIndex = ($wire.debugState.results || []).length ? 0 : -1"
    x-on:workflow-debug-updated.window="selectedIndex = ($event.detail.state.results || []).length - 1"
    x-on:workflow-debug-select.window="selectNode($event.detail.id)">
    <header class="workflow-debugger__bar">
        <strong>Отладка</strong>
        <span x-text="$wire.debugState.status === 'historical' ? 'Данные сохранённого запуска · ничего не выполняется' : ($wire.debugState.real ? 'Реальное выполнение' : 'Без изменений · запросы читают amoCRM')"></span>
        <div class="workflow-debugger__actions">
            <button type="button" wire:click="editWorkflowDebugInput" x-show="$wire.debugState.results?.length || $wire.debugState.status === 'expired'" x-bind:disabled="busy || playing">Изменить данные</button>
            <button type="button" x-on:click="startAndRun()" x-bind:disabled="busy || playing" class="workflow-debugger__primary" x-text="$wire.debugReal ? 'Выполнить поток' : 'Проверить поток'"></button>
            <button type="button" wire:click="startWorkflowDebug" wire:loading.attr="disabled" x-bind:disabled="busy || playing">Пошагово</button>
            <button type="button" x-on:click="next()" x-bind:disabled="busy || $wire.debugState.status !== 'ready'">Выполнить шаг</button>
            <button type="button" x-show="!playing" x-on:click="run()" x-bind:disabled="busy || $wire.debugState.status !== 'ready'">Продолжить</button>
            <button type="button" x-show="playing" x-on:click="playing = false">Пауза</button>
            <button type="button" x-on:click="playing = false" wire:click="closeWorkflowDebugger" aria-label="Закрыть отладку"><x-filament::icon icon="heroicon-o-x-mark" class="h-4 w-4"/></button>
        </div>
    </header>
    <div class="workflow-debugger__setup" x-show="!$wire.debugState.results?.length">
        @include('filament.workflow-builder.workflow-debug-input')
        <div>
            <label class="workflow-debugger__toggle"><input type="checkbox" wire:model.live="debugReal"> Реальные действия</label>
            <label x-show="$wire.debugReal" class="workflow-debugger__toggle"><input type="checkbox" wire:model="debugRealConfirmed"> Подтверждаю изменения и отправку сообщений</label>
            <small>«Проверить поток» пройдёт всю цепочку. «Пошагово» подготовит данные и позволит выполнять по одному шагу. После изменения настроек начните новую проверку.</small>
        </div>
    </div>
    <div class="workflow-debugger__error" x-show="$wire.debugState.error" x-text="$wire.debugState.error"></div>
    <div x-show="$wire.debugState.results?.length" class="workflow-debugger__inspection">
        <div class="workflow-debugger__stepbar">
            <button type="button" x-on:click="selectedIndex = Math.max(0, selectedIndex - 1)" aria-label="Предыдущий результат">←</button>
            <strong x-text="current().name || 'Нода ещё не выполнялась'"></strong>
            <span x-text="current().sequence ? 'Шаг ' + current().sequence + ' · ' + statusLabel(current().status) : ''"></span>
            <button type="button" x-on:click="selectedIndex = Math.min(($wire.debugState.results || []).length - 1, selectedIndex + 1)" aria-label="Следующий результат">→</button>
            <button type="button" x-show="current().id" x-on:click="$wire.openWorkflowActionEditor(current().id)">Настроить</button>
        </div>
        <div class="workflow-data-panes">
            <section><h3>Вход</h3><pre x-text="pretty(current().resolved_input ?? current().input ?? {})"></pre></section>
            <section><h3>Выход</h3><pre x-text="pretty(current().error ? {error: current().error} : current().output ?? {})"></pre></section>
        </div>
    </div>
</section>
