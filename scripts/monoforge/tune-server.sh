#!/usr/bin/env bash
# MonoForge Panel server tuning for a small multi-core host with spare RAM.
# Trades memory for CPU: more PHP-FPM workers (no process spawning), a bigger OPcache,
# the phpredis C extension instead of pure-PHP predis, Redis for cache + sessions,
# long-lived caching and gzip for hashed build assets, and Laravel's config/route/view caches.
#
#   sudo bash tune-server.sh                 apply (backs everything up first, rolls back if the site breaks)
#   sudo bash tune-server.sh --rollback DIR  restore a backup made by a previous run
#
# Note: moving sessions to Redis signs everyone out once.
set -euo pipefail

PANEL=${PANEL:-/var/www/monoforge}
PHPV=${PHPV:-8.3}
WORKERS=${WORKERS:-16}
POOL=/etc/php/$PHPV/fpm/pool.d/www.conf
OPCACHE_INI=/etc/php/$PHPV/fpm/conf.d/99-monoforge.ini
SITE=/etc/nginx/sites-available/monoforge.conf
[ -f "$SITE" ] || SITE=$(readlink -f /etc/nginx/sites-enabled/monoforge.conf)
SNIPPET=/etc/nginx/snippets/monoforge-perf.conf

[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }

restart_all() {
    systemctl restart "php$PHPV-fpm"
    nginx -t >/dev/null 2>&1 && systemctl reload nginx
    systemctl restart monoforge-queue 2>/dev/null || true
}

restore() {
    local dir=$1
    echo "Restoring from $dir"
    cp -a "$dir/www.conf" "$POOL"
    cp -a "$dir/monoforge.conf" "$SITE"
    cp -a "$dir/.env" "$PANEL/.env"
    if [ -f "$dir/99-monoforge.ini" ]; then cp -a "$dir/99-monoforge.ini" "$OPCACHE_INI"; else rm -f "$OPCACHE_INI"; fi
    if [ -f "$dir/monoforge-perf.conf" ]; then cp -a "$dir/monoforge-perf.conf" "$SNIPPET"; else rm -f "$SNIPPET"; fi
    sudo -u www-data php "$PANEL/artisan" optimize:clear >/dev/null 2>&1 || true
    restart_all
    echo "Restored."
}

if [ "${1:-}" = "--rollback" ]; then
    [ -d "${2:-}" ] || { echo "Usage: --rollback /var/backups/monoforge-tune-<time>"; exit 1; }
    restore "$2"
    exit 0
fi

check_site() {
    local code
    for path in /auth/login /api/notices/public; do
        code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1$path" || echo 000)
        [ "$code" = 200 ] || { echo "  $path -> HTTP $code"; return 1; }
    done
}

BACKUP=/var/backups/monoforge-tune-$(date +%Y%m%d-%H%M%S)
mkdir -p "$BACKUP"
chmod 700 "$BACKUP"
cp -a "$POOL" "$SITE" "$PANEL/.env" "$BACKUP/"
[ -f "$OPCACHE_INI" ] && cp -a "$OPCACHE_INI" "$BACKUP/"
[ -f "$SNIPPET" ] && cp -a "$SNIPPET" "$BACKUP/"
echo "Backup: $BACKUP"
trap 'echo "Something failed - rolling back."; restore "$BACKUP"; exit 1' ERR

echo "1/6 phpredis extension"
if ! php -m | grep -qi '^redis$'; then
    apt-get install -y -q "php$PHPV-redis" >/dev/null 2>&1 || { apt-get update -q >/dev/null; apt-get install -y -q "php$PHPV-redis" >/dev/null; }
fi

echo "2/6 PHP-FPM: static pool of $WORKERS workers"
sed -i -E \
    -e "s/^;?pm = .*/pm = static/" \
    -e "s/^;?pm\.max_children = .*/pm.max_children = $WORKERS/" \
    -e "s/^;?pm\.max_requests = .*/pm.max_requests = 1000/" \
    "$POOL"
grep -q '^pm.max_requests' "$POOL" || echo 'pm.max_requests = 1000' >> "$POOL"

echo "3/6 OPcache and realpath cache"
cat > "$OPCACHE_INI" <<'INI'
; MonoForge tuning: keep all compiled PHP in memory.
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=30000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
realpath_cache_size=4096K
realpath_cache_ttl=600
INI

echo "4/6 nginx: sendfile, gzip, immutable caching for hashed assets"
cat > "$SNIPPET" <<'NGINX'
# MonoForge tuning (included inside the server block).
gzip on;
gzip_vary on;
gzip_min_length 1024;
gzip_types text/css application/javascript application/json image/svg+xml text/plain;
location ~* "^/assets/.+\.[A-Za-z0-9_-]{8}\.(js|css|woff2?|svg|png|webp)$" {
    expires 1y;
    add_header Cache-Control "public, max-age=31536000, immutable";
    access_log off;
    try_files $uri =404;
}
NGINX
sed -i -E 's/^(\s*)sendfile off;/\1sendfile on;/' "$SITE"
sed -i -E 's/^(\s*)fastcgi_buffers 4 16k;/\1fastcgi_buffers 16 16k;/' "$SITE"
grep -q 'snippets/monoforge-perf.conf' "$SITE" || perl -0pi -e 's{^(\s*)location / \{}{$1include /etc/nginx/snippets/monoforge-perf.conf;\n$1location / \{}m' "$SITE"
nginx -t

echo "5/6 Laravel: Redis for cache, sessions and queue (phpredis)"
if redis-cli ping 2>/dev/null | grep -q PONG; then
    for kv in CACHE_STORE=redis CACHE_DRIVER=redis SESSION_DRIVER=redis QUEUE_CONNECTION=redis REDIS_CLIENT=phpredis; do
        key=${kv%%=*}
        if grep -q "^$key=" "$PANEL/.env"; then
            sed -i "s/^$key=.*/$kv/" "$PANEL/.env"
        else
            echo "$kv" >> "$PANEL/.env"
        fi
    done
else
    echo "  Redis is not answering; leaving cache/session drivers unchanged."
fi

echo "6/6 Restart services and build Laravel caches"
"php-fpm$PHPV" -t 2>/dev/null
restart_all
sudo -u www-data php "$PANEL/artisan" optimize:clear >/dev/null
sudo -u www-data php "$PANEL/artisan" optimize >/dev/null || {
    echo "  Laravel caching failed; continuing without it."
    sudo -u www-data php "$PANEL/artisan" optimize:clear >/dev/null
}
systemctl restart monoforge-queue 2>/dev/null || true

sleep 2
if ! check_site; then
    echo "The site check failed after tuning."
    false
fi
trap - ERR
echo "Done. The panel answers normally. Backup kept in $BACKUP (undo: sudo bash $0 --rollback $BACKUP)."
