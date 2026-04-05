# Release Checklist

## Pre-deploy

1. Убедиться, что `.env` содержит:

- `TELEGRAM_ALERTS_TOKEN`
- `TELEGRAM_ALERTS_CHAT_ID`
- `ALERTS_*` переменные (если нужны email-алерты)

2. Прогнать базовые проверки:

```bash
php artisan app:smoke --strict
```

## Deploy

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan optimize
```

## Post-deploy

1. Проверить health:

```bash
curl -fsS https://<app>/up
```

2. Проверить метрики:

```bash
curl -fsS "https://<app>/metrics?token=<METRICS_TOKEN>" | head
```

3. Проверить очередь и мониторинг:

```bash
php artisan app:queue-backfill-failed --limit=1000 --dry-run
php artisan app:monitor-queue-health --sample=3
```

4. Проверить UI:

- `/panel/core/users` (кнопка "Очереди")
- `/panel/queue-monitors`
- `/panel/api-requests` (последние API запросы)

5. Сделать тестовую регистрацию и убедиться, что пришёл alert в TG/mail.
