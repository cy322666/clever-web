@php
    $selectedRun = $this->getSelectedRun();
    $startedAt = $selectedRun?->started_at ?? $selectedRun?->created_at;
@endphp

<x-filament-panels::page class="workflow-list-page workflow-account-history-page">
    <div class="workflow-history-page">
        @include('filament.workflow-builder.workflow-overview-nav', ['active' => 'history', 'folder' => $this->workflowGroupFilter, 'launch' => $this->workflowLaunchFilter])

        @if($selectedRun)
            <header class="workflow-history-page__header workflow-account-history-detail__header">
                <div class="workflow-history-page__identity">
                    <a href="{{ $this->getHistoryIndexUrl() }}" class="workflow-history-page__back" aria-label="Вернуться к истории запусков" title="Вернуться к истории запусков">
                        <x-filament::icon icon="heroicon-o-arrow-left" class="h-5 w-5"/>
                    </a>
                    <div>
                        <span class="workflow-account-history-detail__eyebrow">Запуск #{{ $selectedRun->getKey() }}</span>
                        <h1>{{ $selectedRun->workflow?->name ?: 'Сценарий удалён' }}</h1>
                    </div>
                </div>
                <div class="workflow-account-history-detail__actions">
                    @if($selectedRun->status?->isTerminal() && $selectedRun->workflow)
                        <button type="button" class="workflow-account-history-detail__action" wire:click="restartSelectedRun" wire:confirm="Повторный запуск может повторить изменения в CRM. Запустить ещё раз?">
                            <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4"/>
                            Повторить
                        </button>
                    @endif
                    @if(is_array(data_get($selectedRun->context_data, 'variables._definition_snapshot.actions')))
                        <a class="workflow-account-history-detail__action" href="{{ \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('replay', ['run' => $selectedRun->getKey()]) }}">
                            <x-filament::icon icon="heroicon-o-beaker" class="h-4 w-4"/>
                            Отладка
                        </a>
                    @endif
                </div>
            </header>

            <main class="workflow-history-detail" wire:poll.5s.visible>
                <div class="workflow-history-detail__topbar">
                    <div class="workflow-account-history-detail__summary">
                        <strong>{{ $selectedRun->status?->getLabel() ?: 'Неизвестен' }}</strong>
                        <span>{{ $startedAt?->timezone('Europe/Moscow')->format('d.m.Y H:i:s') ?: 'Ожидает запуска' }}</span>
                        <span>{{ \App\Filament\WorkflowBuilder\Resources\WorkflowRunResource::runDuration($selectedRun) }}</span>
                        <span>{{ $selectedRun->steps_count }} шаг(ов)</span>
                    </div>
                </div>

                @include('filament.workflow-builder.workflow-run-failure', ['run' => $selectedRun])

                <div class="workflow-history-detail__content">
                    @include('filament.workflow-builder.workflow-execution-canvas', ['graph' => \App\Services\Workflows\WorkflowExecutionGraph::fromRun($selectedRun), 'runId' => $selectedRun->getKey()])
                </div>
            </main>
        @else
            <div @class(['workflow-overview workflow-account-history-overview', 'is-root' => (bool) auth()->user()?->is_root])>
                @unless((bool) auth()->user()?->is_root)
                    @include('filament.workflow-builder.workflow-sidebar')
                @endunless
                <main class="workflow-account-history-list" aria-label="Запуски всех сценариев клиента">
                    {{ $this->table }}
                </main>
            </div>
        @endif
    </div>
</x-filament-panels::page>
