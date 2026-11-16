#!/usr/bin/env bash
# Installs the MonoForge queue workers that run outside the normal monoforge-queue worker:
#
#   monoforge-assistant-queue  AI assistant turns (queue:work assistant --queue=assistant)
#   monoforge-installs-queue   plugin, mod and modpack installs and installed-jar scans
#                              (queue:work installs --queue=installs)
#
#   sudo bash install-assistant-worker.sh            install or update both, then (re)start them
#   sudo bash install-assistant-worker.sh --remove   stop and remove both
#
# Safe to run again: the unit files are rewritten and the workers restarted with them.
#
# Assistant turns run on their own "assistant" queue connection, whose retry_after (1080 s) is
# above a turn's 17-minute timeout (15 minutes of work, 2 waiting for the lock). Installs run on
# the "installs" connection, whose retry_after (1080 s) is above the longest install job
# (InstallModpackJob, 1000 s). The normal monoforge-queue worker must not pick up either, so
# each has its own service; each runs one job at a time.
set -euo pipefail

PANEL=${PANEL:-/var/www/monoforge}
UNITS=(monoforge-assistant-queue monoforge-installs-queue)

[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }

if [ "${1:-}" = "--remove" ]; then
    for unit in "${UNITS[@]}"; do
        systemctl disable --now "$unit" 2>/dev/null || true
        rm -f "/etc/systemd/system/$unit.service"
    done
    systemctl daemon-reload
    echo "Removed the assistant and installs workers."
    exit 0
fi

# write_unit <name> <description> <queue:work arguments>
write_unit() {
    cat > "/etc/systemd/system/$1.service" <<UNITFILE
[Unit]
Description=$2
After=redis-server.service mariadb.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=$PANEL
ExecStart=/usr/bin/php $PANEL/artisan queue:work $3
Restart=always
RestartSec=5s

[Install]
WantedBy=multi-user.target
UNITFILE
}

write_unit monoforge-assistant-queue "MonoForge Panel AI assistant queue worker" "assistant --queue=assistant --sleep=3 --tries=1 --timeout=1050"
# --tries=1 is the default; the modpack job sets its own $tries so it can re-queue itself while
# its backup runs. A job's own timeout (at most 1000 s) stays under --timeout and retry_after.
write_unit monoforge-installs-queue "MonoForge Panel plugin and modpack install worker" "installs --queue=installs --sleep=3 --tries=1 --timeout=1050"

systemctl daemon-reload
failed=0
for unit in "${UNITS[@]}"; do
    systemctl enable "$unit" >/dev/null 2>&1
    systemctl restart "$unit"
done
sleep 2
for unit in "${UNITS[@]}"; do
    if systemctl is-active --quiet "$unit"; then
        echo "The worker is running ($unit)."
    else
        echo "The worker did not start. Check: journalctl -u $unit -n 50"
        failed=1
    fi
done
exit $failed
