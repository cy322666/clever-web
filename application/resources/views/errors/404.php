<?php
$english = app()->getLocale() === 'en';
$copy = $english ? [
    'title' => 'Page not found',
    'description' => 'The link may be out of date, or the page may have been removed.',
    'back' => 'Go back',
    'help' => 'Need help?',
    'support' => 'Contact support',
    'new_tab' => 'opens in a new tab',
] : [
    'title' => 'Страница не найдена',
    'description' => 'Возможно, ссылка устарела или страница была удалена.',
    'back' => 'Назад',
    'help' => 'Нужна помощь?',
    'support' => 'Поддержка',
    'new_tab' => 'откроется в новой вкладке',
];
$backUrl = '/panel/dashboard';
$previous = (string) request()->headers->get('referer', '');
$origin = request()->getSchemeAndHttpHost();

// Never send a user to an external or malformed address supplied as a referrer.
if (str_starts_with($previous, $origin.'/') && !str_contains($previous, '\\')
    && !preg_match('/[\x00-\x20]/', $previous)) {
    $path = substr($previous, strlen($origin));
    if (!str_starts_with($path, '//') && $path !== request()->getRequestUri()) {
        $backUrl = $path;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $english ? 'en' : 'ru' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>404 · <?= $copy['title'] ?> | Clever</title>
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
            --glow: #ffead8;
        }
        :root[data-theme="dark"] {
            color-scheme: dark;
            --page: #151413;
            --surface: #211f1d;
            --text: #faf7f2;
            --muted: #b5ada5;
            --border: #403a34;
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
            background: radial-gradient(ellipse at 80% 0, var(--glow), transparent 45%), var(--page);
            font-family: 'Manrope', 'Segoe UI', sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .error-shell { width: 100%; max-width: 620px; }
        .brand { display: inline-flex; margin: 0 0 28px 4px; border-radius: 4px; }
        .brand img { display: block; width: 116px; height: auto; }
        main {
            padding: 40px 44px 36px;
            border: 1px solid var(--border);
            border-radius: 24px;
            background: var(--surface);
            box-shadow: 0 16px 48px rgb(28 25 23 / 5%);
        }
        .error-code {
            margin: 0 0 24px;
            color: var(--accent);
            font-family: 'Barlow', 'Manrope', sans-serif;
            font-size: clamp(84px, 16vw, 112px);
            font-weight: 600;
            line-height: 1;
            letter-spacing: -.045em;
        }
        h1 { margin: 0; font-size: clamp(27px, 4vw, 36px); font-weight: 750; line-height: 1.2; letter-spacing: -.035em; }
        .description { max-width: 400px; margin: 16px 0 30px; color: var(--muted); font-size: 15px; line-height: 1.75; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; }
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
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
        .button:hover { border-color: var(--accent); }
        a:focus-visible { outline: 3px solid var(--accent); outline-offset: 4px; }
        .help { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; margin: 28px 0 0; padding-top: 20px; border-top: 1px solid var(--border); color: var(--muted); font-size: 13px; line-height: 1.6; }
        .support { display: inline-flex; align-items: center; gap: 5px; min-height: 36px; color: var(--text); font-weight: 650; text-decoration: underline; text-underline-offset: 4px; text-decoration-color: var(--border); }
        .support:hover { text-decoration-color: var(--accent); }
        svg { flex-shrink: 0; }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        @media (max-width: 480px) {
            body { padding: 28px 16px; }
            main { padding: 28px 24px 22px; border-radius: 18px; }
            .brand { margin-bottom: 22px; }
            .brand img { width: 105px; }
            .error-code { margin-bottom: 20px; }
            .description { font-size: 14px; margin-bottom: 24px; }
            .actions { flex-direction: column; }
            .button { width: 100%; }
            .help { margin-top: 24px; padding-top: 16px; }
        }
    </style>
</head>
<body>
    <div class="error-shell">
        <a class="brand" href="/panel/dashboard" target="_top">
            <img src="/logo/full_logo.png" alt="Clever" width="2667" height="908">
        </a>
        <main aria-labelledby="error-title">
            <p class="error-code">404</p>
            <h1 id="error-title"><?= $copy['title'] ?></h1>
            <p class="description"><?= $copy['description'] ?></p>
            <nav class="actions" aria-label="<?= $english ? 'What to do next' : 'Что можно сделать' ?>">
                <a class="button" id="not-found-back" href="<?= htmlspecialchars($backUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_top">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12H4m7-7-7 7 7 7"/></svg>
                    <?= $copy['back'] ?>
                </a>
            </nav>
            <p class="help">
                <span><?= $copy['help'] ?></span>
                <a class="support" id="not-found-support" href="https://button.amocrm.ru/ddrllz" target="_blank" rel="noopener noreferrer">
                    <?= $copy['support'] ?><span class="sr-only"> (<?= $copy['new_tab'] ?>)</span>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg>
                </a>
            </p>
        </main>
    </div>
</body>
</html>
