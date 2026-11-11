# Мониторинг

Минимальный production-стек:

- Prometheus собирает `/metrics` раз в 30 секунд.
- Alertmanager отправляет тревоги и восстановления в Telegram.
- Grafana показывает один русский дашборд.

Loki, Promtail, Blackbox, cAdvisor и postgres-exporter не используются.

## Переменные

Стек читает `application/.env`:

```dotenv
METRICS_TOKEN=...
TELEGRAM_BOT_TOKEN=...
TELEGRAM_CHAT_ID=...
TELEGRAM_MESSAGE_THREAD_ID=
GRAFANA_ADMIN_USER=admin
GRAFANA_ADMIN_PASSWORD=...
```

`TELEGRAM_MESSAGE_THREAD_ID` необязателен. Если пароль Grafana не задан, при первом запуске используется `METRICS_TOKEN`. Отдельный `monitoring/.env.monitoring` больше не нужен.

## Запуск

```bash
docker compose \
  --env-file application/.env \
  -f docker-compose.yml \
  -f monitoring/docker-compose.monitoring.prod.yml \
  up -d --remove-orphans alertmanager prometheus grafana
```

Проверка:

```bash
curl -fsS http://127.0.0.1:9090/-/ready
curl -fsS http://127.0.0.1:9093/-/ready
curl -fsS http://127.0.0.1:3000/api/health
```

На обычном деплое и после перезагрузки сервера стек запускается автоматически.

## Что контролируется

- доступность `/metrics`;
- внутреннее состояние метрик;
- heartbeat scheduler;
- новые failed jobs;
- размер и возраст очереди;
- всплески медленных SQL.

Пороги находятся в `monitoring/prometheus/alert.rules.yml`.

## Grafana

Открывается только Grafana. Prometheus и Alertmanager доступны лишь на `127.0.0.1`.

Для `grafana.clevercrm.pro` нужны:

1. A-запись на IP production-сервера.
2. Nginx-конфиг из `monitoring/nginx/clevercrm.pro.conf.example`.
3. Сертификат:

```bash
sudo certbot --nginx -d grafana.clevercrm.pro
```

Дашборд `Clever Web: мониторинг` загружается автоматически.
