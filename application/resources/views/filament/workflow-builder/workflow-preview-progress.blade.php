<div class="workflow-preview-progress" role="status" aria-live="polite">
    <strong>{{ ($progress['stopping'] ?? false) ? 'Останавливаем после текущего действия…' : (($progress['status'] ?? '') === 'queued' ? 'Ожидание обработчика…' : 'Выполняется цикл') }}</strong>
    <span>{{ $progress['current_node'] ?? $progress['name'] ?? 'Цикл' }}</span>
    @if($progress['sequence'] ?? 0)<small>Шаг {{ $progress['sequence'] }} из максимум 200</small>@endif
    <small>Пробный запуск: до 60 секунд. Уже начатый запрос может завершиться позже. Выполненные действия не отменяются.</small>
    <button type="button" wire:click="stopWorkflowNodePreview" wire:loading.attr="disabled" wire:target="stopWorkflowNodePreview" @disabled($progress['stopping'] ?? false) class="workflow-preview-progress__stop">Остановить</button>
</div>
