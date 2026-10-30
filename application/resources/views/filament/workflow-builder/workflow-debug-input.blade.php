<div class="workflow-debug-input">
    <div class="workflow-debug-input__source">
        <label>Данные для запуска
            <select :value="$wire.debugInputMode" x-on:change="$wire.$set('debugInputMode',$event.target.value,false); if ($event.target.value === 'history') loadDebugHistory()" aria-label="Источник данных для запуска">
                <option value="json">JSON вручную</option>
                <option value="history">Прошлый запуск</option>
            </select>
        </label>
        <label>Точка запуска в схеме
            <select :value="$wire.debugStartNodeId" x-on:change="$wire.$set('debugStartNodeId',$event.target.value,false)" aria-label="Запуск для отладки">
                <template x-for="node in debugNodes().filter(node => node.id.startsWith('trigger'))" :key="node.id"><option :value="node.id" :selected="node.id === $wire.debugStartNodeId" x-text="node.name"></option></template>
            </select>
        </label>
    </div>
    <div x-show="$wire.debugInputMode === 'history'">
        <label>Запуск с нужными входными данными
            <select wire:model="debugRunId" aria-label="Прошлый запуск для проверки">
                <option value="">Выберите запуск…</option>
                <template x-for="run in (historyOptions || [])" :key="run.id"><option :value="run.id" x-text="run.label"></option></template>
            </select>
        </label>
        <p class="workflow-debug-input__hint" x-text="(historyOptions || []).length ? 'Берём только входные данные. Выполнение пойдёт по текущей схеме.' : 'У этого сценария пока нет запусков. Используйте JSON вручную.'"></p>
        <button type="button" wire:click="previewDebugInput">Посмотреть входные данные</button>
        <pre class="workflow-debug-input__preview">{{ $this->debugInput }}</pre>
    </div>
    <div x-show="$wire.debugInputMode !== 'history'">
        <label>Входные данные запуска (JSON)<textarea wire:model="debugInput" spellcheck="false" rows="5" aria-label="Входные данные отладки"></textarea></label>
        <p class="workflow-debug-input__hint">Если входные данные не нужны, оставьте {}.</p>
    </div>
    @if($this->debugInputSource)<p class="workflow-debug-input__hint" role="status">{{ $this->debugInputSource }}</p>@endif
    @error('debugInput')<p class="workflow-debug-input__error" role="alert">{{ $message }}</p>@enderror
</div>
