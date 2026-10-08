@php($runFailure = \App\Services\Workflows\WorkflowRunFailure::message($run))
@if($runFailure !== null)
    <section class="workflow-run-failure" role="alert" aria-label="Причина ошибки запуска">
        <strong>Запуск #{{ $run->getKey() }} завершился с ошибкой</strong>
        <p>{{ $runFailure }}</p>
        @if($run->steps->isNotEmpty())
            <small>Выполненные действия не отменяются. Перед повторным запуском проверьте результаты шагов.</small>
        @endif
    </section>
@endif
