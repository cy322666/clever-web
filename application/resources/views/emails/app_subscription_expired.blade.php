<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>Подписка интеграции завершилась — Clever Platform</title>
</head>
<body
    style="margin:0; padding:0; background:#F6F6F6; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F6F6F6; padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="560" cellspacing="0" cellpadding="0"
                   style="background:#FFFFFF; border-radius:12px; overflow:hidden;">
                <tr>
                    <td style="padding:20px 24px; border-bottom:1px solid #E6E6E6;">
                        <p style="margin:0; font-size:18px; font-weight:700; color:#262626;">Clever Platform</p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px; color:#262626; font-size:14px; line-height:20px;">
                        <p style="margin:0 0 12px 0;">Здравствуйте, {{ $user->name ?? 'коллеги' }}!</p>

                        <p style="margin:0 0 12px 0;">
                            Подписка по интеграции <b>{{ $appName }}</b> завершилась.
                        </p>

                        <div
                            style="background:#FFF6F5; border:1px solid #FFD8D2; border-radius:10px; padding:12px 14px; margin:16px 0;">
                            <p style="margin:0 0 6px 0; font-weight:600; color:#262626;">Детали</p>
                            <p style="margin:0; font-size:13px; color:#4F4F4F;">
                                Интеграция: <b style="color:#262626;">{{ $appName }}</b><br>
                                ID: <b style="color:#262626;">{{ $appId }}</b><br>
                                Дата окончания: <b style="color:#262626;">{{ $expiresAt ?: '—' }}</b>
                            </p>
                        </div>

                        <p style="margin:0 0 16px 0;">
                            Интеграция автоматически переведена в неактивный режим. Для восстановления работы продлите
                            подписку.
                        </p>

                        <table role="presentation" cellspacing="0" cellpadding="0" style="margin:20px 0;">
                            <tr>
                                <td>
                                    <a href="{{ route('filament.app.pages.dashboard') }}"
                                       style="display:inline-block; background:#FF4E36; color:#FFFFFF; text-decoration:none; padding:10px 16px; border-radius:8px; font-weight:600; font-size:14px;">
                                        Открыть платформу
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:16px 0 0 0; font-size:12px; color:#4F4F4F;">
                            Если нужна помощь с продлением, ответьте на это письмо или напишите в поддержку.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>
