<section class="{{ $nested ? 'child' : '' }}">
    <h2>{{ $workflow['name'] }}</h2>

    <div class="summary">
        <strong>Статус:</strong>
        <span class="badge {{ $workflow['active'] ? 'badge--on' : 'badge--off' }}">
            {{ $workflow['active'] ? 'Включён' : 'Выключен' }}
        </span>
        &nbsp;&nbsp;
        <strong>ID:</strong> {{ $workflow['id'] }}
        &nbsp;&nbsp;
        <strong>Группа:</strong> {{ $workflow['group'] }}
        &nbsp;&nbsp;
        <strong>Шагов:</strong> {{ $workflow['steps_count'] }}

        @if($workflow['description'] !== '')
            <div class="muted" style="margin-top: 8px;">{{ $workflow['description'] }}</div>
        @endif
    </div>

    <h3>Триггер</h3>
    <table>
        <thead>
        <tr>
            <th style="width: 28%;">Тип</th>
            <th>Описание</th>
            <th style="width: 34%;">Настройки</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td><strong>{{ $workflow['trigger']['name'] }}</strong></td>
            <td>{{ $workflow['trigger']['description'] ?: '—' }}</td>
            <td>
                @if($workflow['trigger']['settings'] !== [])
                    <ul class="settings">
                        @foreach($workflow['trigger']['settings'] as $setting)
                            <li><strong>{{ $setting['label'] }}:</strong> {{ $setting['value'] }}</li>
                        @endforeach
                    </ul>
                @else
                    —
                @endif
            </td>
        </tr>
        </tbody>
    </table>

    @if($workflow['warnings'] !== [])
        <div class="warning">
            <strong>Что проверить:</strong>
            @foreach($workflow['warnings'] as $warning)
                <div>{{ $warning }}</div>
            @endforeach
        </div>
    @endif

    <h3>Шаги сценария</h3>
    <table>
        <thead>
        <tr>
            <th style="width: 9%;">№</th>
            <th style="width: 22%;">Шаг</th>
            <th style="width: 13%;">Тип</th>
            <th>Настройки</th>
        </tr>
        </thead>
        <tbody>
        @forelse($workflow['steps'] as $step)
            <tr>
                <td class="step-number">{{ $step['number'] }}</td>
                <td>
                    @if($step['branch'])
                        <div class="branch">{{ $step['branch'] }}</div>
                    @endif
                    <div class="step-title">{{ $step['name'] }}</div>
                    @if($step['description'])
                        <div class="muted">{{ $step['description'] }}</div>
                    @endif
                    @if($step['child_workflow'])
                        <div class="muted">
                            Дочерний сценарий:
                            <strong>{{ $step['child_workflow']['name'] }}</strong>
                            · ID {{ $step['child_workflow']['id'] }}
                        </div>
                    @endif
                </td>
                <td>{{ $step['type_label'] }}</td>
                <td>
                    @if($step['conditions'] !== [])
                        @foreach($step['conditions'] as $conditionIndex => $condition)
                            <div class="condition">
                                @if($conditionIndex > 0)
                                    <strong>{{ $condition['logic'] }}</strong>
                                @endif
                                {{ $condition['left'] }}
                                <strong>{{ $condition['operator'] }}</strong>
                                {{ $condition['right'] }}
                            </div>
                        @endforeach
                    @endif

                    @if($step['settings'] !== [])
                        <ul class="settings">
                            @foreach($step['settings'] as $setting)
                                <li><strong>{{ $setting['label'] }}:</strong> {{ $setting['value'] }}</li>
                            @endforeach
                        </ul>
                    @elseif($step['conditions'] === [])
                        —
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="4">Действия не настроены.</td>
            </tr>
        @endforelse
        </tbody>
    </table>

    <h3>Переменные</h3>
    @if($workflow['variables'] !== [])
        <div class="variables">
            @foreach($workflow['variables'] as $variable)
                <span class="variable">{{ $variable }}</span>
            @endforeach
        </div>
    @else
        <div class="muted">В настройках сценария переменные не найдены.</div>
    @endif

    @if($workflow['children'] !== [])
        <h3>Дочерние сценарии</h3>
        @foreach($workflow['children'] as $childWorkflow)
            @include('filament.workflow-builder.documentation.partials.workflow', [
                'workflow' => $childWorkflow,
                'nested' => true,
            ])
        @endforeach
    @endif
</section>
