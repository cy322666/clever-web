<div class="workflow-debug-input">
    <div class="workflow-debug-input__source">
        <label>Данные для запуска
            <select wire:model.live="debugInputMode" aria-label="Источник данных для запуска">
                <option value="json">JSON вручную</option>
                <option value="history">Прошлый запуск</option>
            </select>
        </label>
        <label>Точка запуска в схеме
            <select wire:model.live="debugStartNodeId" aria-label="Запуск для отладки">
                @foreach(\App\Services\Workflows\WorkflowStartNodes::all(array_merge($this->definition, ['trigger' => $this->trigger])) as $startId => $start)
                    <option value="{{ $startId }}">{{ $start['name'] ?? $this->getTriggerName($start['type']) }}{{ $startId !== 'trigger' ? ' · '.($loop->index + 1) : '' }}</option>
                @endforeach
            </select>
        </label>
    </div>
    @if($this->debugInputMode === 'history')
        @php($debugRuns = $this->recentDebugRuns())
        <label>Запуск с нужными входными данными
            <select wire:model="debugRunId" aria-label="Прошлый запуск для проверки">
                <option value="">Выберите запуск…</option>
                @foreach($debugRuns as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <p class="workflow-debug-input__hint">{{ $debugRuns ? 'Берём только входные данные. Выполнение пойдёт по текущей схеме.' : 'У этого сценария пока нет запусков. Используйте JSON вручную.' }}</p>
        <button type="button" wire:click="previewDebugInput">Посмотреть входные данные</button>
        <pre class="workflow-debug-input__preview">{{ $this->debugInput }}</pre>
    @else
        <label>Входные данные запуска (JSON)<textarea wire:model="debugInput" spellcheck="false" rows="5" aria-label="Входные данные отладки"></textarea></label>
        <p class="workflow-debug-input__hint">Если входные данные не нужны, оставьте {}.</p>
    @endif
    @if($this->debugInputSource)<p class="workflow-debug-input__hint" role="status">{{ $this->debugInputSource }}</p>@endif
    @error('debugInput')<p class="workflow-debug-input__error" role="alert">{{ $message }}</p>@enderror
</div>
