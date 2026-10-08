<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    @if ($state === 'pending')
        <meta http-equiv="refresh" content="2;url={{ $refreshUrl }}">
    @endif
    <title>Подключение amoCRM | Clever</title>
    <link rel="icon" href="/favicon-clevercrm-20260922.ico" sizes="any">
    <link rel="stylesheet" href="/fonts/clevercrm/clevercrm-v1.css">
    <script>
        try {
            const theme = localStorage.getItem('theme');
            document.documentElement.dataset.theme = theme === 'dark' ||
                (theme !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        } catch (_) {}
    </script>
    <style>
        :root { color-scheme: light; font-family: Manrope, sans-serif; --page: #f6f4f0; --text: #1c1917; --muted: #78716c; --border: #d6d3d1; --accent: #ff6a00; }
        :root[data-theme="dark"] { color-scheme: dark; --page: #111111; --text: #fafaf9; --muted: #a8a29e; --border: #44403c; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; min-height: 100dvh; display: grid; place-items: center; padding: 32px 24px; background: var(--page); color: var(--text); }
        main { width: min(440px, 100%); }
        .logo { display: block; width: 116px; height: auto; margin-bottom: 40px; }
        h1 { margin: 0 0 12px; font-size: clamp(24px, 5vw, 28px); font-weight: 700; line-height: 1.35; }
        p { margin: 0; font-size: 15px; line-height: 1.65; color: var(--muted); }
        .loading { display: flex; align-items: center; gap: 12px; margin-top: 24px; color: var(--muted); font-size: 14px; }
        .spinner { flex: none; width: 18px; height: 18px; border: 2px solid var(--border); border-top-color: var(--accent); border-radius: 50%; animation: spin 1s linear infinite; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 28px; }
        a { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 10px 18px; border: 1px solid var(--border); border-radius: 8px; color: var(--text); text-decoration: none; font-size: 14px; font-weight: 600; }
        a.primary { background: var(--accent); border-color: var(--accent); color: #1c1917; }
        a:focus-visible { outline: 2px solid var(--accent); outline-offset: 4px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .spinner { animation: none; } }
    </style>
</head>
<body>
<main>
    <img class="logo" src="/logo/full_logo.png" alt="Clever" width="2667" height="908">
    @if ($state === 'pending')
        <h1>Подключаем amoCRM</h1>
        <p>Настройки {{ $widgetLabel }} откроются автоматически.</p>
        <div class="loading" role="status"><span class="spinner" aria-hidden="true"></span>Подождите немного</div>
    @elseif ($state === 'failed')
        <h1>Не удалось завершить подключение</h1>
        <p>Попробуйте подключить amoCRM ещё раз из настроек виджета. Если ошибка повторится, напишите нам.</p>
    @elseif ($state === 'delayed')
        <h1>Нужно чуть больше времени</h1>
        <p>Подключение ещё обрабатывается. Повторно устанавливать виджет не нужно.</p>
    @elseif ($state === 'unavailable')
        <h1>Не удалось открыть настройки</h1>
        <p>Подключение завершено, но настройки виджета недоступны. Напишите в поддержку.</p>
    @else
        <h1>Ссылка проверки устарела</h1>
        <p>Откройте интеграцию в платформе, чтобы проверить подключение.</p>
    @endif
    @if ($state !== 'pending')
        <div class="actions">
            @if ($state === 'delayed')
                <a class="primary" href="{{ $refreshUrl }}">Проверить снова</a>
            @else
                <a class="primary" href="{{ $dashboardUrl }}">К интеграциям</a>
            @endif
            <a href="https://button.amocrm.ru/ddrllz" target="_blank" rel="noopener noreferrer">Поддержка</a>
        </div>
    @endif
</main>
</body>
</html>
