<div class="workflow-preview-progress" role="status" aria-live="polite">
    <span class="workflow-run-spinner" aria-hidden="true"></span>
    <span>{{ ($progress['stopping'] ?? false) ? 'Останавливаем…' : (($progress['status'] ?? '') === 'queued' ? 'Ожидание обработчика…' : 'Выполняется…') }}</span>
    <button type="button" wire:click="stopWorkflowNodePreview" wire:loading.attr="disabled" wire:target="stopWorkflowNodePreview" @disabled($progress['stopping'] ?? false) class="workflow-preview-progress__stop" title="Остановить после текущего действия. Выполненные действия не отменяются.">Остановить</button>
</div>
