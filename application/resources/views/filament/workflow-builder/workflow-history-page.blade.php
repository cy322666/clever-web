@php
    $workflow = $this->getHistoryWorkflow();
    $historyTitle = $this->getHistoryTitle();
    $historyBackUrl = $this->getHistoryBackUrl();
    $runs = $this->getWorkflowRuns();
    $selectedRun = $this->getSelectedRun($runs);
    $statusColors = [
        'completed' => 'success',
        'failed' => 'danger',
        'running' => 'info',
        'pending' => 'warning',
        'paused' => 'warning',
        'cancelled' => 'gray',
    ];
@endphp

<x-filament-panels::page :class="$workflow ? '' : 'workflow-list-page workflow-account-history-page'">
    <div class="workflow-history-page" wire:poll.5s.visible>
        @if($workflow)
            @include('filament.workflow-builder.workflow-admin-context')
        @endif

        @unless($workflow)
            @include('filament.workflow-builder.workflow-overview-nav', ['active' => 'history'])
        @endunless
        @if($workflow)
        <header class="workflow-history-page__header">
            <div class="workflow-history-page__identity">
                <a href="{{ \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('index') }}" class="workflow-workbench__quick-action" aria-label="Сценарии" title="Сценарии"><x-filament::icon icon="heroicon-o-squares-2x2" class="h-5 w-5"/></a>
                <div>
                    <h1>{{ $historyTitle }}</h1>
                </div>
            </div>

        </header>
        @endif

        <div class="workflow-history-page__layout" x-data="{ query: '' }">
            <aside class="workflow-history-sidebar">
                <div class="workflow-history-sidebar__header">
                    <label class="workflow-history-sidebar__search">
                        <x-filament::icon icon="heroicon-o-magnifying-glass" class="h-4 w-4"/>
                        <input
                            x-model.debounce.100ms="query"
                            type="search"
                            placeholder="Дата, статус или сущность"
                            aria-label="Поиск по дате, статусу или сущности"
                            autocomplete="off"
                        />
                    </label>
                    <div class="workflow-history-sidebar__filters" role="group" aria-label="Фильтр запусков">
                        <button type="button" wire:click="$set('historyErrorsOnly', false)" aria-pressed="{{ $this->historyErrorsOnly ? 'false' : 'true' }}" wire:loading.attr="disabled" wire:target="historyErrorsOnly">Все</button>
                        <button type="button" wire:click="$set('historyErrorsOnly', true)" aria-pressed="{{ $this->historyErrorsOnly ? 'true' : 'false' }}" wire:loading.attr="disabled" wire:target="historyErrorsOnly">С ошибками</button>
                    </div>
                </div>

                <nav class="workflow-history-sidebar__runs" aria-label="Запуски процесса">
                    @forelse($runs as $run)
                        @php
                            $statusValue = $run->status?->value ?? (string) $run->status;
                            $statusLabel = $run->status?->getLabel() ?? $statusValue;
                            $startedAt = $run->started_at ?? $run->created_at;
                            $date = $startedAt?->timezone('Europe/Moscow')->format('d.m.Y H:i:s') ?? 'Ожидает запуска';
                            $isSelected = $selectedRun && (int) $selectedRun->getKey() === (int) $run->getKey();
                            $runWorkflowName = (string) ($run->workflow?->name ?: 'Процесс удалён');
                            $entitySummary = \App\Services\Workflows\WorkflowExecutionExplanation::entity($run->context_data ?? []);
                            $searchText = mb_strtolower(implode(' ', [$date, $statusLabel, $statusValue, $run->ulid, $runWorkflowName, $entitySummary]));
                        @endphp
                        <a
                            x-show="query === '' || @js($searchText).includes(query.toLocaleLowerCase('ru-RU').trim())"
                            href="{{ $this->getHistoryRunUrl($run) }}"
                            @class([
                                'workflow-history-run',
                                'is-active' => $isSelected,
                            ])
                        >
                            <span class="workflow-history-run__status workflow-history-run__status--{{ $statusColors[$statusValue] ?? 'gray' }}"></span>
                            <span class="workflow-history-run__body">
                                <strong>{{ $date }}</strong>
                                <small>
                                    @unless($workflow)
                                        {{ $runWorkflowName }} ·
                                    @endunless
                                    {{ $statusLabel }} · {{ $run->steps_count }} шаг(ов)
                                </small>
                                @if(!$workflow && (bool) auth()->user()?->is_root)
                                    <small>{{ $run->owner?->accounts?->first()?->subdomain ?: $run->owner?->email }}</small>
                                @endif
                                @if($entitySummary)<small>{{ $entitySummary }}</small>@endif
                            </span>
                            <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4"/>
                        </a>
                    @empty
                        <div class="workflow-history-sidebar__empty">
                            <x-filament::icon icon="heroicon-o-play-circle" class="h-6 w-6"/>
                            <strong>{{ $this->historyErrorsOnly ? 'Запусков с ошибками нет' : 'Запусков пока нет' }}</strong>
                            <span>{{ $this->historyErrorsOnly ? 'Выберите «Все», чтобы увидеть остальные запуски.' : 'Они появятся после выполнения сценария.' }}</span>
                        </div>
                    @endforelse
                </nav>
            </aside>

            <main class="workflow-history-detail">
                @if($selectedRun)
                    <div class="workflow-history-detail__topbar">
                        <div>
                            <strong>{{ $selectedRun->status?->getLabel() }}</strong>
                            <span>{{ ($selectedRun->started_at ?? $selectedRun->created_at)?->timezone('Europe/Moscow')->format('d.m.Y H:i:s') }} · {{ $selectedRun->steps_count }} шаг(ов)</span>
                        </div>
                        <div>
                            @if(is_array(data_get($selectedRun->context_data, 'variables._definition_snapshot.actions')))
                                <a class="workflow-history-debug-action" href="{{ \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('replay', ['run' => $selectedRun->getKey()]) }}" style="display:inline-flex;align-items:center;gap:6px;font-size:14px;font-weight:400;white-space:nowrap"><x-filament::icon icon="heroicon-o-beaker" style="width:18px;height:18px;flex:none"/>Отладка</a>
                            @else
                                <span title="Для этого старого запуска схема не была сохранена">Без снимка схемы</span>
                            @endif
                        </div>
                    </div>

                    <div class="workflow-history-detail__content">
                        @include('filament.workflow-builder.workflow-execution-canvas', ['graph' => \App\Services\Workflows\WorkflowExecutionGraph::fromRun($selectedRun), 'runId' => $selectedRun->getKey()])
                    </div>
                @else
                    <div class="workflow-history-detail__empty">
                        <x-filament::icon icon="heroicon-o-clock" class="h-8 w-8"/>
                        <strong>{{ $this->historyErrorsOnly ? 'Запусков с ошибками нет' : 'История пуста' }}</strong>
                        <span>{{ $this->historyErrorsOnly ? 'Выберите «Все», чтобы увидеть остальные запуски.' : 'После первого запуска здесь будет полный путь выполнения.' }}</span>
                    </div>
                @endif
            </main>
        </div>
    </div>
</x-filament-panels::page>
