<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Каталог виджетов — {{ config('app.name') }}</title>
    <meta name="description" content="Каталог виджетов, которые разрабатываются в репозитории {{ config('app.name') }}. Быстрая загрузка, четкая структура и публичный доступ без авторизации." />
    <meta name="robots" content="index, follow" />
    <link rel="canonical" href="{{ url()->current() }}" />

    <meta property="og:title" content="Каталог виджетов — {{ config('app.name') }}" />
    <meta property="og:description" content="Публичный каталог виджетов с понятной структурой и описаниями." />
    <meta property="og:type" content="website" />
    <meta property="og:url" content="{{ url()->current() }}" />

    <style>
        :root {
            color-scheme: light;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #475569;
            --border: #e2e8f0;
            --accent: #4f46e5;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        a {
            color: inherit;
            text-decoration: none;
        }
        .container {
            max-width: 1120px;
            margin: 0 auto;
            padding: 48px 20px 64px;
        }
        header {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 32px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #eef2ff;
            color: #3730a3;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.02em;
            padding: 6px 10px;
            border-radius: 999px;
            width: fit-content;
        }
        h1 {
            font-size: clamp(28px, 4vw, 40px);
            margin: 0;
        }
        .lead {
            color: var(--muted);
            font-size: 16px;
            max-width: 720px;
        }
        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            color: var(--muted);
            font-size: 13px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 20px;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            min-height: 240px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
        }
        .card h2 {
            font-size: 18px;
            margin: 0;
        }
        .card p {
            margin: 0;
            color: var(--muted);
            line-height: 1.5;
        }
        .card .tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .tag {
            background: #f1f5f9;
            color: #334155;
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 999px;
        }
        .card ul {
            margin: 0;
            padding-left: 18px;
            color: var(--muted);
            line-height: 1.6;
        }
        .card .paths {
            font-size: 12px;
            color: #64748b;
            background: #f8fafc;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px dashed #cbd5f5;
        }
        .cta {
            margin-top: 24px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--accent);
            font-weight: 600;
        }
        footer {
            margin-top: 48px;
            color: var(--muted);
            font-size: 13px;
        }
    </style>
</head>
<body>
    <main class="container">
        <header>
            <span class="badge">Каталог виджетов</span>
            <h1>Публичный каталог виджетов</h1>
            <p class="lead">
                Здесь собраны виджеты, которые разрабатываются в этом репозитории. Страница доступна без авторизации,
                оптимизирована под поисковые системы и загружается максимально быстро.
            </p>
            <div class="meta">
                <span>Всего виджетов: {{ count($widgets) }}</span>
                <span>Последнее обновление: {{ $updatedAt }}</span>
                <span>Рекомендация: используйте карточки как краткие страницы-посадки.</span>
            </div>
        </header>

        <section class="grid" aria-label="Список виджетов">
            @foreach ($widgets as $widget)
                <article class="card" id="{{ $widget['slug'] }}">
                    <div>
                        <h2>{{ $widget['name'] }}</h2>
                        <p>{{ $widget['summary'] }}</p>
                    </div>
                    <div class="tags">
                        <span class="tag">{{ $widget['category'] }}</span>
                        <span class="tag">Без авторизации</span>
                        <span class="tag">SEO Ready</span>
                    </div>
                    <ul>
                        @foreach ($widget['features'] as $feature)
                            <li>{{ $feature }}</li>
                        @endforeach
                    </ul>
                    <div class="paths">
                        <strong>Файлы в репозитории</strong><br />
                        {{ $widget['repo_path'] }}<br />
                        {{ $widget['view_path'] }}
                    </div>
                </article>
            @endforeach
        </section>

        <a class="cta" href="{{ url()->current() }}#{{ $widgets[0]['slug'] ?? '' }}">
            Перейти к первому виджету →
        </a>

        <footer>
            Подсказка: добавляйте новые виджеты в массив <code>$widgets</code> маршрута <code>/widgets</code>,
            чтобы каталог обновлялся автоматически без дополнительной навигации.
        </footer>
    </main>

    <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "ItemList",
            "name": "Каталог виджетов",
            "itemListElement": [
                @foreach ($widgets as $index => $widget)
                    {
                        "@type": "ListItem",
                        "position": {{ $index + 1 }},
                        "name": "{{ $widget['name'] }}",
                        "url": "{{ url()->current() }}#{{ $widget['slug'] }}"
                    }@if (! $loop->last),@endif
                @endforeach
            ]
        }
    </script>
</body>
</html>
