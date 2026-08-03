#!/usr/bin/env bash
set -euo pipefail

KEY="/Users/integrator/.ssh/clever_prod_codex"
PLIST="/Library/LaunchDaemons/com.clevercrm.local-tunnel.plist"
HOSTS=(
  "clevercrm.pro"
  "www.clevercrm.pro"
  "app.clevercrm.pro"
  "back.clevercrm.pro"
  "n8n.clevercrm.pro"
  "grafana.clevercrm.pro"
  "prometheus.clevercrm.pro"
  "alerts.clevercrm.pro"
)

if [[ ! -f "$KEY" ]]; then
  echo "SSH key not found: $KEY" >&2
  exit 1
fi

echo "Updating /etc/hosts..."
sudo perl -0pi -e 's/^(127\.0\.0\.1|45\.12\.74\.216)\s+(clevercrm\.pro|www\.clevercrm\.pro|app\.clevercrm\.pro|back\.clevercrm\.pro|n8n\.clevercrm\.pro|grafana\.clevercrm\.pro|prometheus\.clevercrm\.pro|alerts\.clevercrm\.pro)\n//mg' /etc/hosts
for host in "${HOSTS[@]}"; do
  echo "127.0.0.1 ${host}" | sudo tee -a /etc/hosts >/dev/null
done

echo "Installing launch daemon..."
sudo tee "$PLIST" >/dev/null <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key>
  <string>com.clevercrm.local-tunnel</string>
  <key>ProgramArguments</key>
  <array>
    <string>/usr/bin/ssh</string>
    <string>-N</string>
    <string>-o</string>
    <string>ExitOnForwardFailure=yes</string>
    <string>-o</string>
    <string>ServerAliveInterval=30</string>
    <string>-o</string>
    <string>ServerAliveCountMax=3</string>
    <string>-o</string>
    <string>StrictHostKeyChecking=no</string>
    <string>-o</string>
    <string>UserKnownHostsFile=/dev/null</string>
    <string>-o</string>
    <string>IdentitiesOnly=yes</string>
    <string>-i</string>
    <string>${KEY}</string>
    <string>-L</string>
    <string>127.0.0.1:443:127.0.0.1:443</string>
    <string>root@45.12.74.216</string>
  </array>
  <key>RunAtLoad</key>
  <true/>
  <key>KeepAlive</key>
  <true/>
  <key>StandardOutPath</key>
  <string>/var/log/clevercrm-local-tunnel.log</string>
  <key>StandardErrorPath</key>
  <string>/var/log/clevercrm-local-tunnel.err.log</string>
</dict>
</plist>
PLIST

sudo chown root:wheel "$PLIST"
sudo chmod 644 "$PLIST"

echo "Restarting tunnel daemon..."
sudo launchctl bootout system "$PLIST" 2>/dev/null || true
sudo pkill -f 'ssh .*127\.0\.0\.1:443:127\.0\.0\.1:443' 2>/dev/null || true
sudo launchctl bootstrap system "$PLIST"
sudo launchctl kickstart -k system/com.clevercrm.local-tunnel

echo "Flushing DNS cache..."
sudo dscacheutil -flushcache
sudo killall -HUP mDNSResponder

echo "Waiting for local 443..."
for _ in {1..10}; do
  if lsof -nP -iTCP:443 -sTCP:LISTEN | grep -q '127.0.0.1:443'; then
    break
  fi
  sleep 1
done

echo "Checking services..."
for host in app.clevercrm.pro grafana.clevercrm.pro n8n.clevercrm.pro back.clevercrm.pro; do
  printf '%s: ' "$host"
  curl -Ik --connect-timeout 3 --max-time 10 "https://${host}" 2>/dev/null | head -n 1 || true
done

echo "Done."
