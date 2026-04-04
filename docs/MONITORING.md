# Monitoring

## Что добавлено

- Единый стек мониторинга в `monitoring/docker-compose.monitoring.yml`:
    - Prometheus
    - Alertmanager
    - Grafana
    - Loki + Promtail
    - Postgres Exporter
    - Blackbox Exporter
- Health endpoint: `GET /up`
- Metrics endpoint (Prometheus): `GET /metrics`
- Heartbeat планировщика: команда `app:monitor-heartbeat` в `schedule()->everyMinute()`

## Быстрый запуск

1. Скопируйте env для мониторинга:

```bash
cp monitoring/.env.monitoring.example monitoring/.env.monitoring
```

2. Поднимите основной стек + мониторинг:

```bash
docker compose --env-file monitoring/.env.monitoring -f docker-compose.yml -f monitoring/docker-compose.monitoring.yml up -d --build
```

3. Откройте интерфейсы:

- Grafana: `http://localhost:3000`
- Prometheus: `http://localhost:9090`
- Alertmanager: `http://localhost:9093`

## Ключевые метрики приложения

- `clever_queue_jobs_total`
- `clever_queue_failed_jobs_total`
- `clever_queue_jobs_by_queue{queue="..."}`
- `clever_queue_oldest_job_age_seconds`
- `clever_scheduler_last_heartbeat_unixtime`
- `clever_scheduler_heartbeat_age_seconds`
- `clever_db_slow_queries_total`
- `clever_db_last_slow_query_ms`
- `clever_db_last_slow_query_unixtime`
- `clever_metrics_up`

## Базовые алерты

Правила находятся в `monitoring/prometheus/alert.rules.yml`:

- AppUnavailable
- AppMetricsScrapeFailed
- SchedulerHeartbeatStale
- FailedJobsDetected
- QueueBacklogHigh
- QueueOldestJobTooOld
- MetricsCollectionError
- PostgresExporterDown
- DbSlowQueriesBurst

## Медленные запросы к БД

- В приложении включён listener запросов и счетчик медленных SQL.
- Порог задается через `DB_SLOW_QUERY_THRESHOLD_MS` (по умолчанию `1000` мс).
- Для приватности SQL-текст в лог не пишется; включить можно через `DB_SLOW_QUERY_SAMPLE_SQL=true`.
- Alert `DbSlowQueriesBurst` срабатывает, если больше 10 медленных запросов за 10 минут.

## Важно по безопасности

- `/metrics` защищён токеном `METRICS_TOKEN`.
- Prometheus шлёт токен как query-параметр `token`.
- Токен автоматически подставляется в конфиг Prometheus из `METRICS_TOKEN` при старте контейнера.

## Уведомления (Telegram / Slack)

Alertmanager теперь автоматически собирает конфиг из env-переменных при старте.

Заполните в `monitoring/.env.monitoring`:

- Telegram:
    - `ALERTMANAGER_TELEGRAM_BOT_TOKEN`
    - `ALERTMANAGER_TELEGRAM_CHAT_ID`
- Slack:
    - `ALERTMANAGER_SLACK_WEBHOOK_URL`
    - `ALERTMANAGER_SLACK_CHANNEL` (опционально)

Можно включить сразу оба канала.

Применить изменения:

```bash
docker compose --env-file monitoring/.env.monitoring -f docker-compose.yml -f monitoring/docker-compose.monitoring.yml up -d alertmanager
```

## Telescope в production

- Telescope выключен в production по умолчанию.
- При необходимости разово включается переменной `TELESCOPE_ENABLED=true`.

## Production: Нормальная схема (subdomains + Nginx)

В репозитории есть:

- `monitoring/docker-compose.monitoring.prod.yml` — публикует сервисы только на `127.0.0.1`.
- `monitoring/nginx/clevercrm.pro.conf.example` — готовый шаблон reverse proxy под:
    - `app.clevercrm.pro`
    - `grafana.clevercrm.pro`
    - `prometheus.clevercrm.pro` (Basic Auth)
    - `alerts.clevercrm.pro` (Basic Auth)

### 1) DNS

Создайте A-записи на IP прод-сервера:

- `app.clevercrm.pro`
- `grafana.clevercrm.pro`
- `prometheus.clevercrm.pro`
- `alerts.clevercrm.pro`

### 2) Запуск compose в production-режиме

```bash
docker compose \
  --env-file monitoring/.env.monitoring \
  -f docker-compose.yml \
  -f monitoring/docker-compose.monitoring.prod.yml \
  up -d --build
```

### 3) Nginx + Basic Auth для monitoring

```bash
sudo apt-get update
sudo apt-get install -y nginx apache2-utils certbot python3-certbot-nginx

sudo cp monitoring/nginx/clevercrm.pro.conf.example /etc/nginx/sites-available/clevercrm.pro
sudo ln -s /etc/nginx/sites-available/clevercrm.pro /etc/nginx/sites-enabled/clevercrm.pro

sudo htpasswd -c /etc/nginx/.htpasswd-monitoring monitoring

sudo nginx -t
sudo systemctl reload nginx
```

### 4) TLS сертификаты

```bash
sudo certbot --nginx \
  -d app.clevercrm.pro \
  -d grafana.clevercrm.pro \
  -d prometheus.clevercrm.pro \
  -d alerts.clevercrm.pro
```

После этого рабочие URL:

- `https://app.clevercrm.pro`
- `https://grafana.clevercrm.pro`
- `https://prometheus.clevercrm.pro` (Basic Auth)
- `https://alerts.clevercrm.pro` (Basic Auth)

### 5) Закрыть прямой доступ к служебным портам (рекомендуется)

Если на сервере включен UFW:

```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw deny 3000/tcp
sudo ufw deny 9090/tcp
sudo ufw deny 9093/tcp
sudo ufw deny 3100/tcp
sudo ufw deny 9115/tcp
sudo ufw deny 9187/tcp
```

## Мониторинг очередей в приложении

Добавлены команды:

- `app:monitor-queue-health` — проверка новых `failed_jobs` и зависших jobs c отправкой алертов (TG/mail).
- `app:queue-backfill-failed` — backfill `failed_jobs` -> `queue_monitors`, чтобы UI очередей показывал исторические
  падения.
- `app:smoke` — post-deploy smoke checks (БД, таблицы очередей, роуты, heartbeat).

Планировщик (`app/Console/Kernel.php`):

- `app:monitor-queue-health` — каждую минуту
- `app:queue-backfill-failed --limit=1000` — каждую минуту

UI для операторов:

- `/panel/queue-monitors` — монитор `queue_monitors`
- `/panel/app-stats` — дополнительно виджет метрик очередей, которые не дублируются в Grafana
