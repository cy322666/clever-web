# Release Checklist

## Source of truth

Исходники изменяются только локально. Порядок: проверки, коммит, push в GitHub,
выкладка этого SHA. Редактирование исходников и перенос отдельных патчей на сервер
запрещены. GitHub `master`, основная локальная папка и прод после релиза должны
совпадать по HEAD и иметь чистое состояние исходного кода.

Перед выкладкой и после неё:

```bash
bash ops/deploy/assert-clean-tree.sh
git rev-parse HEAD
git ls-remote origin refs/heads/master
```

При расхождении остановить выкладку, сохранить изменения, объединить их локально
и отправить новый коммит. Не обходить защиту через stash/reset/ручные замены.
Настройки окружения, секреты, зависимости, БД, загрузки и резервные копии не
синхронизируются как исходники. Сборка должна происходить из релизного SHA.

## Pre-deploy

1. Убедиться, что `.env` содержит:

- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_CHAT_ID`
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
