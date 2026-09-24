<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    @if ($state === 'pending')
        <meta http-equiv="refresh" content="2">
    @endif
    <title>Подключение amoCRM | Контроль ответов</title>
    <style>
        :root { color-scheme: light dark; font-family: system-ui, sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f6f5f3; color: #202020; }
        main { box-sizing: border-box; width: min(540px, calc(100% - 32px)); padding: 32px; background: #fff; border: 1px solid #e5e2dd; border-radius: 18px; }
        .brand { color: #eb6d26; font-weight: 700; font-size: 14px; }
        h1 { font-size: 25px; line-height: 1.3; }
        p { line-height: 1.6; color: #65615b; }
        a { color: #bd4a06; }
        .button { display: inline-block; margin-top: 10px; padding: 12px 18px; background: #ec7027; color: #fff; border-radius: 9px; text-decoration: none; font-weight: 600; }
        @media (prefers-color-scheme: dark) {
            body { background: #111; color: #f5f5f4; }
            main { background: #191919; border-color: #35312d; }
            p { color: #bbb5ad; }
            a { color: #ffad78; }
        }
    </style>
</head>
<body>
<main>
    <div class="brand">CLEVER · Контроль ответов</div>
    @if ($state === 'pending')
        <h1>Подключаем amoCRM</h1>
        <p>Подождите немного. После завершения подключения настройки виджета откроются автоматически, если вы вошли в нужный аккаунт платформы.</p>
    @elseif ($state === 'completed')
        <h1>amoCRM подключена</h1>
        <p>Аккаунт {{ $domain }} подключён. Подписка на сообщения настраивается автоматически. Войдите в связанный с ним аккаунт платформы. Если сейчас открыт другой аккаунт, сначала выйдите из него.</p>
        <a class="button" href="{{ $loginUrl }}">Войти в платформу</a>
    @elseif ($state === 'failed')
        <h1>Не удалось завершить подключение</h1>
        <p>Вернитесь в настройки виджета и запустите подключение amoCRM заново.</p>
        <a class="button" href="{{ $dashboardUrl }}">Открыть платформу</a>
    @elseif ($state === 'delayed')
        <h1>Подключение занимает больше времени</h1>
        <p>Обновите эту страницу немного позже, чтобы проверить результат.</p>
        <a class="button" href="{{ $dashboardUrl }}">Открыть платформу</a>
    @else
        <h1>Ссылка проверки устарела</h1>
        <p>Откройте платформу и проверьте подключение в настройках виджета.</p>
        <a class="button" href="{{ $dashboardUrl }}">Открыть платформу</a>
    @endif
</main>
</body>
</html>
