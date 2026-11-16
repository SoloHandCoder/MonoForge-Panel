#!/usr/bin/env bash
# MonoForge Panel: switch this node's playit.gg agent to the self-managed agent the panel set up
# ("Set up automatic tunnels" on the node's playit card), so the panel can create tunnels itself.
#
#   sudo bash playit-switch-agent.sh --panel https://panel.example.com
#       Asks for the one-time token shown in the panel (hidden prompt), fetches the new agent
#       secret from the panel once, backs up the current playit config, points the running
#       playit agent at the new secret, restarts it, checks it is online and tells the panel.
#       If anything fails after the config changed, the old config is put back automatically.
#
#   sudo bash playit-switch-agent.sh --rollback [BACKUP_DIR]
#       Puts back the config from a backup (the latest one by default), restarts playit and,
#       when given the switch token, tells the panel to go back to the old agent too.
#
# Options:
#   --panel URL           the panel URL (default: "remote" from the Wings config)
#   --wings-config PATH   the Wings config with the node's daemon token
#                         (default: /etc/pterodactyl/config.yml)
#   --manual-secret       paste the agent secret into a hidden prompt instead of fetching it
#                         (the panel is not told; finish the switch in the panel by hand)
#   --secret-path PATH    the playit secret file, when it cannot be detected
#   --dry-run             only show what was detected; change nothing
#   -y, --yes             do not ask before switching or rolling back
#
# The secret is never printed, logged, passed on a command line or written anywhere but the
# playit secret file. The token is read from a hidden prompt, so it is not in shell history.
# Supported setups: playitd as a systemd service (--secret-path, as the official package
# installs it) and the playit CLI running in screen (or on its own) with its secret in
# ~/.config/playit_gg/playit.toml.
set -euo pipefail
set +x
umask 077
ulimit -c 0 2>/dev/null || true

BACKUP_ROOT=/var/backups/monoforge-playit
WINGS_CONFIG=/etc/pterodactyl/config.yml
PANEL=""
MODE_ROLLBACK=0
ROLLBACK_DIR=""
MANUAL_SECRET=0
SECRET_PATH_OVERRIDE=""
DRY_RUN=0
ASSUME_YES=0
ALLOW_HTTP=0
PLAYIT_API=https://api.playit.gg

say() { printf '%s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

usage() { sed -n '2,29p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }

while [ $# -gt 0 ]; do
    case "$1" in
        --panel) PANEL=${2:-}; shift 2 ;;
        --panel=*) PANEL=${1#*=}; shift ;;
        --wings-config) WINGS_CONFIG=${2:-}; shift 2 ;;
        --wings-config=*) WINGS_CONFIG=${1#*=}; shift ;;
        --rollback)
            MODE_ROLLBACK=1; shift
            if [ $# -gt 0 ] && [ "${1#-}" = "$1" ]; then ROLLBACK_DIR=$1; shift; fi ;;
        --rollback=*) MODE_ROLLBACK=1; ROLLBACK_DIR=${1#*=}; shift ;;
        --manual-secret) MANUAL_SECRET=1; shift ;;
        --secret-path) SECRET_PATH_OVERRIDE=${2:-}; shift 2 ;;
        --secret-path=*) SECRET_PATH_OVERRIDE=${1#*=}; shift ;;
        --dry-run) DRY_RUN=1; shift ;;
        --allow-http) ALLOW_HTTP=1; shift ;;
        -y|--yes) ASSUME_YES=1; shift ;;
        -h|--help) usage 0 ;;
        *) warn "Unknown option: $1"; usage 1 ;;
    esac
done

[ "$(id -u)" -eq 0 ] || die "Run with sudo."
command -v curl >/dev/null 2>&1 || die "curl is required."

confirm() {
    [ "$ASSUME_YES" -eq 1 ] && return 0
    local answer
    read -r -p "$1 [y/N] " answer </dev/tty || return 1
    case "$answer" in y|Y|yes|YES) return 0 ;; *) return 1 ;; esac
}

# Reads a value without echoing it, from the terminal, into the variable named by $2.
read_hidden() {
    local prompt=$1 __value=""
    [ -r /dev/tty ] || die "No terminal to read the ${prompt%%:*} from."
    read -r -s -p "$prompt " __value </dev/tty || true
    printf '\n' >/dev/tty
    printf -v "$2" '%s' "$__value"
}

# The value of a top-level "key: value" line of a YAML file, without quotes.
yaml_value() {
    local key=$1 file=$2 line
    line=$(grep -E "^${key}:[[:space:]]" "$file" | head -n 1 || true)
    line=${line#*:}
    line=$(printf '%s' "$line" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/")
    printf '%s' "$line"
}

# One JSON string field of a flat JSON body (the panel's and playit's answers are compact).
json_field() {
    printf '%s' "$2" | sed -n "s/.*\"$1\":\"\([^\"]*\)\".*/\1/p" | head -n 1
}

json_number() {
    printf '%s' "$2" | sed -n "s/.*\"$1\":\([0-9][0-9]*\).*/\1/p" | head -n 1
}

# ---------------------------------------------------------------------------------------------
# Talking to the panel and to playit. Secrets go into curl through a config on a pipe, never on
# the command line (where `ps` would show them).
# ---------------------------------------------------------------------------------------------
TOKEN_ID=""
DAEMON_TOKEN=""
SWITCH_TOKEN=""
HTTP_BODY=""
HTTP_CODE=""

load_wings_config() {
    [ -r "$WINGS_CONFIG" ] || die "Cannot read the Wings config at $WINGS_CONFIG. Pass --wings-config PATH."
    TOKEN_ID=$(yaml_value token_id "$WINGS_CONFIG")
    DAEMON_TOKEN=$(yaml_value token "$WINGS_CONFIG")
    [ -n "$TOKEN_ID" ] && [ -n "$DAEMON_TOKEN" ] || die "The Wings config has no token_id/token."
    if [ -z "$PANEL" ]; then
        PANEL=$(yaml_value remote "$WINGS_CONFIG")
    fi
    PANEL=${PANEL%/}
    [ -n "$PANEL" ] || die "Pass --panel https://your-panel."
    case "$PANEL" in
        https://*) ;;
        http://*) [ "$ALLOW_HTTP" -eq 1 ] || die "The panel URL must use https (or pass --allow-http)." ;;
        *) die "The panel URL must start with https://." ;;
    esac
}

# panel_post PATH JSON_BODY -> HTTP_CODE, HTTP_BODY
panel_post() {
    local path=$1 body=$2 out
    out=$(curl -sS --proto '=https,http' --max-time 30 -X POST \
        -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -K <(printf 'header = "Authorization: Bearer %s.%s"\ndata = "%s"\n' "$TOKEN_ID" "$DAEMON_TOKEN" "${body//\"/\\\"}") \
        -w '\n%{http_code}' "$PANEL$path") || { HTTP_CODE=000; HTTP_BODY=""; return 0; }
    HTTP_CODE=${out##*$'\n'}
    HTTP_BODY=${out%$'\n'*}
}

# The agent id playit returns for a key, from the agent's run data, or empty.
playit_agent_id() {
    local key=$1 out
    out=$(curl -sS --proto '=https' --max-time 20 -X POST \
        -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -K <(printf 'header = "Authorization: Agent-Key %s"\n' "$key") \
        --data '{}' "$PLAYIT_API/v1/agents/rundata" 2>/dev/null) || return 0
    case "$out" in *'"status":"success"'*) json_field agent_id "$out" | tr 'A-F' 'a-f' ;; esac
}

# ---------------------------------------------------------------------------------------------
# Detecting how playit runs on this node.
# ---------------------------------------------------------------------------------------------
MODE=""          # systemd | screen | process
UNIT=""
RUN_USER=root
RUN_CWD=/
SECRET_PATH=""
SCREEN_PID=""
PLAYIT_PID=""
ARGV=()

cmdline_of() {
    local pid=$1
    ARGV=()
    while IFS= read -r -d '' arg; do ARGV+=("$arg"); done <"/proc/$pid/cmdline"
}

# The value of --name VALUE or --name=VALUE in ARGV.
arg_value() {
    local name i
    for name in "$@"; do
        for ((i = 0; i < ${#ARGV[@]}; i++)); do
            case "${ARGV[$i]}" in
                "$name") printf '%s' "${ARGV[$((i + 1))]:-}"; return 0 ;;
                "$name"=*) printf '%s' "${ARGV[$i]#*=}"; return 0 ;;
            esac
        done
    done
    return 1
}

unit_of() {
    grep -o '[^/]*\.service' "/proc/$1/cgroup" 2>/dev/null | tail -n 1 || true
}

screen_ancestor() {
    local pid=$1 comm ppid
    while [ -n "$pid" ] && [ "$pid" -gt 1 ]; do
        comm=$(cat "/proc/$pid/comm" 2>/dev/null || true)
        case "$comm" in screen|SCREEN) printf '%s' "$pid"; return 0 ;; esac
        ppid=$(awk '/^PPid:/ {print $2}' "/proc/$pid/status" 2>/dev/null || true)
        pid=$ppid
    done
    return 1
}

detect() {
    local pid home
    pid=$(pgrep -x playitd | head -n 1 || true)
    if [ -n "$pid" ]; then
        PLAYIT_PID=$pid
        cmdline_of "$pid"
        if arg_value --secret >/dev/null; then
            die "playitd runs with --secret on its command line; give it a --secret-path file first."
        fi
        SECRET_PATH=$(arg_value --secret-path || true)
        [ -n "$SECRET_PATH" ] || SECRET_PATH=/etc/playit/playit.toml
        UNIT=$(unit_of "$pid")
        RUN_USER=$(stat -c %U "/proc/$pid")
        [ -n "$UNIT" ] || die "playitd (pid $pid) does not run as a systemd service; this script only restarts playitd through systemd."
        MODE=systemd
    else
        pid=$(pgrep -x playit | head -n 1 || true)
        [ -n "$pid" ] || die "No running playit agent (playitd or playit) was found. Start it first."
        PLAYIT_PID=$pid
        cmdline_of "$pid"
        RUN_USER=$(stat -c %U "/proc/$pid")
        RUN_CWD=$(readlink "/proc/$pid/cwd" || printf '/')
        if arg_value --secret >/dev/null; then
            die "playit runs with --secret on its command line; move the secret into a file (--secret_path) first."
        fi
        SECRET_PATH=$(arg_value --secret_path --secret-path || true)
        if [ -z "$SECRET_PATH" ]; then
            home=$(getent passwd "$RUN_USER" | cut -d: -f6)
            SECRET_PATH=$home/.config/playit_gg/playit.toml
        fi
        case "$SECRET_PATH" in /*) ;; *) SECRET_PATH=$RUN_CWD/$SECRET_PATH ;; esac
        UNIT=$(unit_of "$pid")
        if [ -n "$UNIT" ] && [ "$UNIT" != "user@$(id -u "$RUN_USER").service" ] && ! screen_ancestor "$pid" >/dev/null; then
            MODE=systemd
        elif SCREEN_PID=$(screen_ancestor "$pid"); then
            MODE=screen
        else
            MODE=process
        fi
    fi
    [ -z "$SECRET_PATH_OVERRIDE" ] || SECRET_PATH=$SECRET_PATH_OVERRIDE
}

describe() {
    say "playit agent:  pid $PLAYIT_PID, user $RUN_USER, $(printf '%q ' "${ARGV[@]}")"
    case "$MODE" in
        systemd) say "Runs as:       systemd service $UNIT" ;;
        screen) say "Runs as:       screen session (screen pid $SCREEN_PID)" ;;
        process) say "Runs as:       a plain process (restarted with nohup)" ;;
    esac
    say "Secret file:   $SECRET_PATH $( [ -f "$SECRET_PATH" ] && printf '(exists)' || printf '(missing)')"
}

# ---------------------------------------------------------------------------------------------
# Changing, restarting and checking the agent.
# ---------------------------------------------------------------------------------------------
write_secret() {
    local secret=$1 dir tmp owner group mode
    dir=$(dirname "$SECRET_PATH")
    mkdir -p "$dir"
    tmp=$(mktemp "$dir/.playit.toml.monoforge.XXXXXX")
    if [ -f "$SECRET_PATH" ] && grep -qE '^[[:space:]]*secret_key[[:space:]]*=' "$SECRET_PATH"; then
        # Keep any other settings in the file; only the key changes.
        while IFS= read -r line || [ -n "$line" ]; do
            case "$line" in
                *secret_key*=*) printf 'secret_key = "%s"\n' "$secret" ;;
                *) printf '%s\n' "$line" ;;
            esac
        done <"$SECRET_PATH" >"$tmp"
    else
        printf 'secret_key = "%s"\n' "$secret" >"$tmp"
    fi
    if [ -f "$SECRET_PATH" ]; then
        owner=$(stat -c %U "$SECRET_PATH"); group=$(stat -c %G "$SECRET_PATH"); mode=$(stat -c %a "$SECRET_PATH")
    else
        owner=$RUN_USER; group=$(id -gn "$RUN_USER"); mode=600
    fi
    chown "$owner:$group" "$tmp"
    chmod "$mode" "$tmp"
    mv -f "$tmp" "$SECRET_PATH"
}

stop_cli() {
    local pid=$1 i
    kill -TERM "$pid" 2>/dev/null || return 0
    for i in $(seq 1 20); do
        kill -0 "$pid" 2>/dev/null || return 0
        sleep 0.5
    done
    kill -KILL "$pid" 2>/dev/null || true
}

restart_agent() {
    local cmd
    case "$MODE" in
        systemd)
            systemctl restart "$UNIT"
            ;;
        screen)
            stop_cli "$PLAYIT_PID"
            cmd=$(printf '%q ' "${ARGV[@]}")
            sleep 1
            if [ -n "$SCREEN_PID" ] && [ "$SCREEN_PID" != 0 ] && kill -0 "$SCREEN_PID" 2>/dev/null; then
                # The session is still there (playit ran from a shell in it): open a new window.
                sudo -u "$RUN_USER" -H bash -c "cd $(printf '%q' "$RUN_CWD") && screen -S $SCREEN_PID -X screen -t playit $cmd"
            else
                sudo -u "$RUN_USER" -H bash -c "cd $(printf '%q' "$RUN_CWD") && screen -dmS playit $cmd"
            fi
            ;;
        process)
            stop_cli "$PLAYIT_PID"
            cmd=$(printf '%q ' "${ARGV[@]}")
            sudo -u "$RUN_USER" -H bash -c "cd $(printf '%q' "$RUN_CWD") && setsid nohup $cmd >/dev/null 2>&1 < /dev/null &"
            ;;
    esac
}

# Whether the agent process is up again and stays up for a few seconds.
agent_running() {
    local i name pid
    name=$(basename "${ARGV[0]}")
    for i in $(seq 1 30); do
        if [ "$MODE" = systemd ]; then
            systemctl is-active --quiet "$UNIT" && pid=$(pgrep -x "$name" -u "$RUN_USER" | head -n 1 || true) || pid=""
        else
            pid=$(pgrep -x "$name" -u "$RUN_USER" | head -n 1 || true)
        fi
        if [ -n "$pid" ] && [ "$pid" != "$PLAYIT_PID" ]; then
            sleep 5
            kill -0 "$pid" 2>/dev/null && { PLAYIT_PID=$pid; return 0; }
        fi
        sleep 1
    done
    return 1
}

# ---------------------------------------------------------------------------------------------
# Backups.
# ---------------------------------------------------------------------------------------------
BACKUP_DIR=""

backup() {
    BACKUP_DIR=$BACKUP_ROOT/$(date +%Y%m%d-%H%M%S)
    mkdir -p "$BACKUP_DIR"
    chmod 700 "$BACKUP_ROOT" "$BACKUP_DIR"
    if [ -f "$SECRET_PATH" ]; then
        cp -a "$SECRET_PATH" "$BACKUP_DIR/playit.toml"
        printf '1' >"$BACKUP_DIR/had_secret"
    else
        printf '0' >"$BACKUP_DIR/had_secret"
    fi
    {
        printf 'MODE=%q\n' "$MODE"
        printf 'UNIT=%q\n' "$UNIT"
        printf 'RUN_USER=%q\n' "$RUN_USER"
        printf 'RUN_CWD=%q\n' "$RUN_CWD"
        printf 'SECRET_PATH=%q\n' "$SECRET_PATH"
        printf 'ARGV=('; printf '%q ' "${ARGV[@]}"; printf ')\n'
    } >"$BACKUP_DIR/state.env"
    ln -sfn "$BACKUP_DIR" "$BACKUP_ROOT/latest"
    say "Backed up the playit config to $BACKUP_DIR"
}

restore() {
    local dir=$1 pid name
    [ -f "$dir/state.env" ] || die "$dir is not a backup made by this script."
    # shellcheck disable=SC1091
    . "$dir/state.env"
    if [ "$(cat "$dir/had_secret")" = 1 ]; then
        cp -a "$dir/playit.toml" "$SECRET_PATH"
    else
        rm -f "$SECRET_PATH"
    fi
    name=$(basename "${ARGV[0]}")
    pid=$(pgrep -x "$name" -u "$RUN_USER" | head -n 1 || true)
    PLAYIT_PID=${pid:-0}
    if [ "$MODE" = screen ]; then
        SCREEN_PID=""
        if [ -n "$pid" ]; then SCREEN_PID=$(screen_ancestor "$pid" || true); fi
        [ -n "$SCREEN_PID" ] || SCREEN_PID=0
    fi
    if [ "$PLAYIT_PID" = 0 ] && [ "$MODE" != systemd ]; then
        # Nothing to stop: just start it again.
        PLAYIT_PID=999999999
    fi
    restart_agent
    if agent_running; then
        say "Restored the old playit config from $dir and restarted playit."
    else
        warn "Restored the old playit config from $dir, but playit did not come back up. Check it by hand."
        return 1
    fi
}

# ---------------------------------------------------------------------------------------------
# Rollback mode.
# ---------------------------------------------------------------------------------------------
if [ "$MODE_ROLLBACK" -eq 1 ]; then
    dir=${ROLLBACK_DIR:-$BACKUP_ROOT/latest}
    dir=$(readlink -f "$dir" || true)
    [ -n "$dir" ] && [ -d "$dir" ] || die "No backup found. Pass --rollback DIR (backups are in $BACKUP_ROOT)."
    say "Rolling back to $dir"
    confirm "Restore this backup and restart playit?" || die "Nothing changed."
    restore "$dir" || true
    if [ -r "$WINGS_CONFIG" ]; then
        read_hidden "Paste the switch token to tell the panel (or press Enter to skip):" SWITCH_TOKEN
        if [ -n "$SWITCH_TOKEN" ]; then
            load_wings_config
            panel_post /api/remote/playit/agent-switch "{\"token\":\"$SWITCH_TOKEN\",\"outcome\":\"rolled_back\"}"
            if [ "$HTTP_CODE" = 200 ]; then
                say "The panel is back on the previous agent."
            else
                warn "The panel did not accept the report (HTTP $HTTP_CODE). Click \"Undo switch\" on the node's playit card."
            fi
        else
            say "Click \"Undo switch\" on the node's playit card so the panel uses the old agent again."
        fi
    fi
    exit 0
fi

# ---------------------------------------------------------------------------------------------
# Switch mode.
# ---------------------------------------------------------------------------------------------
detect
describe
if [ "$DRY_RUN" -eq 1 ]; then
    say "Dry run: nothing changed."
    exit 0
fi

NEW_SECRET=""
NEW_AGENT=""
if [ "$MANUAL_SECRET" -eq 1 ]; then
    read_hidden "Paste the new agent secret (hidden):" NEW_SECRET
else
    load_wings_config
    read_hidden "Paste the one-time switch token from the panel (hidden):" SWITCH_TOKEN
    printf '%s' "$SWITCH_TOKEN" | grep -qE '^[0-9a-f]{48}$' || die "That is not a switch token. Copy it from the node's playit card."
    say "Fetching the new agent secret from $PANEL ..."
    panel_post /api/remote/playit/agent-secret "{\"token\":\"$SWITCH_TOKEN\"}"
    case "$HTTP_CODE" in
        200) ;;
        404) die "The panel says the token is unknown, expired or already used. Create a new one on the node's playit card." ;;
        401|403) die "The panel did not accept this node's daemon token (from $WINGS_CONFIG)." ;;
        000) die "Could not reach the panel at $PANEL." ;;
        *) die "The panel answered HTTP $HTTP_CODE." ;;
    esac
    NEW_SECRET=$(json_field secret_key "$HTTP_BODY")
    NEW_AGENT=$(json_field agent_id "$HTTP_BODY")
    HTTP_BODY=""
fi
printf '%s' "$NEW_SECRET" | grep -qE '^[0-9a-fA-F]{16,512}$' || die "The agent secret does not look like a playit key."

OLD_AGENT=""
if [ -f "$SECRET_PATH" ]; then
    old_secret=$(sed -n 's/^[[:space:]]*secret_key[[:space:]]*=[[:space:]]*["'"'"']\{0,1\}\([0-9a-fA-F]*\).*/\1/p' "$SECRET_PATH" | head -n 1)
    [ -z "$old_secret" ] || OLD_AGENT=$(playit_agent_id "$old_secret")
    old_secret=""
fi
CHECK_AGENT=$(playit_agent_id "$NEW_SECRET")
[ -n "$CHECK_AGENT" ] || die "playit.gg did not accept the new secret. Nothing changed."
if [ -n "$NEW_AGENT" ] && [ "$CHECK_AGENT" != "$NEW_AGENT" ]; then
    die "The new secret belongs to agent $CHECK_AGENT, not $NEW_AGENT as the panel says. Nothing changed."
fi
say "New agent:     $CHECK_AGENT"
[ -z "$OLD_AGENT" ] || say "Current agent: $OLD_AGENT"
if [ -n "$OLD_AGENT" ] && [ "$OLD_AGENT" = "$CHECK_AGENT" ]; then
    say "playit already runs with the new agent secret."
fi

confirm "Switch playit on this node to the new agent now? Its old tunnels stop working." || die "Nothing changed."

backup
ROLLBACK_NEEDED=1
on_exit() {
    local status=$?
    NEW_SECRET=""
    if [ "${ROLLBACK_NEEDED:-0}" -eq 1 ] && [ -n "$BACKUP_DIR" ]; then
        warn "The switch did not finish; putting the old playit config back."
        restore "$BACKUP_DIR" || true
        if [ -n "$SWITCH_TOKEN" ] && [ "${REPORTED:-0}" -eq 1 ]; then
            panel_post /api/remote/playit/agent-switch "{\"token\":\"$SWITCH_TOKEN\",\"outcome\":\"rolled_back\"}"
            [ "$HTTP_CODE" = 200 ] && say "Told the panel to use the old agent again." \
                || warn "Could not tell the panel (HTTP $HTTP_CODE). Click \"Undo switch\" on the node's playit card."
        fi
    fi
    exit "$status"
}
trap on_exit EXIT
trap 'exit 130' INT TERM

write_secret "$NEW_SECRET"
NEW_SECRET=""
say "Wrote the new secret to $SECRET_PATH; restarting playit ..."
restart_agent
agent_running || die "playit did not come back up with the new secret."
say "playit is running again (pid $PLAYIT_PID)."

REPORTED=0
if [ -n "$SWITCH_TOKEN" ]; then
    panel_post /api/remote/playit/agent-switch "{\"token\":\"$SWITCH_TOKEN\",\"outcome\":\"switched\"}"
    [ "$HTTP_CODE" = 200 ] || die "The panel did not accept the switch (HTTP $HTTP_CODE): $(json_field message "$HTTP_BODY")"
    REPORTED=1
    say "The panel switched to the new agent and is creating tunnels. Checking that playit accepts them ..."

    # The panel tries creating a tunnel; playit refuses (AgentVersionTooOld) until the agent
    # really runs with the new key. Give it up to three minutes.
    ok=0
    for _ in $(seq 1 18); do
        sleep 10
        panel_post /api/remote/playit/agent-status "{\"token\":\"$SWITCH_TOKEN\"}"
        [ "$HTTP_CODE" = 200 ] || continue
        total=$(json_number tunnels "$HTTP_BODY"); created=$(json_number created "$HTTP_BODY"); active=$(json_number active "$HTTP_BODY")
        say "  tunnels: ${total:-0}, created on playit: ${created:-0}, active: ${active:-0}"
        if [ "${total:-0}" = 0 ] || [ "${created:-0}" -gt 0 ]; then ok=1; break; fi
    done
    if [ "$ok" -ne 1 ]; then
        warn "playit has not accepted a tunnel for the new agent yet: $(json_field last_error "$HTTP_BODY")"
        if confirm "Roll back to the old agent?"; then
            die "Rolling back."
        fi
        say "Keeping the new agent. The panel retries every ten minutes; run with --rollback to go back."
    fi
else
    say "Manual secret: finish the switch on the node's playit card (\"Finish switch\")."
fi

ROLLBACK_NEEDED=0
say "Done. Old config backup: $BACKUP_DIR (undo with: sudo bash $0 --rollback $BACKUP_DIR)"
