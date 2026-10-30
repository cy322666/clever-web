<aside class="workflow-debug-side workflow-debug-ui" x-bind:hidden="!$wire.debugOpen" hidden aria-label="Отладка ноды">
    <header class="workflow-debug-history">
        <details x-ref="debugHistory" x-on:toggle="if ($el.open) loadDebugHistory()" x-on:click.outside="$el.open = false" x-on:keydown.escape.stop="$el.open = false">
            <summary><x-filament::icon icon="heroicon-o-clock" class="h-5 w-5"/><span x-text="debugHistoryLabel()">История</span><x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4"/></summary>
            <div class="workflow-debug-history__menu">
                <div class="workflow-debug-history__search"><input type="search" x-model="historyQuery" placeholder="Найти запуск…" aria-label="Найти запуск"/><button type="button" x-on:click="loadDebugHistory(true)" :disabled="historyLoading || busy" aria-label="Обновить историю" title="Обновить историю"><x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4"/></button></div>
                <p x-show="historyLoading" role="status">Загружаем историю…</p>
                <p x-show="historyError" x-text="historyError" role="alert"></p>
                <p x-show="!historyLoading && !historyError && !filteredDebugHistory().length">Запуски не найдены.</p>
                <template x-for="run in filteredDebugHistory()" :key="run.id">
                    <button type="button" class="workflow-debug-history__run" x-on:click="inspectDebugRun(run.id)" :disabled="busy || playing" :aria-current="Number($wire.debugState.run_id) === Number(run.id) ? 'true' : null"><strong x-text="'#' + run.id"></strong><span x-text="run.label"></span><x-filament::icon x-show="Number($wire.debugState.run_id) === Number(run.id)" icon="heroicon-o-check" class="h-4 w-4"/></button>
                </template>
            </div>
        </details>
        <div class="workflow-debug-history__navigation">
            <button type="button" x-on:click="moveDebugNode(-1)" :disabled="!canMoveDebugNode(-1)" aria-label="Предыдущая нода" title="Предыдущая нода"><x-filament::icon icon="heroicon-o-chevron-left" class="h-4 w-4"/></button>
            <button type="button" x-on:click="moveDebugNode(1)" :disabled="!canMoveDebugNode(1)" aria-label="Следующая нода" title="Следующая нода"><x-filament::icon icon="heroicon-o-chevron-right" class="h-4 w-4"/></button>
            <button type="button" x-on:click="closeDebug()" :disabled="busy || playing" aria-label="Закрыть отладку" title="Закрыть отладку"><x-filament::icon icon="heroicon-o-x-mark" class="h-4 w-4"/></button>
        </div>
    </header>
    <header class="workflow-debug-side__heading">
        <h2 x-text="debugNodeName(selectedDebugId) || 'Данные для отладки'"></h2>
        <div class="workflow-debug-meta" x-show="selectedDebugId"><span class="workflow-debug-dot" :data-status="debugCurrent().status"></span><span x-text="debugStatusLabel(debugCurrent().status)"></span></div>
        <span class="workflow-debug-duration" x-show="debugCurrent().duration_ms != null" x-text="debugCurrent().duration_ms + ' мс'"></span>
    </header>
    <nav class="workflow-debug-tabs" role="tablist" aria-label="Данные ноды" x-show="debugConfigured() || selectedDebugId">
        @foreach(['settings' => 'Настройки', 'input' => 'Вход', 'output' => 'Выход'] as $tab => $label)
            <button type="button" role="tab" id="debug-{{ $tab }}-{{ $this->getId() }}" aria-controls="{{ $tab === 'settings' ? 'workflow-debug-settings-' : 'debug-data-' }}{{ $this->getId() }}" :aria-selected="debugTab === @js($tab)" :tabindex="debugTab === @js($tab) ? 0 : -1" x-on:click="setDebugTab(@js($tab))" x-on:keydown.arrow-right.prevent="nextDebugTab(1)" x-on:keydown.arrow-left.prevent="nextDebugTab(-1)">{{ $label }}</button>
        @endforeach
    </nav>
    <div class="workflow-debug-side__body">
        <section x-show="!debugConfigured() && !selectedDebugId" class="workflow-debug-start">
            <p>Выберите прошлый запуск в истории или подготовьте новые входные данные.</p>
            <fieldset x-bind:disabled="busy">
                @include('filament.workflow-builder.workflow-debug-input')
                <label class="workflow-debug-check"><input type="checkbox" wire:model.live="debugReal"> Реальные действия</label>
                <label x-show="$wire.debugReal" class="workflow-debug-check"><input type="checkbox" wire:model="debugRealConfirmed"> Подтверждаю изменения и отправку сообщений</label>
                <p class="workflow-debug-hint" x-text="$wire.debugReal ? 'Запуск изменяет данные и отправляет сообщения.' : 'Изменения отключены. Запросы могут читать amoCRM и внешние сервисы.'"></p>
            </fieldset>
        </section>
        <section id="workflow-debug-settings-{{ $this->getId() }}" role="tabpanel" aria-labelledby="debug-settings-{{ $this->getId() }}" :aria-owns="debugTab === 'settings' && debugSettingsAvailable() ? 'workflow-node-inspector-{{ $this->getId() }}' : null" class="workflow-debug-settings" x-show="debugTab === 'settings' && selectedDebugId" aria-label="Настройки выбранной ноды">
            <p x-show="!debugSettingsAvailable()">У этой ноды нет параметров. Выберите «Вход» или «Выход», чтобы посмотреть данные.</p>
        </section>
        {{-- Livewire updates the state, Alpine owns the JSON rows and record selection. --}}
        <section wire:ignore id="debug-data-{{ $this->getId() }}" role="tabpanel" :aria-labelledby="'debug-' + debugTab + '-{{ $this->getId() }}'" class="workflow-debug-data" x-show="debugTab !== 'settings' && (debugConfigured() || selectedDebugId)">
            <div class="workflow-debug-records">
                <button type="button" aria-label="Предыдущая запись" x-on:click="moveDebugOccurrence(-1)" :disabled="debugOccurrencePosition() <= 0"><x-filament::icon icon="heroicon-o-chevron-left" class="h-4 w-4"/></button>
                <span class="workflow-debug-records__count" x-text="debugRecordLabel()"></span>
                <button type="button" aria-label="Следующая запись" x-on:click="moveDebugOccurrence(1)" :disabled="debugOccurrencePosition() >= debugOccurrences().length - 1"><x-filament::icon icon="heroicon-o-chevron-right" class="h-4 w-4"/></button>
            </div>
            <p class="workflow-debug-error workflow-debug-error--inline" x-show="debugCurrent().error" role="alert"><x-filament::icon icon="heroicon-o-exclamation-circle" class="h-5 w-5"/><span x-text="debugCurrent().error"></span></p>
            <div class="workflow-debug-empty" x-show="!selectedDebugId"><strong>Выберите ноду на схеме</strong><p>Здесь появятся входные и выходные данные выбранной записи.</p></div>
            <div class="workflow-debug-empty" x-show="selectedDebugId && selectedIndex < 0 && !debugSelectedTrigger()"><strong>Нода ещё не выполнялась</strong><p>Нажмите значок запуска над нодой, чтобы проверить её на текущих данных.</p></div>
            <div class="workflow-debug-json" x-show="selectedIndex >= 0 || debugSelectedTrigger()">
                @include('filament.workflow-builder.workflow-json-tree', ['expression' => "debugTab === 'input' ? debugInputValue() : debugOutputValue()", 'numbered' => true])
            </div>
        </section>
        <p class="workflow-debug-error" x-show="debugError || historyError" x-text="debugError || historyError" role="alert"></p>
    </div>
</aside>
