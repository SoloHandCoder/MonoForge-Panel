#!/usr/bin/env bash
# Installs the queue worker that runs MonoForge AI assistant turns.
#
#   sudo bash install-assistant-worker.sh            install or update, then start
#   sudo bash install-assistant-worker.sh --remove   stop and remove it
#
# Assistant turns run on their own "assistant" queue connection, whose retry_after
# (1080 s) is above a turn's 17-minute timeout (15 minutes of work, 2 waiting for the lock). The normal monoforge-queue worker must
# not pick them up, so this is a separate service.
set -euo pipefail

PANEL=${PANEL:-/var/www/monoforge}
UNIT=/etc/systemd/system/monoforge-assistant-queue.service

[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }

if [ "${1:-}" = "--remove" ]; then
    systemctl disable --now monoforge-assistant-queue 2>/dev/null || true
    rm -f "$UNIT"
    systemctl daemon-reload
    echo "Removed the assistant worker."
    exit 0
fi

cat > "$UNIT" <<UNITFILE
[Unit]
Description=MonoForge Panel AI assistant queue worker
After=redis-server.service mariadb.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=$PANEL
ExecStart=/usr/bin/php $PANEL/artisan queue:work assistant --queue=assistant --sleep=3 --tries=1 --timeout=1050
Restart=always
RestartSec=5s

[Install]
WantedBy=multi-user.target
UNITFILE

systemctl daemon-reload
systemctl enable --now monoforge-assistant-queue
sleep 2
if systemctl is-active --quiet monoforge-assistant-queue; then
    echo "The assistant worker is running (monoforge-assistant-queue)."
else
    echo "The assistant worker did not start. Check: journalctl -u monoforge-assistant-queue -n 50"
    exit 1
fi
