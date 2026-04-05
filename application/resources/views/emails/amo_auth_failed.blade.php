<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>Проблема авторизации amoCRM — Clever Platform</title>
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
                        <p style="margin:0 0 12px 0;">Здравствуйте!</p>
                        <p style="margin:0 0 16px 0;">
                            Обнаружили проблему с авторизацией amoCRM. Интеграции могут работать некорректно, пока не
                            выполнена повторная авторизация.
                        </p>

                        <div
                            style="background:#FFF6F5; border:1px solid #FFD8D2; border-radius:10px; padding:12px 14px; margin:16px 0;">
                            <p style="margin:0 0 6px 0; font-weight:600; color:#262626;">Детали</p>
                            <p style="margin:0; font-size:13px; color:#4F4F4F;">
                                Аккаунт: <b style="color:#262626;">{{ $account->subdomain ?? '—' }}</b><br>
                                Контекст: <b style="color:#262626;">{{ $context }}</b><br>
                                Ошибка: <b style="color:#262626;">{{ $error ?: 'Неизвестная ошибка' }}</b>
                            </p>
                        </div>

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
                            Чтобы убрать ошибку, переподключите amoCRM в карточке аккаунта.
                            Следующее письмо по этой же проблеме придет не раньше чем через {{ $cooldownMinutes }} мин.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 24px; background:#F6F6F6; border-top:1px solid #E6E6E6;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                            <tr>
                                <td style="font-size:12px; color:#4F4F4F;">Поддержка Clever Platform</td>
                                <td align="right">
                                    <a href="mailto:tech@blackclever.ru"
                                       style="margin-right:10px; text-decoration:none; font-size:12px; color:#262626;">Email</a>
                                    <a href="https://t.me/cleverplatform_support_bot"
                                       style="margin-right:10px; text-decoration:none; font-size:12px; color:#262626;">Telegram</a>
                                    <a href="https://clevercrm.pro"
                                       style="text-decoration:none; font-size:12px; color:#262626;">Сайт</a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

