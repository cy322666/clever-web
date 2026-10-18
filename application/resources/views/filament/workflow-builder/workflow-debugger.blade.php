<aside class="workflow-debug-side workflow-debug-ui" x-bind:hidden="!$wire.debugOpen" hidden aria-label="Результаты отладки">
    <div class="workflow-debug-side__scroll">
        <section x-show="!debugConfigured()" class="workflow-debug-start">
            <h2>Данные для отладки</h2>
            <p>Выберите точку запуска и входные данные.</p>
            <fieldset x-bind:disabled="busy">
                @include('filament.workflow-builder.workflow-debug-input')
                <label class="workflow-debug-check"><input type="checkbox" wire:model.live="debugReal"> Реальные действия</label>
                <label x-show="$wire.debugReal" class="workflow-debug-check"><input type="checkbox" wire:model="debugRealConfirmed"> Подтверждаю изменения и отправку сообщений</label>
                <p class="workflow-debug-hint" x-text="$wire.debugReal ? 'Запуск изменяет данные и отправляет сообщения.' : 'Изменения отключены. Запросы могут читать amoCRM и внешние сервисы.'"></p>
            </fieldset>
        </section>
        {{-- Alpine owns result rows. Livewire must not morph its x-for clones. --}}
        <section wire:ignore x-show="debugConfigured()">
            <header class="workflow-debug-side__heading">
                <label for="workflow-debug-node-{{ $this->getId() }}">Нода</label>
                <select id="workflow-debug-node-{{ $this->getId() }}" x-bind:value="selectedDebugId || ''" x-on:change="selectDebugNode($event.target.value)">
                    <option value="">Выберите ноду на схеме</option>
                    <template x-for="node in debugNodes()" :key="node.id"><option :value="node.id" x-text="node.name"></option></template>
                </select>
                <div class="workflow-debug-meta"><span class="workflow-debug-dot" :data-status="debugCurrent().status"></span><span x-text="debugStatusLabel(debugCurrent().status)"></span><span x-show="debugCurrent().duration_ms != null" x-text="'· ' + debugCurrent().duration_ms + ' мс'"></span></div>
            </header>
            <div class="workflow-debug-pass" x-show="debugOccurrences().length">
                <span>Проход</span>
                <button type="button" aria-label="Предыдущий проход" x-on:click="moveDebugOccurrence(-1)" :disabled="selectedIndex === debugOccurrences()[0]?.index"><x-filament::icon icon="heroicon-o-chevron-left" class="h-4 w-4"/></button>
                <select aria-label="Проход ноды" :value="selectedIndex" x-on:change="selectDebugResult($event.target.value)">
                    <template x-for="(result, index) in debugOccurrences()" :key="result.index"><option :value="result.index" x-text="debugOccurrenceLabel(result, index)"></option></template>
                </select>
                <button type="button" aria-label="Следующий проход" x-on:click="moveDebugOccurrence(1)" :disabled="selectedIndex === debugOccurrences().at(-1)?.index"><x-filament::icon icon="heroicon-o-chevron-right" class="h-4 w-4"/></button>
            </div>
            <div class="workflow-debug-tabs" role="tablist" aria-label="Данные ноды">
                <button type="button" role="tab" id="debug-summary-{{ $this->getId() }}" aria-controls="debug-data-{{ $this->getId() }}" :aria-selected="debugTab === 'summary'" x-on:click="debugTab = 'summary'">Результат</button>
                <button type="button" role="tab" id="debug-input-{{ $this->getId() }}" aria-controls="debug-data-{{ $this->getId() }}" :aria-selected="debugTab === 'input'" x-on:click="debugTab = 'input'">Вход</button>
                <button type="button" role="tab" id="debug-output-{{ $this->getId() }}" aria-controls="debug-data-{{ $this->getId() }}" :aria-selected="debugTab === 'output'" x-on:click="debugTab = 'output'">Выход</button>
            </div>
            <div id="debug-data-{{ $this->getId() }}" role="tabpanel" :aria-labelledby="'debug-' + debugTab + '-{{ $this->getId() }}'" class="workflow-debug-data">
                <div x-show="selectedIndex < 0 && !debugSelectedTrigger()" class="workflow-debug-empty"><strong>Нода ещё не выполнялась</strong><p>Выполните следующий шаг или выберите ноду с результатом.</p></div>
                <div x-show="(selectedIndex >= 0 || debugSelectedTrigger()) && debugTab === 'summary'">
                    <div class="workflow-debug-outcome" :data-tone="debugExplanation().tone"><strong x-text="debugExplanation().title"></strong><p x-show="debugExplanation().note" x-text="debugExplanation().note"></p></div>
                    <template x-for="(comparison, index) in debugExplanation().details" :key="index">
                        <section class="workflow-debug-comparison">
                            <strong x-text="(comparison.passed ? '✓ ' : '× ') + 'Условие ' + (index + 1)"></strong>
                            <label>Выражение</label><code x-text="comparison.label"></code>
                            <label>Значение 1 <small x-text="comparison.actual_type"></small></label><code x-text="comparison.actual"></code>
                            <label>Проверка</label><div x-text="comparison.operator"></div>
                            <div x-show="!comparison.unary"><label>Значение 2 <small x-text="comparison.expected_type"></small></label><code x-text="comparison.expected"></code></div>
                        </section>
                    </template>
                    <div x-show="!debugExplanation().details.length && !debugCurrent().error">
                        <p x-show="debugOutputEmpty()">Нода не вернула выходных данных.</p>
                        <div x-show="!debugOutputEmpty()">@include('filament.workflow-builder.workflow-json-tree', ['expression' => 'debugOutputValue()', 'title' => 'Выходные данные'])</div>
                    </div>
                </div>
                <div x-show="(selectedIndex >= 0 || debugSelectedTrigger()) && debugTab !== 'summary'">
                    <p x-show="debugTab === 'output' && debugOutputEmpty()">Нода не вернула выходных данных.</p>
                    <div x-show="debugTab === 'input' || !debugOutputEmpty()">@include('filament.workflow-builder.workflow-json-tree', ['expression' => "debugTab === 'input' ? debugInputValue() : debugOutputValue()"])</div>
                </div>
            </div>
        </section>
    </div>
    <footer class="workflow-debug-side__footer" x-show="debugConfigured()">
        <button type="button" x-on:click="openDebugSettings()" :disabled="!selectedDebugId || busy || playing"><x-filament::icon icon="heroicon-o-cog-6-tooth" class="h-4 w-4"/>Открыть настройки ноды<x-filament::icon icon="heroicon-o-chevron-right" class="h-4 w-4"/></button>
    </footer>
</aside>
