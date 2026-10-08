@php($result = $getLivewire()->editingNodeResult())
<section class="workflow-node-output" aria-label="Результат ноды">
    <header>Результат <small>{{ $result['duration_ms'] ?? '' }}{{ isset($result['duration_ms']) ? ' мс' : '' }}</small></header>
    @if($result)
        @if($result['historical'] ?? false)<p>Данные сохранённого запуска. После правок результат не пересчитывается.</p>@endif
        @if(!empty($result['error']))<p role="alert" class="workflow-node-output__error">{{ $result['error'] }}</p>@endif
        @if(\App\Services\Workflows\WorkflowOutputView::isCondition($result['output'] ?? null))
            <p>{{ $result['output']['passed'] ? 'Условие выполнено. Ветка «Да».' : 'Условие не выполнено. Ветка «Нет».' }}</p>
        @else
            @include('filament.workflow-builder.workflow-json-tree', ['value' => \App\Services\Workflows\WorkflowOutputView::value($result['output'] ?? [])])
        @endif
    @else
        <p class="workflow-node-output__empty">Запустите ноду, чтобы увидеть результат.</p>
    @endif
</section>

