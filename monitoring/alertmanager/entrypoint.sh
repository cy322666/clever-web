#!/bin/sh
set -eu

CONFIG_PATH=/etc/alertmanager/alertmanager.yml
BOT_TOKEN=${TELEGRAM_BOT_TOKEN:-${ALERTMANAGER_TELEGRAM_BOT_TOKEN:-}}
CHAT_ID=${TELEGRAM_CHAT_ID:-${ALERTMANAGER_TELEGRAM_CHAT_ID:-}}
THREAD_ID=${TELEGRAM_MESSAGE_THREAD_ID:-}

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

if [ -n "$BOT_TOKEN" ] && [ -n "$CHAT_ID" ]; then
  THREAD_LINE=""
  if [ -n "$THREAD_ID" ]; then
    THREAD_LINE="        message_thread_id: ${THREAD_ID}"
  fi

  cat >> "$CONFIG_PATH" <<EOF
    telegram_configs:
      - bot_token: "${BOT_TOKEN}"
        chat_id: ${CHAT_ID}
${THREAD_LINE}
        parse_mode: "HTML"
        message: |-
          <b>{{ if eq .Status "firing" }}Тревога{{ else }}Восстановлено{{ end }}: Clever Web</b>
          {{ range .Alerts }}
          <b>{{ if .Annotations.summary }}{{ .Annotations.summary | html }}{{ else }}{{ .Labels.alertname | html }}{{ end }}</b>
          {{ .Annotations.description | html }}
          {{ if .Labels.instance }}Узел: <code>{{ .Labels.instance | html }}</code>{{ end }}
          {{ if .Labels.severity }}Уровень: {{ if eq .Labels.severity "critical" }}критично{{ else if eq .Labels.severity "warning" }}предупреждение{{ else }}{{ .Labels.severity | html }}{{ end }}{{ end }}
          {{ end }}
        send_resolved: true
EOF
else
  echo "Alertmanager: TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID is missing." >&2
fi

exec /bin/alertmanager --config.file="$CONFIG_PATH" --storage.path=/alertmanager
