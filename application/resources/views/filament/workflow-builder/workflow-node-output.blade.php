@php($result = $getLivewire()->editingNodeResult())
@php($isCondition = \App\Services\Workflows\WorkflowOutputView::isCondition($result['output'] ?? null))
<section class="workflow-node-output" aria-label="Результат ноды" x-bind:aria-busy="workflowNodeIsRunning($wire, $wire.editingActionId)" wire:key="workflow-node-output-{{ hash('sha256', serialize($result)) }}">
    <div class="workflow-node-output__loading" role="status" aria-live="polite" x-show="workflowNodeIsRunning($wire, $wire.editingActionId) && !$wire.nodePreviewTaskId" x-cloak>
        <span class="workflow-run-spinner" aria-hidden="true"></span> Выполняется…
    </div>
    @unless($isCondition)
        <header>Результат <small>{{ $result['duration_ms'] ?? '' }}{{ isset($result['duration_ms']) ? ' мс' : '' }}</small></header>
    @endunless
    @if($getLivewire()->nodePreviewTaskId)
        @include('filament.workflow-builder.workflow-preview-progress', ['progress' => $getLivewire()->nodePreviewProgress])
    @elseif($result)
        @if($result['historical'] ?? false)<p>Данные сохранённого запуска. После правок результат не пересчитывается.</p>@endif
        @if(!empty($result['error']))<p role="alert" class="workflow-node-output__error">{{ $result['error'] }}</p>@endif
        @if($isCondition)
            @php($explanation = \App\Services\Workflows\WorkflowExecutionExplanation::forResult($result))
            <div class="workflow-condition-comparisons" aria-label="Что сравнивалось">
                @isset($result['duration_ms'])<small class="workflow-condition-duration">{{ $result['duration_ms'] }} мс</small>@endisset
                @forelse($explanation['details'] as $detail)
                    <section class="workflow-condition-comparison">
                        <strong>{{ $detail['passed'] ? '✓ Условие выполнено' : '× Условие не выполнено' }}</strong>
                        <dl>
                            <dt>Значение 1 <small>{{ $detail['actual_type'] }}</small></dt><dd>{{ $detail['actual'] }}</dd>
                            <dt>Проверка</dt><dd>{{ $detail['operator'] }}</dd>
                            @unless($detail['unary'])<dt>Значение 2 <small>{{ $detail['expected_type'] }}</small></dt><dd>{{ $detail['expected'] }}</dd>@endunless
                        </dl>
                    </section>
                @empty
                    <p>Значения сравнения не записаны в этом запуске.</p>
                @endforelse
            </div>
        @else
            @include('filament.workflow-builder.workflow-json-tree', ['value' => \App\Services\Workflows\WorkflowOutputView::value($result['output'] ?? [])])
        @endif
    @else
        <p class="workflow-node-output__empty">Запустите ноду, чтобы увидеть результат.</p>
    @endif
</section>

