<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>{{ $document['title'] }}</title>
    <style>
        @page {
            margin: 30px 34px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            color: #111827;
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            line-height: 1.45;
        }

        h1, h2, h3 {
            margin: 0;
            line-height: 1.2;
        }

        h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        h2 {
            border-bottom: 1px solid #e5e7eb;
            font-size: 19px;
            margin-top: 28px;
            padding-bottom: 8px;
        }

        h3 {
            font-size: 14px;
            margin-bottom: 8px;
            margin-top: 16px;
        }

        .muted {
            color: #6b7280;
        }

        .cover {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 12px;
            margin-bottom: 24px;
            padding: 22px;
        }

        .meta {
            display: table;
            margin-top: 18px;
            width: 100%;
        }

        .meta__cell {
            display: table-cell;
            padding-right: 16px;
            width: 33.33%;
        }

        .meta__label {
            color: #6b7280;
            font-size: 9px;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .meta__value {
            font-size: 13px;
            font-weight: 700;
            margin-top: 3px;
        }

        .badge {
            border-radius: 999px;
            display: inline-block;
            font-size: 9px;
            font-weight: 700;
            padding: 3px 8px;
        }

        .badge--on {
            background: #dcfce7;
            color: #166534;
        }

        .badge--off {
            background: #fee2e2;
            color: #991b1b;
        }

        .summary {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            margin-top: 12px;
            padding: 12px 14px;
        }

        table {
            border-collapse: collapse;
            margin-top: 10px;
            width: 100%;
        }

        th {
            background: #f9fafb;
            color: #4b5563;
            font-size: 9px;
            letter-spacing: .04em;
            text-align: left;
            text-transform: uppercase;
        }

        th, td {
            border: 1px solid #e5e7eb;
            padding: 7px 8px;
            vertical-align: top;
        }

        .step-number {
            color: #ea580c;
            font-weight: 700;
            white-space: nowrap;
        }

        .step-title {
            font-weight: 700;
        }

        .branch {
            color: #9a3412;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .settings {
            margin: 4px 0 0;
            padding: 0;
        }

        .settings li {
            margin: 0 0 4px 0;
        }

        .condition {
            background: #fffbeb;
            border-left: 3px solid #f59e0b;
            margin: 4px 0;
            padding: 5px 7px;
        }

        .variables {
            margin-top: 8px;
        }

        .variable {
            background: #f3f4f6;
            border-radius: 5px;
            display: inline-block;
            font-family: DejaVu Sans Mono, monospace;
            margin: 0 4px 5px 0;
            padding: 3px 6px;
        }

        .warning {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            color: #991b1b;
            margin-top: 10px;
            padding: 8px 10px;
        }

        .child {
            border-left: 3px solid #d1d5db;
            margin-left: 10px;
            padding-left: 12px;
        }

        .page-break {
            page-break-before: always;
        }
    </style>
</head>
<body>
<section class="cover">
    <h1>{{ $document['title'] }}</h1>
    <div class="muted">{{ $document['scope'] }}</div>

    <div class="meta">
        <div class="meta__cell">
            <div class="meta__label">Дата формирования</div>
            <div class="meta__value">{{ $document['generated_at']->format('d.m.Y H:i') }}</div>
        </div>
        <div class="meta__cell">
            <div class="meta__label">Сценариев</div>
            <div class="meta__value">{{ $document['workflows_count'] }}</div>
        </div>
        <div class="meta__cell">
            <div class="meta__label">Назначение</div>
            <div class="meta__value">Паспорт автоматизации</div>
        </div>
    </div>
</section>

@foreach($document['workflows'] as $workflowIndex => $workflow)
    @if($workflowIndex > 0)
        <div class="page-break"></div>
    @endif

    @include('filament.workflow-builder.documentation.partials.workflow', [
        'workflow' => $workflow,
        'nested' => false,
    ])
@endforeach
</body>
</html>
