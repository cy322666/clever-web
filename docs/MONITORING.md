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
