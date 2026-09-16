<x-filament-panels::page class="workflow-list-page workflow-analytics-page">
    @include('filament.workflow-builder.workflow-overview-nav', ['active' => 'analytics'])
    @php($summary = $this->summary())
    <div class="workflow-analytics__content">
    <div class="workflow-analytics__period"><span>Все сценарии аккаунта</span><select wire:model.live="period" aria-label="Период аналитики"><option value="7">7 дней</option><option value="14">14 дней</option><option value="30">30 дней</option><option value="90">90 дней</option></select></div>
    <div class="workflow-analytics__stats">
        @foreach(['Выполнения' => number_format($summary['total'], 0, ',', ' '), 'Ошибки' => $summary['failed'], 'Успешно завершены' => $summary['success_rate'] === null ? '—' : $summary['success_rate'].'%', 'Среднее время' => $summary['duration'] === null ? '—' : $summary['duration'].' с'] as $label => $value)
            <section><span>{{ $label }}</span><strong>{{ $value }}</strong></section>
        @endforeach
    </div>
    <section class="workflow-analytics__chart"><h2>Выполнения по дням</h2>
        <div class="workflow-analytics__bars" style="--workflow-analytics-days: {{ $summary['days'] }}" role="img" aria-label="Количество выполнений за {{ $summary['days'] }} дней: {{ $summary['total'] }}. Ошибок: {{ $summary['failed'] }}.">
            @foreach($summary['series'] as $day)
                <div class="workflow-analytics__day" title="{{ $day['date'] }}: {{ $day['total'] }} выполнений, {{ $day['failed'] }} ошибок">
                    <div class="workflow-analytics__bar-track"><div class="workflow-analytics__bar" style="height: {{ 100 * $day['total'] / $summary['peak'] }}%"><i style="height: {{ $day['total'] ? 100 * $day['failed'] / $day['total'] : 0 }}%"></i></div></div>
                    @if($loop->first || $loop->last || $loop->index % max(1, (int)ceil($summary['days'] / 10)) === 0)<small>{{ $day['date'] }}</small>@endif
                </div>
            @endforeach
        </div>
        <p>Ошибки отмечены красным. Доля успеха рассчитана по завершённым запускам.</p>
    </section>
    <section class="workflow-analytics__ranking"><h2>Сценарии</h2>
        @forelse($summary['top'] as $workflow)
            <a href="{{ \App\Filament\WorkflowBuilder\Resources\WorkflowRunResource::getUrl('index', ['workflow_id' => $workflow->workflow_id]) }}"><span>{{ $workflow->name ?: 'Удалённый сценарий' }}</span><strong>{{ $workflow->total }} запусков</strong><small>{{ $workflow->failed }} ошибок</small></a>
        @empty
            <p>За этот период выполнений ещё нет.</p>
        @endforelse
    </section>
    </div>
</x-filament-panels::page>
