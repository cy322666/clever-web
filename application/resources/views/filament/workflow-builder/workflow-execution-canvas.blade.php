<div class="workflow-execution-viewer" x-data="workflowExecutionViewer(@js($graph))" x-on:workflow-history-graph="updateGraph($event.detail.graph)" wire:key="execution-{{ $runId }}">
    <div hidden wire:key="execution-data-{{ $runId }}-{{ md5(json_encode($graph)) }}" x-data="{ incoming: @js($graph) }" x-init="$dispatch('workflow-history-graph', { graph: incoming })"></div>
    @if($graph['historical_fallback'])
        <div class="workflow-execution-note">Исходная версия этого сценария не сохранена. Показан фактический порядок записанных шагов.</div>
    @endif
    <section class="workflow-node-editor workflow-execution-canvas" x-data="workflowNodeCanvas('clever.workflow.history.{{ $runId }}')"
        x-init="$nextTick(() => { initializeCanvas(); $nextTick(() => fitView()); })"
        x-on:pointermove.window="moveCanvas($event)" x-on:pointerup.window="stopCanvasInteraction($event)" x-on:pointercancel.window="stopCanvasInteraction($event)">
        <div x-ref="viewport" class="workflow-node-editor__viewport" x-on:pointerdown="startCanvasInteraction($event)">
            <button type="button" class="workflow-canvas-fit" x-on:pointerdown.stop x-on:click.stop="fitView()" aria-label="Показать весь запуск" title="Показать весь запуск"><x-filament::icon icon="heroicon-o-arrows-pointing-in" class="h-4 w-4"/></button>
            <div x-ref="stage" x-bind:style="stageStyle()" class="workflow-node-editor__stage">
                <svg x-ref="edgeLayer" class="workflow-node-edge-layer" aria-hidden="true" wire:ignore></svg>
                <main id="workflow-canvas" class="workflow-builder workflow-execution-nodes">
                    @foreach($graph['nodes'] as $node)
                        <div data-workflow-node-id="{{ $node['id'] }}" class="workflow-execution-node" style="left:{{ $node['x'] }}px;top:{{ $node['y'] }}px">
                            <button type="button" x-on:click="selectNode(@js($node['id']))" x-bind:class="{ 'is-selected': selectedNodeId() === @js($node['id']) }" aria-label="Данные ноды: {{ $node['name'] }}">
                                @if(str_starts_with($node['id'], 'trigger'))
                                    <x-filament-workflows::workflows.trigger-card :type="$node['type']" :config="[]" :metadata="['name' => $node['name'], 'icon' => $node['icon']]" :read-only="true"/>
                                @else
                                    <x-filament-workflows::workflows.action-card :action="$node['action']" :metadata="['name' => $node['name'], 'icon' => $node['icon'], 'category' => '']" :read-only="true" data-execution-status="{{ $node['status'] }}"/>
                                    @if($node['result'])<span class="workflow-execution-order" title="{{ ($node['execution_count'] ?? 1) > 1 ? 'Повторных выполнений ноды; нажмите для просмотра каждого' : 'Сущностей на выходе' }}">{{ ($node['execution_count'] ?? 1) > 1 ? '×'.$node['execution_count'] : $node['item_count'] }}</span>@endif
                                @endif
                            </button>
                        </div>
                    @endforeach
                </main>
                <div x-ref="edgeControls" hidden>
                    @foreach($graph['edges'] as $edge)
                        <span data-workflow-edge-source="{{ $edge['sourceId'] }}" data-workflow-edge-port="{{ $edge['sourcePort'] }}" data-workflow-edge-target="{{ $edge['targetId'] }}" data-workflow-edge-active="{{ $edge['active'] ? 'true' : 'false' }}"></span>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
    <section class="workflow-execution-inspector" wire:ignore>
        <header class="workflow-debugger__stepbar">
            <button type="button" x-on:click="previous()" x-bind:disabled="selectedIndex < 0" aria-label="Предыдущий шаг">←</button>
            <strong x-text="current().name || 'Данные запуска'"></strong>
            <span x-text="current().sequence ? statusLabel(current().status) + ' · ' + (current().duration_ms || 0) + ' мс' : ''"></span>
            <button type="button" x-on:click="next()" x-bind:disabled="selectedIndex >= graph.results.length - 1" aria-label="Следующий шаг">→</button>
            <span x-text="selectedIndex >= 0 ? 'Шаг ' + (selectedIndex + 1) + ' из ' + graph.results.length : ''"></span>
            <select x-show="executions().length > 1" x-model.number="selectedIndex" x-on:change="selectExecution($event.target.value)" aria-label="Выполнение ноды">
                <template x-for="result in executions()" :key="result.execution_id ?? result.index"><option :value="result.index" x-text="executionLabel(result)"></option></template>
            </select>
        </header>
        <section class="workflow-execution-explanation" x-show="current().explanation" x-cloak aria-label="Объяснение результата шага">
            <strong x-text="current().explanation?.title"></strong>
            <p x-show="current().explanation?.note" x-text="current().explanation?.note"></p>
            <ul>
                <template x-for="(detail, index) in (current().explanation?.details || [])" :key="index">
                    <li :class="detail.passed ? 'is-passed' : 'is-not-passed'">
                        <span x-text="detail.passed ? '✓ Совпало' : '— Не совпало'"></span>
                        <strong x-text="detail.label"></strong>
                        <span x-text="'Получено: ' + detail.actual"></span>
                        <span x-text="'Проверка: ' + detail.operator + (detail.unary ? '' : ' ' + detail.expected)"></span>
                    </li>
                </template>
            </ul>
        </section>
        <div class="workflow-data-panes">
            <section><h3 x-text="isAmoStep() ? (exchange() ? 'Запрос в amoCRM' : 'Настройки шага · HTTP-журнал отсутствует') : 'Вход'"></h3>
                <select x-show="exchanges().length > 1" x-model.number="exchangeIndex" aria-label="Запрос шага"><template x-for="(exchange, index) in exchanges()" :key="index"><option :value="index" x-text="(index + 1) + '. ' + exchange.request.method + ' ' + exchange.request.url"></option></template></select>
                @include('filament.workflow-builder.workflow-json-tree', ['expression' => 'requestData()'])</section>
            <section><h3 x-text="isAmoStep() && exchange() ? 'Ответ amoCRM' : 'Результат шага'"></h3>
                @include('filament.workflow-builder.workflow-json-tree', ['expression' => 'responseData()'])
                <template x-if="current().output?.create_request?.sent === false"><div><h3>Подготовленное тело создания · не отправлялось: контакт найден</h3>@include('filament.workflow-builder.workflow-json-tree', ['expression' => 'current().output.create_request.body'])</div></template>
            </section>
        </div>
    </section>
</div>
