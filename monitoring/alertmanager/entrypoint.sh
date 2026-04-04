#!/bin/sh
set -eu

CONFIG_PATH=/etc/alertmanager/alertmanager.yml

HAS_TELEGRAM=0
HAS_SLACK=0

if [ -n "${ALERTMANAGER_TELEGRAM_BOT_TOKEN:-}" ] && [ -n "${ALERTMANAGER_TELEGRAM_CHAT_ID:-}" ]; then
  HAS_TELEGRAM=1
fi

if [ -n "${ALERTMANAGER_SLACK_WEBHOOK_URL:-}" ]; then
  HAS_SLACK=1
fi

cat > "$CONFIG_PATH" <<'YAML'
route:
  receiver: default
  group_by: ['alertname', 'severity']
  group_wait: 30s
  group_interval: 5m
  repeat_interval: 3h

receivers:
  - name: default
YAML

if [ "$HAS_TELEGRAM" -eq 1 ]; then
  cat >> "$CONFIG_PATH" <<EOF
    telegram_configs:
      - bot_token: "${ALERTMANAGER_TELEGRAM_BOT_TOKEN}"
        chat_id: ${ALERTMANAGER_TELEGRAM_CHAT_ID}
        parse_mode: "HTML"
        message: '{{ if eq .Status "firing" }}<b>Тревога</b>{{ else }}<b>Восстановлено</b>{{ end }}: {{ if eq .CommonLabels.alertname "AppUnavailable" }}Приложение недоступно{{ else if eq .CommonLabels.alertname "AppMetricsScrapeFailed" }}Метрики приложения недоступны{{ else if eq .CommonLabels.alertname "SchedulerHeartbeatStale" }}Задержка heartbeat планировщика{{ else if eq .CommonLabels.alertname "FailedJobsDetected" }}Обнаружены упавшие задачи{{ else if eq .CommonLabels.alertname "QueueBacklogHigh" }}Переполнена очередь задач{{ else if eq .CommonLabels.alertname "QueueOldestJobTooOld" }}Слишком старые задачи в очереди{{ else if eq .CommonLabels.alertname "MetricsCollectionError" }}Ошибка сбора метрик приложения{{ else if eq .CommonLabels.alertname "PostgresExporterDown" }}Недоступен Postgres exporter{{ else if eq .CommonLabels.alertname "DbSlowQueriesBurst" }}Всплеск медленных запросов к БД{{ else }}{{ .CommonLabels.alertname }}{{ end }}{{ if .CommonLabels.severity }} [{{ if eq .CommonLabels.severity "critical" }}критично{{ else if eq .CommonLabels.severity "warning" }}предупреждение{{ else }}{{ .CommonLabels.severity }}{{ end }}]{{ end }}{{ if .CommonLabels.instance }}\nИнстанс: <code>{{ .CommonLabels.instance }}</code>{{ end }}'
        send_resolved: true
EOF
fi

if [ "$HAS_SLACK" -eq 1 ]; then
  cat >> "$CONFIG_PATH" <<EOF
    slack_configs:
      - api_url: "${ALERTMANAGER_SLACK_WEBHOOK_URL}"
        channel: "${ALERTMANAGER_SLACK_CHANNEL:-#alerts}"
        title: '{{ .Status }}: {{ .CommonLabels.alertname }}'
        text: '{{ .CommonAnnotations.summary }} - {{ .CommonAnnotations.description }}'
        send_resolved: true
EOF
fi

if [ "$HAS_TELEGRAM" -eq 0 ] && [ "$HAS_SLACK" -eq 0 ]; then
  echo "Alertmanager: no notification channel configured (telegram/slack)." >&2
fi

exec /bin/alertmanager --config.file="$CONFIG_PATH" --storage.path=/alertmanager
