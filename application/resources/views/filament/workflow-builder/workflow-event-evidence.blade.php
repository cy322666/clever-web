<div>
    <p>Сценарий #{{ $event->workflow_id }} · {{ $event->event }} · ID {{ $event->entity_id }}</p>
    <p>{{ $event->reason }}</p>
    <details><summary>Данные события</summary>
        @include('filament.workflow-builder.workflow-json-tree', ['value'=>\App\Services\Workflows\Testing\WorkflowReportSanitizer::sanitize($event->envelope['event'] ?? [])])
    </details>
</div>
