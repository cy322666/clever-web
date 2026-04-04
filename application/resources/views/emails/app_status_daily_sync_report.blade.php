<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>Ежедневная синхронизация интеграций — Clever Platform</title>
</head>
<body
    style="margin:0; padding:0; background:#F6F6F6; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F6F6F6; padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="640" cellspacing="0" cellpadding="0"
                   style="background:#FFFFFF; border-radius:12px; overflow:hidden;">
                <tr>
                    <td style="padding:20px 24px; border-bottom:1px solid #E6E6E6;">
                        <p style="margin:0; font-size:18px; font-weight:700; color:#262626;">Clever Platform</p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px; color:#262626; font-size:14px; line-height:20px;">
                        <p style="margin:0 0 12px 0;">Здравствуйте, {{ $user->name ?? 'коллеги' }}!</p>

                        <p style="margin:0 0 16px 0;">
                            Выполнена ежедневная актуализация статусов интеграций за {{ $syncDate->format('d.m.Y') }}.
                            Изменения найдены по {{ count($items) }} прилож.
                        </p>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0"
                               style="border-collapse:collapse; margin:12px 0;">
                            <thead>
                            <tr>
                                <th align="left"
                                    style="padding:8px; border:1px solid #E6E6E6; font-size:12px; background:#F6F6F6;">
                                    Приложение
                                </th>
                                <th align="left"
                                    style="padding:8px; border:1px solid #E6E6E6; font-size:12px; background:#F6F6F6;">
                                    Статус
                                </th>
                                <th align="left"
                                    style="padding:8px; border:1px solid #E6E6E6; font-size:12px; background:#F6F6F6;">
                                    Срок
                                </th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($items as $item)
                                <tr>
                                    <td style="padding:8px; border:1px solid #E6E6E6; vertical-align:top;">
                                        <div style="font-weight:600;">{{ $item['app_name'] }}</div>
                                        <div style="font-size:12px; color:#4F4F4F;">ID: {{ $item['app_id'] }}</div>
                                    </td>
                                    <td style="padding:8px; border:1px solid #E6E6E6; vertical-align:top;">
                                        <div>{{ $item['status_before_label'] }}
                                            → {{ $item['status_after_label'] }}</div>
                                        <div style="margin-top:6px; font-size:12px; color:#4F4F4F;">
                                            @foreach($item['changes'] as $line)
                                                <div>• {{ $line }}</div>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td style="padding:8px; border:1px solid #E6E6E6; vertical-align:top; font-size:12px; color:#4F4F4F;">
                                        До: {{ $item['expires_before'] ?: '—' }}<br>
                                        После: {{ $item['expires_after'] ?: '—' }}
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>

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
                            Письмо отправлено автоматически после ежедневной проверки интеграций.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>
