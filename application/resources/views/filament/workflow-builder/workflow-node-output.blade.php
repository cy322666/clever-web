@php($result = $getLivewire()->editingNodeResult())
@php($isCondition = in_array($result['type'] ?? '', ['condition', 'control-condition'], true) || \App\Services\Workflows\WorkflowOutputView::isCondition($result['output'] ?? null))
@php($branch = \App\Services\Workflows\WorkflowOutputView::conditionBranch($result))
<section class="workflow-node-output" aria-label="Результат ноды" x-bind:aria-busy="workflowNodeIsRunning($wire, $wire.editingActionId)" wire:key="workflow-node-output-{{ hash('sha256', serialize($result)) }}">
    <div class="workflow-node-output__loading" role="status" aria-live="polite" x-show="workflowNodeIsRunning($wire, $wire.editingActionId) && !$wire.nodePreviewTaskId" x-cloak>
        <span class="workflow-run-spinner" aria-hidden="true"></span> Выполняется…
    </div>
    @isset($result['duration_ms'])<small class="workflow-node-output__duration">{{ $result['duration_ms'] }} мс</small>@endisset
    @if($getLivewire()->nodePreviewTaskId)
        @include('filament.workflow-builder.workflow-preview-progress', ['progress' => $getLivewire()->nodePreviewProgress])
    @elseif($result)
        @if(!empty($result['error']))<p role="alert" class="workflow-node-output__error">{{ $result['error'] }}</p>@endif
        @if($isCondition)
            @php($explanation = \App\Services\Workflows\WorkflowExecutionExplanation::forResult($result))
            <div class="workflow-condition-comparisons" aria-label="Что сравнивалось">
                @if($branch)<strong class="workflow-condition-branch">Ветка «{{ $branch }}»</strong>@endif
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
            @if(($result['output_recorded'] ?? true) === false)
                <p class="workflow-node-output__empty">Выходные данные не сохранены или срок их хранения истёк.</p>
            @elseif(($result['output'] ?? []) === [])
                <p class="workflow-node-output__empty">Нода не вернула выходных данных.</p>
            @else
                @php($visibleOutput = \App\Services\Workflows\WorkflowOutputView::displayValue($result['output']))
                @if(is_array($visibleOutput) && ($visibleOutput['items'] ?? null) === [] && is_array($result['output']['items'] ?? null))
                    <p class="workflow-node-output__empty">Элементы не найдены.</p>
                @else
                    @include('filament.workflow-builder.workflow-json-tree', ['value' => $visibleOutput])
                @endif
            @endif
        @endif
    @else
        <p class="workflow-node-output__empty">Запустите ноду, чтобы увидеть результат.</p>
    @endif
</section>

