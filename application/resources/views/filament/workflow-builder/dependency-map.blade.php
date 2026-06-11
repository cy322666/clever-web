@php
    $diagram = $workflow
        ? app(\App\Services\Workflows\WorkflowMermaidMap::class)->render($workflow, $map)
        : 'flowchart LR';
@endphp

<div class="workflow-mermaid-map space-y-4" data-workflow-mermaid-map>
    <textarea class="hidden" data-workflow-mermaid-source readonly>{{ $diagram }}</textarea>

    <div class="workflow-mermaid-map__canvas" data-workflow-mermaid-target>
        <div class="workflow-mermaid-map__loading">Строим карту...</div>
    </div>

    <div class="grid gap-3 text-sm sm:grid-cols-4">
        <div
            class="rounded-xl border border-orange-200 bg-orange-50 px-3 py-2 text-orange-800 dark:border-orange-900 dark:bg-orange-950/30 dark:text-orange-200">
            <div class="font-semibold">Откуда стартует</div>
            <div class="mt-0.5 text-xs opacity-80">Триггер или родительский процесс.</div>
        </div>

        <div
            class="rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-blue-800 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-200">
            <div class="font-semibold">Текущий процесс</div>
            <div class="mt-0.5 text-xs opacity-80">Процесс, по которому открыта карта.</div>
        </div>

        <div
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200">
            <div class="font-semibold">Действия</div>
            <div class="mt-0.5 text-xs opacity-80">Все шаги процесса и ветки условий.</div>
        </div>

        <div
            class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-slate-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
            <div class="font-semibold">Куда дальше</div>
            <div class="mt-0.5 text-xs opacity-80">Дочерние процессы раскрываются вместе с шагами.</div>
        </div>
    </div>
</div>
