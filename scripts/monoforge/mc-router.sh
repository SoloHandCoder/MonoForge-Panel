#!/usr/bin/env bash
# MonoForge shared Minecraft entrance: runs Gate (https://gate.minekube.com) in Lite mode on a
# node, so every Minecraft Java server on it is joined as <name>.<domain> through ONE public
# entrance (a playit.gg tunnel, or the game gateway VPS). Gate reads the host name from the
# Minecraft handshake and forwards the player to the server's local port.
#
# The routes come from the panel: a timer fetches them every minute with the node's daemon token,
# read from the Wings config (never put on a command line or printed), and writes Gate's config
# atomically. Gate reloads its config file by itself when it changes.
#
# Run with sudo:
#
#   sudo bash mc-router.sh install    installs Gate, the service and the sync timer, then syncs
#   sudo bash mc-router.sh update     installs the newest script and Gate version again
#   sudo bash mc-router.sh sync       fetches the routes now (the timer does this every minute)
#   sudo bash mc-router.sh status     the proxy, the last sync and the routes
#   sudo bash mc-router.sh remove     stops and removes the proxy (the panel keeps its settings)
#
# Options:
#   --wings-config PATH   the Wings config with the panel URL and daemon token
#                         (default: /etc/pterodactyl/config.yml)
#   --gate-version X.Y.Z  the Gate release to install (default below)
#   --gate-binary PATH    use this Gate binary instead of downloading one
#   --allow-http          let the panel URL be http:// (testing only)
set -euo pipefail

NAME=monoforge-mc-router
DIR=/etc/$NAME
CONFIG=$DIR/config.yml
STATE=$DIR/last-sync
BIN=/usr/local/bin/monoforge-gate
SELF=/usr/local/sbin/$NAME
WINGS_CONFIG=/etc/pterodactyl/config.yml
GATE_VERSION=0.42.2
GATE_BINARY=""
ALLOW_HTTP=0

die() { echo "Error: $*" >&2; exit 1; }
say() { echo "$*"; }

[ "$(id -u)" -eq 0 ] || die "run this with sudo."

command="${1:-}"
[ $# -gt 0 ] && shift
while [ $# -gt 0 ]; do
    case "$1" in
        --wings-config) WINGS_CONFIG="${2:-}"; shift 2 ;;
        --gate-version) GATE_VERSION="${2:-}"; shift 2 ;;
        --gate-binary) GATE_BINARY="${2:-}"; shift 2 ;;
        --allow-http) ALLOW_HTTP=1; shift ;;
        *) die "unknown option $1" ;;
    esac
done

[[ "$GATE_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "--gate-version must look like 0.42.2."

# The value of a top-level KEY in the Wings config, without quotes.
yaml_value() {
    local key=$1 file=$2 line
    line=$(grep -E "^${key}:[[:space:]]" "$file" 2>/dev/null | head -n 1 || true)
    line=${line#*:}
    printf '%s' "$line" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
}

TOKEN_ID=""
DAEMON_TOKEN=""
PANEL=""

load_wings_config() {
    [ -r "$WINGS_CONFIG" ] || die "cannot read the Wings config at $WINGS_CONFIG (pass --wings-config PATH)."
    TOKEN_ID=$(yaml_value token_id "$WINGS_CONFIG")
    DAEMON_TOKEN=$(yaml_value token "$WINGS_CONFIG")
    PANEL=$(yaml_value remote "$WINGS_CONFIG")
    PANEL=${PANEL%/}
    [ -n "$TOKEN_ID" ] && [ -n "$DAEMON_TOKEN" ] || die "the Wings config has no token_id/token."
    case "$PANEL" in
        https://*) ;;
        http://*) [ "$ALLOW_HTTP" -eq 1 ] || die "the panel URL must use https (or pass --allow-http)." ;;
        *) die "the Wings config has no https:// remote." ;;
    esac
}

# Fetches the routes into $1. The token goes to curl through a config on a pipe, so `ps` never
# shows it; -q keeps any .curlrc out. Prints the HTTP status.
fetch_routes() {
    local out=$1 proto='=https'
    [ "$ALLOW_HTTP" -eq 1 ] && proto='=https,http'
    curl -q -sS --proto "$proto" --max-time 20 -o "$out" -w '%{http_code}' \
        -H 'Accept: application/json' \
        -K <(printf 'header = "Authorization: Bearer %s.%s"\n' "$TOKEN_ID" "$DAEMON_TOKEN") \
        "$PANEL/api/remote/minecraft-routes" || echo 000
}

# The "gate" object of the panel's answer as JSON (which YAML reads as it is), or nothing when
# the entrance is off.
gate_config() {
    python3 - "$1" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as handle:
    body = json.load(handle)
gate = body.get("gate")
if body.get("enabled") and isinstance(gate, dict):
    print(json.dumps(gate, indent=2))
PY
}

sync_routes() {
    local tmp body code
    load_wings_config
    mkdir -p "$DIR"
    body=$(mktemp "$DIR/.answer.XXXXXX")
    code=$(fetch_routes "$body")
    if [ "$code" != "200" ]; then
        rm -f "$body"
        die "the panel answered HTTP $code for the routes (is this node's Wings token right?)."
    fi

    tmp=$(mktemp "$DIR/.config.XXXXXX")
    gate_config "$body" > "$tmp" || { rm -f "$body" "$tmp"; die "the panel's answer is not the expected JSON."; }
    rm -f "$body"

    if [ ! -s "$tmp" ]; then
        rm -f "$tmp"
        date -u +%FT%TZ > "$STATE"
        say "The shared Minecraft entrance is off for this node in the panel; the proxy config is left as it was."
        return 0
    fi

    chmod 644 "$tmp"
    if [ -f "$CONFIG" ] && cmp -s "$tmp" "$CONFIG"; then
        rm -f "$tmp"
    else
        # Same directory, so the rename is atomic: Gate never reads half a file.
        mv -f "$tmp" "$CONFIG"
        say "Routes updated."
    fi

    date -u +%FT%TZ > "$STATE"
    systemctl is-active --quiet "$NAME" || systemctl restart "$NAME"
}

gate_arch() {
    case "$(uname -m)" in
        x86_64|amd64) echo amd64 ;;
        aarch64|arm64) echo arm64 ;;
        *) die "unsupported CPU $(uname -m); pass --gate-binary PATH." ;;
    esac
}

install_gate() {
    if [ -n "$GATE_BINARY" ]; then
        [ -x "$GATE_BINARY" ] || die "$GATE_BINARY is not an executable."
        install -m 755 "$GATE_BINARY" "$BIN"
        return 0
    fi

    command -v curl >/dev/null || die "curl is needed."
    local arch base asset tmp sum
    arch=$(gate_arch)
    base="https://github.com/minekube/gate/releases/download/v$GATE_VERSION"
    asset="gate_${GATE_VERSION}_linux_${arch}"
    tmp=$(mktemp -d)
    say "Downloading Gate $GATE_VERSION ($arch) ..."
    curl -fsSL -o "$tmp/$asset" "$base/$asset" || { rm -rf "$tmp"; die "could not download $base/$asset (pass --gate-version or --gate-binary)."; }
    curl -fsSL -o "$tmp/checksums.txt" "$base/checksums.txt" || { rm -rf "$tmp"; die "could not download the Gate checksums."; }
    sum=$(grep -E "[[:space:]]\*?${asset}\$" "$tmp/checksums.txt" | awk '{print $1}' | head -n 1)
    [ -n "$sum" ] || { rm -rf "$tmp"; die "the Gate checksums do not list $asset."; }
    echo "$sum  $tmp/$asset" | sha256sum -c - >/dev/null || { rm -rf "$tmp"; die "the Gate download does not match its checksum."; }
    install -m 755 "$tmp/$asset" "$BIN"
    rm -rf "$tmp"
}

write_units() {
    cat > "/etc/systemd/system/$NAME.service" <<EOF
[Unit]
Description=MonoForge shared Minecraft entrance (Gate Lite)
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=$BIN --config $CONFIG
WorkingDirectory=$DIR
DynamicUser=yes
ReadOnlyPaths=$DIR
NoNewPrivileges=yes
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

    cat > "/etc/systemd/system/$NAME-sync.service" <<EOF
[Unit]
Description=Fetch the MonoForge Minecraft routes from the panel

[Service]
Type=oneshot
ExecStart=$SELF sync --wings-config $WINGS_CONFIG$([ "$ALLOW_HTTP" -eq 1 ] && echo " --allow-http")
EOF

    cat > "/etc/systemd/system/$NAME-sync.timer" <<EOF
[Unit]
Description=Fetch the MonoForge Minecraft routes every minute

[Timer]
OnBootSec=30s
OnUnitActiveSec=60s

[Install]
WantedBy=timers.target
EOF
}

case "$command" in
    install|update)
        command -v python3 >/dev/null || die "python3 is needed (apt-get install python3)."
        load_wings_config
        install_gate
        install -m 700 "$0" "$SELF"
        mkdir -p "$DIR"
        chmod 755 "$DIR"
        write_units
        systemctl daemon-reload
        "$SELF" sync --wings-config "$WINGS_CONFIG" $([ "$ALLOW_HTTP" -eq 1 ] && echo --allow-http) || true
        systemctl enable --now "$NAME-sync.timer" >/dev/null
        if [ -s "$CONFIG" ]; then
            systemctl enable "$NAME" >/dev/null 2>&1
            systemctl restart "$NAME"
            say "Gate runs with the panel's routes. Check it with: sudo bash mc-router.sh status"
        else
            say "Installed. Turn on the shared Minecraft entrance for this node in the panel; the timer picks it up within a minute."
        fi
        ;;

    sync)
        sync_routes
        ;;

    status)
        systemctl is-active --quiet "$NAME" && say "Gate: running" || say "Gate: NOT running (journalctl -u $NAME -n 30)"
        systemctl is-active --quiet "$NAME-sync.timer" && say "Route sync timer: on" || say "Route sync timer: off"
        [ -s "$STATE" ] && say "Last sync: $(cat "$STATE")" || say "Last sync: never"
        if [ -s "$CONFIG" ]; then
            say "Listening on: $(grep -m1 '"bind"' "$CONFIG" | sed 's/.*"bind": *"\([^"]*\)".*/\1/')"
            say "Routes:"
            grep -E '"(host|backend)"' "$CONFIG" | sed -e 's/^[[:space:]]*//' -e 's/,$//' | paste - - | sed 's/^/  /'
        fi
        ;;

    remove)
        systemctl disable --now "$NAME-sync.timer" "$NAME" >/dev/null 2>&1 || true
        rm -f "/etc/systemd/system/$NAME.service" "/etc/systemd/system/$NAME-sync.service" "/etc/systemd/system/$NAME-sync.timer"
        systemctl daemon-reload
        rm -rf "$DIR" "$BIN" "$SELF"
        say "The Minecraft router is removed from this node."
        ;;

    *)
        sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'
        exit 1
        ;;
esac
