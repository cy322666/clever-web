<?php
$english = ($locale ?? 'ru') === 'en';
$copy = $english ? [
    'title' => 'Something went wrong',
    'description' => 'We could not complete your request. Go back and try again, or contact support.',
    'code' => 'Error',
    'back' => 'Back',
    'support' => 'Support',
    'new_tab' => 'opens in a new tab',
] : [
    'title' => 'Возникла ошибка',
    'description' => 'Не удалось выполнить запрос. Попробуйте вернуться назад или обратитесь в поддержку.',
    'code' => 'Ошибка',
    'back' => 'Назад',
    'support' => 'Поддержка',
    'new_tab' => 'откроется в новой вкладке',
];
?>
<!DOCTYPE html>
<html lang="<?= $english ? 'en' : 'ru' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= $copy['title'] ?> | Clever</title>
    <link rel="icon" href="/favicon-clevercrm-20260922.ico" sizes="any">
    <link rel="stylesheet" href="/fonts/clevercrm/clevercrm-v1.css">
    <script>
        try {
            const theme = localStorage.getItem('theme');
            document.documentElement.dataset.theme = theme === 'dark' ||
                (theme !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        } catch (_) {}
    </script>
    <style>
        :root {
            color-scheme: light;
            --page: #f6f4f0;
            --surface: #fff;
            --text: #292524;
            --muted: #706860;
            --border: #e8e1d7;
            --accent: #ff6a00;
            --accent-soft: #fff1e5;
            --glow: #ffe4cb;
        }
        :root[data-theme="dark"] {
            color-scheme: dark;
            --page: #151413;
            --surface: #211f1d;
            --text: #faf7f2;
            --muted: #b5ada5;
            --border: #403a34;
            --accent-soft: #39271a;
            --glow: #332214;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100svh;
            display: grid;
            place-items: center;
            padding: 40px 20px;
            color: var(--text);
            background: radial-gradient(ellipse at 75% 5%, var(--glow), transparent 48%), var(--page);
            font-family: 'Manrope', 'Segoe UI', sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .error-shell { width: 100%; max-width: 600px; }
        .brand { display: block; width: 116px; height: auto; margin: 0 0 28px 4px; }
        main {
            padding: 44px;
            border: 1px solid var(--border);
            border-radius: 24px;
            background: var(--surface);
            box-shadow: 0 16px 48px rgb(28 25 23 / 5%);
        }
        .error-code {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 24px;
            padding: 7px 11px;
            border-radius: 8px;
            background: var(--accent-soft);
            color: var(--text);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .025em;
        }
        .error-code svg { color: var(--accent); }
        h1 { margin: 0; font-size: clamp(28px, 4vw, 38px); line-height: 1.2; font-weight: 750; letter-spacing: -.04em; }
        .description { max-width: 440px; margin: 18px 0 32px; color: var(--muted); font-size: 15px; line-height: 1.8; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; }
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 48px;
            padding: 12px 20px;
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            background: var(--surface);
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.4;
        }
        .button-primary { background: var(--accent); border-color: var(--accent); color: #24170b; }
        .button:hover { border-color: var(--accent); }
        .button-primary:hover { background: #f47b24; }
        .button:focus-visible { outline: 3px solid var(--accent); outline-offset: 4px; }
        svg { flex-shrink: 0; }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        @media (max-width: 480px) {
            body { padding: 28px 16px; }
            main { padding: 28px 24px; border-radius: 18px; }
            .brand { width: 105px; margin-bottom: 24px; }
            .description { font-size: 14px; margin-bottom: 26px; }
            .actions { flex-direction: column; }
            .button { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="error-shell">
        <img class="brand" src="/logo/full_logo.png" alt="Clever" width="2667" height="908">
        <main aria-labelledby="error-title">
            <p class="error-code">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v6m0 3v1" stroke-linecap="round"/></svg>
                <?= $copy['code'] ?> <?= (int) ($statusCode ?? 500) ?>
            </p>
            <h1 id="error-title"><?= $copy['title'] ?></h1>
            <p class="description"><?= $copy['description'] ?></p>
            <nav class="actions" aria-label="<?= $english ? 'What to do next' : 'Что можно сделать' ?>">
                <a class="button button-primary" id="server-error-back" target="_top" href="<?= htmlspecialchars($backUrl ?? '/panel/dashboard', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10 5-7 7 7 7M3 12h18"/></svg>
                    <?= $copy['back'] ?>
                </a>
                <a class="button" href="https://button.amocrm.ru/ddrllz" target="_blank" rel="noopener noreferrer">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"/></svg>
                    <?= $copy['support'] ?><span class="sr-only"> (<?= $copy['new_tab'] ?>)</span>
                </a>
            </nav>
        </main>
    </div>
</body>
</html>
