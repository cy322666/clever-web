<div class="workflow-debug-input">
    <div class="workflow-debug-input__source">
        <label>Данные для проверки
            <select wire:model.live="debugInputMode" aria-label="Источник данных для проверки">
                <option value="builder">Конструктор события</option>
                <option value="history">Прошлый запуск</option>
                <option value="json">JSON вручную</option>
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

    @if($this->debugInputMode === 'builder')
        <div class="workflow-debug-input__source">
            <label>Сущность
                <select wire:model.live="debugEntity" aria-label="Сущность для проверки">
                    @foreach(\App\Services\Workflows\WorkflowDebugInput::entities() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <label>Событие
                <select wire:model="debugEvent" aria-label="Событие для проверки">
                    @foreach(\App\Services\Workflows\WorkflowDebugInput::events($this->debugEntity) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </label>
        </div>
        <p class="workflow-debug-input__hint">Задайте пример события. Это не меняет событие запуска сценария и данные в CRM.</p>
        @if($this->debugEntity !== 'payload')
            <div class="workflow-debug-input__load">
                <label>ID сущности <input wire:model="debugEntityId" inputmode="numeric" placeholder="Необязательно для примера" aria-label="ID сущности для проверки" /></label>
                @if(in_array($this->debugEntity, ['lead', 'contact', 'company', 'customer', 'task'], true))
                    <button type="button" wire:click="loadDebugEntity" wire:loading.attr="disabled" wire:target="loadDebugEntity">
                        <span wire:loading.remove wire:target="loadDebugEntity">Загрузить из amoCRM</span><span wire:loading wire:target="loadDebugEntity">Загружаем…</span>
                    </button>
                @endif
                @if($this->debugLoadedEntity)<button type="button" wire:click="resetLoadedDebugEntity">Использовать только поля ниже</button>@endif
            </div>
        @endif
        @foreach($this->debugFields as $index => $field)
            <div class="workflow-debug-input__field" wire:key="debug-input-field-{{ $this->debugEntity }}-{{ $index }}">
                <label>Поле
                    <select wire:model.live="debugFields.{{ $index }}.key" aria-label="Поле примера {{ $index + 1 }}">
                        @foreach(\App\Services\Workflows\WorkflowDebugInput::fields($this->debugEntity) as $value => $label)
                            @if($value !== 'id' || $this->debugEntity === 'payload')<option value="{{ $value }}">{{ $label }}</option>@endif
                        @endforeach
                    </select>
                </label>
                @if(($field['key'] ?? '') === '__custom')<label>Путь поля<input wire:model="debugFields.{{ $index }}.path" placeholder="custom_fields_values.0.field_id" aria-label="Путь поля {{ $index + 1 }}" /></label>@endif
                <label>Тип
                    <select wire:model.live="debugFields.{{ $index }}.type" aria-label="Тип значения {{ $index + 1 }}">
                        <option value="text">Текст</option><option value="number">Число</option><option value="boolean">Да / нет</option><option value="null">Не задано</option>
                    </select>
                </label>
                <label>Значение
                    @if(($field['type'] ?? 'text') === 'boolean')
                        <select wire:model="debugFields.{{ $index }}.value" aria-label="Значение поля {{ $index + 1 }}"><option value="">Выберите…</option><option value="true">Да</option><option value="false">Нет</option></select>
                    @else
                        <input wire:model="debugFields.{{ $index }}.value" @disabled(($field['type'] ?? '') === 'null') aria-label="Значение поля {{ $index + 1 }}" />
                    @endif
                </label>
                <button type="button" wire:click="removeDebugInputField({{ $index }})" aria-label="Удалить поле {{ $index + 1 }}" title="Удалить поле"><x-filament::icon icon="heroicon-o-x-mark" class="h-4 w-4"/></button>
            </div>
        @endforeach
        <button type="button" wire:click="addDebugInputField" @disabled(count($this->debugFields) >= 49)>Добавить поле</button>
    @elseif($this->debugInputMode === 'history')
        @php($debugRuns = $this->recentDebugRuns())
        <label>Запуск с нужными входными данными
            <select wire:model="debugRunId" aria-label="Прошлый запуск для проверки">
                <option value="">Выберите запуск…</option>
                @foreach($debugRuns as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
            </select>
        </label>
        <p class="workflow-debug-input__hint">{{ $debugRuns ? 'Берём только входные данные. Проверка выполняется по текущей схеме.' : 'У этого сценария пока нет запусков. Используйте конструктор события.' }}</p>
    @else
        <label>Входные данные запуска (JSON)<textarea wire:model="debugInput" spellcheck="false" rows="5" aria-label="Входные данные отладки"></textarea></label>
    @endif
    @if($this->debugInputSource)<p class="workflow-debug-input__hint" role="status">{{ $this->debugInputSource }}</p>@endif
    @error('debugInputBuilder')<p class="workflow-debug-input__error" role="alert">{{ $message }}</p>@enderror
    @if($this->debugInputMode !== 'json')
        <details class="workflow-debug-input__preview">
            <summary>Посмотреть подготовленные данные</summary>
            <button type="button" wire:click="previewDebugInput">Собрать пример</button>
            <pre>{{ $this->debugInput }}</pre>
        </details>
    @endif
</div>
