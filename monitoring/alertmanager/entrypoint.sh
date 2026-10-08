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
  routes:
    # Application errors are already delivered by the platform.
    - receiver: application-errors
      matchers: ['alertname="FailedJobsDetected"']

receivers:
  - name: application-errors
  - name: default
YAML

if [ "$HAS_TELEGRAM" -eq 1 ]; then
  cat >> "$CONFIG_PATH" <<EOF
    telegram_configs:
      - bot_token: "${ALERTMANAGER_TELEGRAM_BOT_TOKEN}"
        chat_id: ${ALERTMANAGER_TELEGRAM_CHAT_ID}
        parse_mode: "HTML"
        message: |-
          <b>{{ if eq .Status "firing" }}Тревога{{ else }}Восстановлено{{ end }}: Clever Web</b>
          {{ range .Alerts }}
          <b>{{ if .Annotations.summary }}{{ .Annotations.summary | html }}{{ else }}{{ .Labels.alertname | html }}{{ end }}</b>
          {{ .Annotations.description | html }}
          {{ if .Labels.container_label_com_docker_compose_service }}Сервис: <code>{{ .Labels.container_label_com_docker_compose_service | html }}</code>{{ end }}
          {{ if .Labels.instance }}Узел: <code>{{ .Labels.instance | html }}</code>{{ end }}
          {{ if .Labels.severity }}Уровень: {{ if eq .Labels.severity "critical" }}критично{{ else if eq .Labels.severity "warning" }}предупреждение{{ else }}{{ .Labels.severity | html }}{{ end }}{{ end }}
          {{ end }}
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
