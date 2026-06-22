<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>{{ $document['title'] }}</title>
    <style>
        @page { margin: 22px 26px; }
        * { box-sizing: border-box; }
        body {
            color: #111827;
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            line-height: 1.32;
        }
        h1, h2, h3, p { margin: 0; }
        h1 { font-size: 19px; }
        h2 { font-size: 12px; line-height: 1.25; }
        .cover {
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 10px;
            padding-bottom: 8px;
        }
        .meta {
            color: #6b7280;
            margin-top: 4px;
        }
        .workflow {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 8px;
            padding: 8px 9px;
            page-break-inside: avoid;
        }
        .workflow__head {
            display: table;
            width: 100%;
        }
        .workflow__title,
        .workflow__meta {
            display: table-cell;
            vertical-align: top;
        }
        .workflow__meta {
            color: #6b7280;
            text-align: right;
            white-space: nowrap;
            width: 35%;
        }
        .badge {
            border-radius: 999px;
            display: inline-block;
            font-size: 8px;
            font-weight: 700;
            padding: 2px 6px;
        }
        .badge--on { background: #dcfce7; color: #166534; }
        .badge--off { background: #fee2e2; color: #991b1b; }
        .line {
            margin-top: 5px;
        }
        .label {
            color: #6b7280;
            font-weight: 700;
            text-transform: uppercase;
        }
        table {
            border-collapse: collapse;
            margin-top: 6px;
            width: 100%;
        }
        th {
            background: #f9fafb;
            color: #6b7280;
            font-size: 8px;
            letter-spacing: .03em;
            text-align: left;
            text-transform: uppercase;
        }
        th, td {
            border: 1px solid #e5e7eb;
            padding: 4px 5px;
            vertical-align: top;
        }
        .num {
            color: #ea580c;
            font-weight: 700;
            white-space: nowrap;
            width: 26px;
        }
        .step-name {
            font-weight: 700;
        }
        .muted {
            color: #6b7280;
        }
        .settings {
            margin: 2px 0 0;
            padding: 0;
        }
        .settings li {
            list-style-position: inside;
            margin: 0 0 2px;
        }
        .condition {
            background: #fffbeb;
            border-left: 2px solid #f59e0b;
            margin-bottom: 3px;
            padding: 3px 5px;
        }
        .warning {
            color: #b91c1c;
            margin-top: 5px;
        }
    </style>
</head>
<body>
<section class="cover">
    <h1>Сценарии автоматизации</h1>
    <div class="meta">
        {{ $document['generated_at']->format('d.m.Y H:i') }}
        · сценариев: {{ $document['workflows_count'] }}
    </div>
</section>

@foreach($document['workflows'] as $workflow)
    <section class="workflow">
        <div class="workflow__head">
            <div class="workflow__title">
                <h2>{{ $workflow['name'] }}</h2>
                @if($workflow['description'] !== '')
                    <div class="muted">{{ $workflow['description'] }}</div>
                @endif
            </div>
            <div class="workflow__meta">
                <span class="badge {{ $workflow['active'] ? 'badge--on' : 'badge--off' }}">
                    {{ $workflow['active'] ? 'включён' : 'выключен' }}
                </span>
                · ID {{ $workflow['id'] }}
                · {{ $workflow['group'] }}
            </div>
        </div>

        <div class="line">
            <span class="label">Триггер:</span>
            {{ $workflow['trigger']['name'] }}
            @if($workflow['trigger']['description'])
                <span class="muted">· {{ $workflow['trigger']['description'] }}</span>
            @endif
        </div>

        @if($workflow['warnings'] !== [])
            <div class="warning">
                {{ implode(' · ', $workflow['warnings']) }}
            </div>
        @endif

        <table>
            <thead>
            <tr>
                <th style="width: 8%;">№</th>
                <th style="width: 30%;">Шаг</th>
                <th>Ключевые настройки</th>
            </tr>
            </thead>
            <tbody>
            @forelse($workflow['steps'] as $step)
                <tr>
                    <td class="num">{{ $step['number'] }}</td>
                    <td>
                        @if($step['branch'])
                            <div class="muted">{{ $step['branch'] }}</div>
                        @endif
                        <div class="step-name">{{ $step['name'] }}</div>
                        @if($step['child_workflow'])
                            <div class="muted">
                                Запускает: {{ $step['child_workflow']['name'] }} · ID {{ $step['child_workflow']['id'] }}
                            </div>
                        @endif
                    </td>
                    <td>
                        @if($step['conditions'] !== [])
                            @foreach($step['conditions'] as $conditionIndex => $condition)
                                <div class="condition">
                                    @if($conditionIndex > 0)
                                        {{ $condition['logic'] }}
                                    @endif
                                    {{ $condition['left'] }}
                                    {{ $condition['operator'] }}
                                    {{ $condition['right'] }}
                                </div>
                            @endforeach
                        @endif

                        @if($step['settings'] !== [])
                            <ul class="settings">
                                @foreach(array_slice($step['settings'], 0, 6) as $setting)
                                    <li><strong>{{ $setting['label'] }}:</strong> {{ $setting['value'] }}</li>
                                @endforeach
                            </ul>
                        @elseif($step['conditions'] === [])
                            <span class="muted">-</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="3">Действия не настроены.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </section>
@endforeach
</body>
</html>
