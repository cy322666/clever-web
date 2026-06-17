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

    <div
        class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
        <span><strong class="text-gray-900 dark:text-gray-100">⚡</strong> триггер</span>
        <span><strong class="text-gray-900 dark:text-gray-100">▣</strong> текущий процесс</span>
        <span><strong class="text-gray-900 dark:text-gray-100">◇</strong> условие</span>
        <span><strong class="text-gray-900 dark:text-gray-100">↳</strong> дочерний процесс</span>
        <span><strong class="text-gray-900 dark:text-gray-100">+</strong> создание</span>
        <span><strong class="text-gray-900 dark:text-gray-100">✎</strong> изменение</span>
        <span><strong class="text-gray-900 dark:text-gray-100">✉</strong> уведомление</span>
    </div>
</div>
