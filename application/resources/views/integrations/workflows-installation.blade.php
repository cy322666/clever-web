<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    @if ($state === 'pending')
        <meta http-equiv="refresh" content="2">
    @endif
    <title>Подключение amoCRM | Потоки</title>
    <style>
        :root { color-scheme: light dark; font-family: system-ui, sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f6f5f3; color: #202020; }
        main { box-sizing: border-box; width: min(480px, calc(100% - 32px)); padding: 32px; background: #fff; border: 1px solid #e5e2dd; border-radius: 16px; }
        .brand { color: #bd4a06; font-weight: 700; font-size: 14px; }
        h1 { font-size: 24px; line-height: 1.3; margin: 20px 0 12px; }
        p { line-height: 1.6; color: #65615b; }
        a { color: #bd4a06; }
        .button { display: inline-block; margin-top: 10px; padding: 12px 18px; background: #b84812; color: #fff; border-radius: 9px; text-decoration: none; font-weight: 600; }
        a:focus-visible { outline: 3px solid #339dc7; outline-offset: 4px; }
        @media (prefers-color-scheme: dark) {
            body { background: #232429; color: #f5f5f4; }
            main { background: #2b2d33; border-color: #454851; }
            p { color: #bfc2cb; }
            .brand, a { color: #ffad78; }
            .button { color: #fff; }
        }
    </style>
</head>
<body>
<main>
    <div class="brand">CLEVER · Потоки</div>
    @if ($state === 'pending')
        <h1>Подключаем amoCRM</h1>
        <p role="status">Подождите немного. После завершения подключения «Потоки» откроются автоматически.</p>
    @elseif ($state === 'failed')
        <h1>Не удалось подключить amoCRM</h1>
        <p>Вернитесь в «Потоки» и запустите подключение заново. Если ошибка повторится, обратитесь в поддержку.</p>
        <a class="button" href="{{ $workflowsUrl }}">Вернуться в «Потоки»</a>
    @elseif ($state === 'delayed')
        <h1>Подключение занимает больше времени</h1>
        <p>Результат ещё не получен. Проверьте статус немного позже. Повторно подключать amoCRM пока не нужно.</p>
        <a class="button" href="{{ $statusUrl }}">Проверить статус</a>
    @elseif ($state === 'different_account')
        <h1>amoCRM подключена</h1>
        <p>Сейчас вы вошли в другой аккаунт платформы. Выйдите из него и войдите в аккаунт, к которому подключена amoCRM.</p>
        <a class="button" href="{{ $workflowsUrl }}">Открыть «Потоки»</a>
    @else
        <h1>Ссылка проверки устарела</h1>
        <p>Откройте «Потоки» и проверьте состояние подключения.</p>
        <a class="button" href="{{ $workflowsUrl }}">Открыть «Потоки»</a>
    @endif
</main>
</body>
</html>
