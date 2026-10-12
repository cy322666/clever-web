@php($manualStarts = \App\Services\Workflows\WorkflowStartNodes::ofType(array_merge($this->definition, ['trigger' => $this->trigger]), 'manual'))
<div class="workflow-manual-run" x-data="{}">
    <span wire:poll.2s="['pending', 'running'].includes($wire.manualRunStatus) && $wire.pollManualWorkflowRun()" aria-hidden="true"></span>
    @if(count($manualStarts) > 1)
        <select x-ref="manualStart" aria-label="Начать с ноды" x-bind:disabled="workflowNodeIsRunning($wire)">
            @foreach($manualStarts as $id => $start)<option value="{{ $id }}">{{ $start['name'] ?? 'Ручной запуск '.($loop->index + 1) }}</option>@endforeach
        </select>
    @endif
    <button type="button" x-on:click="$wire.runWorkflowManually($refs.manualStart?.value || null)"
        x-bind:aria-busy="workflowManualIsRunning($wire) ? 'true' : 'false'"
        x-bind:disabled="workflowNodeIsRunning($wire) || @js($manualStarts === [])" @disabled($manualStarts === [])
        class="workflow-workbench__quick-action workflow-workbench__quick-action--labeled workflow-manual-run__button"
        title="{{ $manualStarts ? 'Сохранить и реально выполнить поток с ручного запуска. Расписание включать не нужно.' : 'Добавьте ноду «Ручной запуск» и соедините её с потоком.' }}">
        <span x-show="!workflowManualIsRunning($wire)" class="workflow-node-run__label"><x-filament::icon icon="heroicon-m-play" class="h-4 w-4"/> Запустить поток</span>
        <span x-show="workflowManualIsRunning($wire)" x-cloak class="workflow-node-run__label"><span class="workflow-run-spinner" aria-hidden="true"></span> Выполняется…</span>
    </button>
    <a x-show="$wire.manualRunId" x-cloak x-bind:href="$wire.manualRunUrl" class="workflow-manual-run__status" aria-live="polite"
        x-text="({pending: 'В очереди', running: 'Выполняется', completed: 'Готово', failed: 'Ошибка', paused: 'Приостановлен', cancelled: 'Отменён'}[$wire.manualRunStatus] || 'Открыть выполнение') + ' · #' + $wire.manualRunId" title="Открыть ход выполнения и результаты всех шагов"></a>
    <span x-show="$wire.manualRunError" x-cloak x-text="$wire.manualRunError" class="workflow-manual-run__error" role="alert"></span>
</div>
