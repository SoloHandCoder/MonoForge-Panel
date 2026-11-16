#!/usr/bin/env bash
# MonoForge Panel: switch this node's playit.gg agent to the self-managed agent the panel set up
# ("Set up automatic tunnels" on the node's playit card), so the panel can create tunnels itself.
#
#   sudo bash playit-switch-agent.sh
#       Finds the playit agent of this host (never one inside a game server container), shows the
#       systemd unit or process, the secret file and its owner, and asks you to type "yes".
#       It then asks for the one-time token shown in the panel (hidden prompt), fetches the new
#       agent secret from the panel in the Wings config ("remote") once, backs up the secret
#       file, writes the new secret, restarts playit through systemd (or tells you how to restart
#       it yourself) and tells the panel. If anything fails after the file changed, the old file
#       is put back automatically. Run it inside tmux or screen, so a dropped SSH session cannot
#       stop it halfway.
#
#   sudo bash playit-switch-agent.sh --rollback [BACKUP_DIR]
#       Puts back the secret file from a backup (the latest one by default), restarts playit and,
#       when given the switch token, tells the panel to go back to the old agent too.
#
# Options:
#   --unit NAME           the systemd unit that runs playit (skips the detection)
#   --secret-path PATH    the playit secret file (skips the detection of the file)
#   --panel URL           another panel URL than "remote" in the Wings config (asks first)
#   --wings-config PATH   the Wings config with the node's daemon token
#                         (default: /etc/pterodactyl/config.yml)
#   --manual-secret       paste the agent secret into a hidden prompt instead of fetching it
#                         (the panel is not told; finish the switch in the panel by hand)
#   --allow-http          allow an http:// panel URL (never for playit.gg)
#   --dry-run             only show what was detected and what would happen; change nothing
#
# Detection: --unit or --secret-path win. Otherwise a systemd unit whose ExecStart is a playit or
# playitd binary. Otherwise a running playit process of this host (same mount and pid namespace
# and root as pid 1), owned by root or the "playit" user. The script refuses when more than one
# matches. The secret file must be a .toml file in /etc/playit, /var/lib/playit or the agent
# user's ~/.config/playit_gg, with a secret_key line; never in the Wings data directory,
# /var/lib/docker or /home/container; and no part of its path may be a symlink, be owned by
# anyone but root or "playit", or be writable by group or others.
# A playit process outside systemd is never started by this script: it prints how to restart it.
#
# The secret is never printed, logged, passed on a command line or written anywhere but the
# playit secret file. The token is read from a hidden prompt, so it is not in shell history.
set -euo pipefail
set +x
umask 077
ulimit -c 0 2>/dev/null || true

SCRIPT_NAME=playit-switch-agent.sh
BACKUP_ROOT=/var/backups/monoforge-playit
BACKUP_KEEP_DAYS=30
WINGS_CONFIG=/etc/pterodactyl/config.yml
PLAYIT_USER=playit
PANEL=""
MODE_ROLLBACK=0
ROLLBACK_DIR=""
MANUAL_SECRET=0
SECRET_PATH_OVERRIDE=""
UNIT_OVERRIDE=""
DRY_RUN=0
ALLOW_HTTP=0
PLAYIT_API=https://api.playit.gg

say() { printf '%s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

usage() {
    local self=${BASH_SOURCE[0]:-}
    if [ -f "$self" ]; then sed -n '2,40p' "$self" | sed 's/^# \{0,1\}//'; else say "Usage: sudo bash $SCRIPT_NAME [--rollback [DIR]] [--dry-run] [--unit NAME] [--secret-path PATH]"; fi
    exit "${1:-0}"
}

# Asks for a typed "yes"; anything else is a no. There is no way to skip it.
confirm_yes() {
    local answer=""
    [ -r /dev/tty ] || return 1
    read -r -p "$1 Type yes to continue: " answer </dev/tty || return 1
    [ "$answer" = yes ]
}

# Reads a value without echoing it, from the terminal, into the variable named by $2.
read_hidden() {
    local prompt=$1 __value=""
    [ -r /dev/tty ] || die "No terminal to read the ${prompt%%:*} from."
    read -r -s -p "$prompt " __value </dev/tty || true
    printf '\n' >/dev/tty
    printf -v "$2" '%s' "$__value"
}

# Whether a value is a one-time switch token (48 lowercase hex characters, nothing else).
valid_token() {
    [[ "$1" =~ ^[0-9a-f]{48}$ ]]
}

# Whether a value looks like a playit agent secret (hex only).
valid_secret() {
    # ERE repeat counts stop at 255, so the length is checked on its own.
    [[ "$1" =~ ^[0-9a-fA-F]+$ ]] && [ ${#1} -ge 16 ] && [ ${#1} -le 512 ]
}

# ---------------------------------------------------------------------------------------------
# Reading the Wings config.
# ---------------------------------------------------------------------------------------------

# The value of a top-level "key: value" line of a YAML file, without quotes.
yaml_value() {
    local key=$1 file=$2 line
    line=$(grep -E "^${key}:[[:space:]]" "$file" 2>/dev/null | head -n 1 || true)
    line=${line#*:}
    printf '%s' "$line" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
}

# The value of KEY under a dotted path of YAML sections ("system" or "system.user"), without quotes.
yaml_nested() {
    local path=$1 key=$2 file=$3
    awk -v path="$path" -v key="$key" '
        function indent(s) { match(s, /^ */); return RLENGTH }
        BEGIN { n = split(path, want, "."); depth = 0 }
        /^[[:space:]]*(#|$)/ { next }
        {
            ind = indent($0); line = $0; sub(/^ */, "", line)
            while (depth > 0 && ind <= levels[depth]) depth--
            name = line; sub(/:.*/, "", name)
            if (depth == n && name == key) {
                value = line; sub(/^[^:]*:[[:space:]]*/, "", value); sub(/[[:space:]]+#.*$/, "", value)
                gsub(/^["\047]|["\047]$/, "", value); print value; exit
            }
            if (depth < n && name == want[depth + 1] && line ~ /:[[:space:]]*$/) { depth++; levels[depth] = ind }
        }' "$file" 2>/dev/null || true
}

# The directories of game server files on this node, from the Wings config and the defaults.
wings_data_dirs() {
    local dir
    for dir in "$(yaml_nested system root_directory "$WINGS_CONFIG")" "$(yaml_nested system data "$WINGS_CONFIG")" \
        /var/lib/pterodactyl /var/lib/docker /home/container; do
        if [ -n "$dir" ]; then printf '%s\n' "${dir%/}"; fi
    done
}

# The uid Wings runs game servers as (system.user.uid), or the pterodactyl user's, or empty.
wings_uid() {
    local uid
    uid=$(yaml_nested system.user uid "$WINGS_CONFIG")
    case "$uid" in '' | *[!0-9]*) uid=$(id -u pterodactyl 2>/dev/null || true) ;; esac
    printf '%s' "$uid"
}

# ---------------------------------------------------------------------------------------------
# Rules for what may be touched. Each returns 0 for yes.
# ---------------------------------------------------------------------------------------------

# Whether a path is inside game server data, where a tenant could read it.
path_forbidden() {
    local lexical resolved dir
    # Both the path as written and where its symlinks lead count.
    lexical=$(realpath -m -s -- "$1")
    resolved=$(realpath -m -- "$1")
    while IFS= read -r dir; do
        [ -n "$dir" ] || continue
        dir=$(realpath -m -s -- "$dir")
        case "$lexical/" in "$dir"/*) return 0 ;; esac
        case "$resolved/" in "$dir"/*) return 0 ;; esac
    done < <(wings_data_dirs)
    return 1
}

# The home directory of a user, or empty.
home_of() {
    getent passwd "$1" 2>/dev/null | cut -d: -f6
}

# Whether a secret path is one playit uses: a .toml file directly in /etc/playit,
# /var/lib/playit or the agent user's ~/.config/playit_gg. `$2` is the agent user.
secret_path_allowed() {
    local path=$1 user=${2:-root} dir name home
    [[ "$path" == /* ]] || return 1
    path=$(realpath -m -s -- "$path")
    case "/$path/" in */../* | */./*) return 1 ;; esac
    dir=$(dirname -- "$path")
    name=$(basename -- "$path")
    [[ "$name" =~ ^[A-Za-z0-9._-]+\.toml$ ]] || return 1
    [ "$dir" = /etc/playit ] && return 0
    [ "$dir" = /var/lib/playit ] && return 0
    home=$(home_of "$user")
    if [ -n "$home" ] && [ "$home" != / ]; then
        [ "$dir" = "$(realpath -m -s -- "$home")/.config/playit_gg" ] && return 0
    fi
    return 1
}

# Whether a uid may own the agent and its files: root or the playit user, never the Wings uid.
uid_allowed() {
    local uid=$1 playit_uid wings
    playit_uid=$(id -u "$PLAYIT_USER" 2>/dev/null || printf 'none')
    wings=$(wings_uid)
    if [ -n "$wings" ] && [ "$uid" = "$wings" ] && [ "$uid" != 0 ]; then
        return 1
    fi
    if [ "$uid" = 0 ] || [ "$uid" = "$playit_uid" ]; then
        return 0
    fi
    return 1
}

# Whether a binary path is a playit agent (playit, playitd, playit-linux-amd64, ...).
is_playit_binary() {
    local name
    name=$(basename -- "$1")
    [[ "$name" =~ ^playit(d)?([-_.].*)?$ ]]
}

# Whether a process runs in the host's mount and pid namespaces with the host's root (not in a
# container). Anything that cannot be read counts as a mismatch.
in_host_namespaces() {
    local pid=$1 ns mine host root
    for ns in mnt pid; do
        mine=$(readlink "/proc/$pid/ns/$ns" 2>/dev/null || true)
        host=$(readlink "/proc/1/ns/$ns" 2>/dev/null || true)
        [ -n "$mine" ] && [ -n "$host" ] && [ "$mine" = "$host" ] || return 1
    done
    root=$(readlink "/proc/$pid/root" 2>/dev/null || true)
    [ "$root" = / ]
}

# The start time of a process (field 22 of /proc/<pid>/stat, after the command name), or empty.
proc_start_time() {
    local stat
    stat=$(cat "/proc/$1/stat" 2>/dev/null || true)
    [ -n "$stat" ] || return 0
    stat=${stat##*) }
    # Fields after ") " start at field 3 (state); the start time is field 22, the 20th here.
    printf '%s' "$stat" | awk '{ print $20 }'
}

# Whether a secret file is unsafe to write: it, or one of its directories, is a symlink, is owned
# by anyone but root or the playit user, or is a directory writable by group or others (the
# sticky /tmp included). Prints the reason and returns 0 when unsafe.
path_unsafe() {
    local path=$1 part uid mode
    # Normalised without following symlinks, so each one on the way is seen.
    path=$(realpath -m -s -- "$path")
    part=$path
    while :; do
        if [ -L "$part" ]; then printf '%s is a symlink' "$part"; return 0; fi
        if [ -e "$part" ]; then
            uid=$(stat -c %u -- "$part")
            uid_allowed "$uid" || { printf '%s is owned by uid %s, not root or %s' "$part" "$uid" "$PLAYIT_USER"; return 0; }
            if [ -d "$part" ]; then
                mode=$(stat -c %a -- "$part")
                if (( (8#$mode & 8#022) != 0 )); then printf '%s is writable by group or others (mode %s)' "$part" "$mode"; return 0; fi
            fi
        fi
        [ "$part" = / ] && break
        part=$(dirname -- "$part")
    done
    return 1
}

# Who writes the secret file: the least privileged owner of the file and its directories. Any
# part owned by the playit user means the write runs as that user, never as root through a
# directory it controls.
path_writer() {
    local path part owner=""
    path=$(realpath -m -s -- "$1")
    part=$path
    while :; do
        if [ -e "$part" ] && [ "$(stat -c %u -- "$part")" != 0 ]; then
            owner=$(stat -c %U -- "$part")
        fi
        [ "$part" = / ] && break
        part=$(dirname -- "$part")
    done
    printf '%s' "${owner:-root}"
}

# The mode of the new secret file: 640 when the file's group is the playit group (so the agent
# can read a root-owned file), otherwise 600. Never anything readable by others.
secret_mode() {
    local file=$1 group
    if [ -f "$file" ]; then
        group=$(stat -c %G -- "$file")
        if [ "$group" = "$PLAYIT_USER" ] && [ "$(stat -c %U -- "$file")" != "$PLAYIT_USER" ]; then
            printf '640'
            return 0
        fi
    fi
    printf '600'
}

# Whether a systemd unit name looks like one.
valid_unit_name() {
    [[ "$1" =~ ^[A-Za-z0-9:_.@-]+\.service$ ]]
}

# ---------------------------------------------------------------------------------------------
# Command lines.
# ---------------------------------------------------------------------------------------------
ARGV=()

cmdline_of() {
    ARGV=()
    local arg
    while IFS= read -r -d '' arg; do ARGV+=("$arg"); done <"/proc/$1/cmdline"
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

# Fills ARGV from systemd's ExecStart value ("{ path=/usr/bin/playitd ; argv[]=/usr/bin/playitd
# --secret-path /etc/playit/playit.toml ; ... }"). The first command of the unit counts.
parse_execstart() {
    local text=$1 argv
    ARGV=()
    argv=${text#*argv[]=}
    [ "$argv" != "$text" ] || return 1
    argv=${argv%% ;*}
    read -r -a ARGV <<<"$argv"
    [ ${#ARGV[@]} -gt 0 ]
}

# ---------------------------------------------------------------------------------------------
# The secret file's content.
# ---------------------------------------------------------------------------------------------

# How many lines of a text set a key named exactly secret_key.
secret_key_lines() {
    local count=0 line
    while IFS= read -r line || [ -n "$line" ]; do
        if [[ "$line" =~ ^[[:space:]]*secret_key[[:space:]]*= ]]; then count=$((count + 1)); fi
    done <<<"$1"
    printf '%s' "$count"
}

# The new secret file from the old one ($1, possibly empty when there is none): only the line
# that sets exactly `secret_key =` changes. Fails when the old file sets it more than once.
build_secret_content() {
    local old=$1 secret=$2 line out=""
    if [ -z "$old" ]; then
        printf 'secret_key = "%s"\n' "$secret"
        return 0
    fi
    [ "$(secret_key_lines "$old")" = 1 ] || return 1
    while IFS= read -r line || [ -n "$line" ]; do
        if [[ "$line" =~ ^[[:space:]]*secret_key[[:space:]]*= ]]; then
            out+="secret_key = \"$secret\""$'\n'
        else
            out+="$line"$'\n'
        fi
    done <<<"$old"
    printf '%s' "$out"
}

# ---------------------------------------------------------------------------------------------
# Detecting playit on this host.
# ---------------------------------------------------------------------------------------------
MODE=""          # systemd | manual
UNIT=""
RUN_USER=root
RUN_CWD=/
SECRET_PATH=""
PLAYIT_PID=""
PLAYIT_START=""
FILE_OWNER=root
FILE_MODE=600
WRITE_AS=root

# The systemd services whose ExecStart is a playit binary, one per line.
playit_units() {
    local unit exec
    command -v systemctl >/dev/null 2>&1 || return 0
    while IFS= read -r unit; do
        [ -n "$unit" ] || continue
        exec=$(systemctl show -p ExecStart --value -- "$unit" 2>/dev/null || true)
        parse_execstart "$exec" || continue
        if is_playit_binary "${ARGV[0]}"; then printf '%s\n' "$unit"; fi
    done < <(systemctl list-units --type=service --all --no-legend --plain 2>/dev/null | awk '{print $1}')
}

# Running playit processes of this host owned by root or the playit user, one pid per line.
playit_processes() {
    local dir pid exe uid
    for dir in /proc/[0-9]*; do
        pid=${dir#/proc/}
        exe=$(readlink "$dir/exe" 2>/dev/null || true)
        [ -n "$exe" ] && is_playit_binary "${exe% (deleted)}" || continue
        in_host_namespaces "$pid" || continue
        uid=$(stat -c %u "$dir" 2>/dev/null || true)
        [ -n "$uid" ] && uid_allowed "$uid" || continue
        printf '%s\n' "$pid"
    done
}

# Checks a unit runs a playit binary and reads it into UNIT, RUN_USER, RUN_CWD and ARGV.
read_unit() {
    local exec unit=$1
    [[ "$unit" == *.service ]] || unit=$unit.service
    valid_unit_name "$unit" || die "$1 is not a systemd service name."
    UNIT=$unit
    exec=$(systemctl show -p ExecStart --value -- "$UNIT" 2>/dev/null || true)
    parse_execstart "$exec" || die "Cannot read the ExecStart of $UNIT."
    is_playit_binary "${ARGV[0]}" || die "$UNIT does not run a playit binary (${ARGV[0]})."
    RUN_USER=$(systemctl show -p User --value -- "$UNIT" 2>/dev/null || true)
    RUN_USER=${RUN_USER:-root}
    RUN_CWD=$(systemctl show -p WorkingDirectory --value -- "$UNIT" 2>/dev/null || true)
    RUN_CWD=${RUN_CWD:-/}
}

use_unit() {
    read_unit "$1"
    MODE=systemd
    if arg_value --secret >/dev/null; then
        die "$UNIT runs playit with --secret on its command line; give it a --secret-path file first."
    fi
    SECRET_PATH=$(arg_value --secret-path --secret_path || true)
    [ -n "$SECRET_PATH" ] || SECRET_PATH=/etc/playit/playit.toml
}

use_process() {
    local pid=$1 home
    PLAYIT_START=$(proc_start_time "$pid")
    [ -n "$PLAYIT_START" ] || die "playit (pid $pid) went away."
    cmdline_of "$pid"
    RUN_USER=$(stat -c %U "/proc/$pid")
    RUN_CWD=$(readlink "/proc/$pid/cwd" || printf '/')
    # The same process as before it was read, still on the host and owned by an allowed user.
    [ "$(proc_start_time "$pid")" = "$PLAYIT_START" ] || die "playit (pid $pid) changed while it was read. Run the script again."
    in_host_namespaces "$pid" || die "playit (pid $pid) is not in the host's namespaces."
    uid_allowed "$(stat -c %u "/proc/$pid")" || die "playit (pid $pid) is not owned by root or $PLAYIT_USER."
    PLAYIT_PID=$pid
    MODE=manual
    if arg_value --secret >/dev/null; then
        die "playit (pid $pid) runs with --secret on its command line; move the secret into a file (--secret_path) first."
    fi
    SECRET_PATH=$(arg_value --secret_path --secret-path || true)
    if [ -z "$SECRET_PATH" ]; then
        home=$(home_of "$RUN_USER")
        SECRET_PATH=$home/.config/playit_gg/playit.toml
    fi
    case "$SECRET_PATH" in /*) ;; *) SECRET_PATH=$RUN_CWD/$SECRET_PATH ;; esac
}

# The checks every secret path passes, in a switch and on a restore.
check_secret_path() {
    local reason
    [[ "$SECRET_PATH" == /* ]] || die "The secret path must be absolute: $SECRET_PATH"
    SECRET_PATH=$(realpath -m -s -- "$SECRET_PATH")
    path_forbidden "$SECRET_PATH" && die "Refusing $SECRET_PATH: it is inside game server data (the Wings data directory, /var/lib/docker or /home/container)."
    secret_path_allowed "$SECRET_PATH" "$RUN_USER" || die "Refusing $SECRET_PATH: playit secrets live in /etc/playit, /var/lib/playit or ~$RUN_USER/.config/playit_gg, as a .toml file."
    if reason=$(path_unsafe "$SECRET_PATH"); then
        die "Refusing $SECRET_PATH: $reason."
    fi
    WRITE_AS=$(path_writer "$SECRET_PATH")
    uid_allowed "$(id -u "$WRITE_AS")" || die "Refusing $SECRET_PATH: it would be written as $WRITE_AS."
}

detect() {
    local units pids count
    if [ -n "$UNIT_OVERRIDE" ]; then
        use_unit "$UNIT_OVERRIDE"
    else
        units=$(playit_units)
        count=$(printf '%s' "$units" | grep -c . || true)
        if [ "$count" -gt 1 ]; then
            die "More than one systemd unit runs playit: $(printf '%s' "$units" | tr '\n' ' ')- pick one with --unit NAME."
        elif [ "$count" -eq 1 ]; then
            use_unit "$units"
        elif [ -z "$SECRET_PATH_OVERRIDE" ]; then
            pids=$(playit_processes)
            count=$(printf '%s' "$pids" | grep -c . || true)
            [ "$count" -gt 0 ] || die "No playit agent of this host was found (a systemd unit, or a process owned by root or $PLAYIT_USER). Pass --unit or --secret-path."
            [ "$count" -eq 1 ] || die "More than one playit process matches (pids $(printf '%s' "$pids" | tr '\n' ' ')). Stop the extra ones or pass --secret-path."
            use_process "$pids"
        else
            MODE=manual
        fi
    fi

    [ -z "$SECRET_PATH_OVERRIDE" ] || SECRET_PATH=$SECRET_PATH_OVERRIDE
    check_secret_path

    if [ -f "$SECRET_PATH" ]; then
        FILE_OWNER=$(stat -c %U -- "$SECRET_PATH")
        local current
        current=$(run_as "$WRITE_AS" cat -- "$SECRET_PATH") || die "Cannot read $SECRET_PATH as $WRITE_AS."
        [ "$(secret_key_lines "$current")" = 1 ] || die "Refusing $SECRET_PATH: it has no single secret_key line, so it is not a playit secret file."
        current=""
    else
        FILE_OWNER=$WRITE_AS
    fi
    FILE_MODE=$(secret_mode "$SECRET_PATH")
    if [ "$MODE" = systemd ]; then
        uid_allowed "$(id -u "$RUN_USER" 2>/dev/null || printf x)" || die "$UNIT runs as $RUN_USER, not root or $PLAYIT_USER."
    fi
}

describe() {
    case "$MODE" in
        systemd) say "playit unit:   $UNIT (runs as $RUN_USER): $(printf '%q ' "${ARGV[@]}")" ;;
        manual)
            if [ -n "$PLAYIT_PID" ]; then
                say "playit process: pid $PLAYIT_PID, user $RUN_USER, in $RUN_CWD: $(printf '%q ' "${ARGV[@]}")"
            else
                say "playit:        no unit or process found; only the secret file is changed."
            fi
            say "Restart:       by hand (this script never starts programs outside systemd)" ;;
    esac
    say "Secret file:   $SECRET_PATH $([ -f "$SECRET_PATH" ] && printf '(exists, owner %s)' "$FILE_OWNER" || printf '(missing)')"
    say "Written as:    $WRITE_AS, mode $FILE_MODE"
}

# ---------------------------------------------------------------------------------------------
# Files: written as the least privileged owner on the path (so a non-root owner can only affect
# their own files), through a temp file in the same directory and an atomic rename.
# ---------------------------------------------------------------------------------------------
run_as() {
    local user=$1
    shift
    if [ "$user" = root ]; then "$@"; else runuser -u "$user" -- "$@"; fi
}

# Writes stdin to DEST with MODE, as USER: mktemp (O_EXCL, 0600) in DEST's directory, then mv -T.
install_as() {
    local user=$1 dest=$2 mode=$3
    # shellcheck disable=SC2016
    run_as "$user" bash -c 'set -euo pipefail; umask 077
        dest=$1; mode=$2; dir=$(dirname -- "$dest")
        tmp=$(mktemp -- "$dir/.playit.toml.monoforge.XXXXXX")
        trap '\''rm -f -- "$tmp"'\'' EXIT
        cat >"$tmp"
        chmod -- "$mode" "$tmp"
        mv -fT -- "$tmp" "$dest"
        trap - EXIT' _ "$dest" "$mode"
}

write_secret() {
    local secret=$1 reason old="" content
    if reason=$(path_unsafe "$SECRET_PATH"); then die "Refusing $SECRET_PATH: $reason."; fi
    [ "$(path_writer "$SECRET_PATH")" = "$WRITE_AS" ] || die "The owners on $SECRET_PATH changed. Nothing written."
    if [ -f "$SECRET_PATH" ]; then
        old=$(run_as "$WRITE_AS" cat -- "$SECRET_PATH") || die "Cannot read $SECRET_PATH."
    else
        run_as "$WRITE_AS" mkdir -p -- "$(dirname -- "$SECRET_PATH")"
    fi
    # Built and checked in full before anything is installed.
    content=$(build_secret_content "$old" "$secret") || die "$SECRET_PATH sets secret_key more than once. Nothing written."
    old=""
    [ "$(secret_key_lines "$content")" = 1 ] || die "The new secret file would not have exactly one secret_key line. Nothing written."
    printf '%s\n' "$content" | install_as "$WRITE_AS" "$SECRET_PATH" "$FILE_MODE"
    content=""
}

# ---------------------------------------------------------------------------------------------
# Restarting and checking the agent.
# ---------------------------------------------------------------------------------------------
manual_restart_steps() {
    say ""
    say "playit does not run under systemd, so restart it yourself now (this script never starts it):"
    if [ -n "$PLAYIT_PID" ]; then
        say "  1. Stop it:   sudo kill $PLAYIT_PID"
        say "  2. Start it again as $RUN_USER, in $RUN_CWD, exactly as before (inside its screen session if it ran in one):"
        say "       cd $(printf '%q' "$RUN_CWD") && $(printf '%q ' "${ARGV[@]}")"
    else
        say "  Stop playit and start it again, so it reads $SECRET_PATH."
    fi
    say ""
}

restart_agent() {
    if [ "$MODE" = systemd ]; then
        systemctl restart -- "$UNIT"
        return 0
    fi

    manual_restart_steps
    local answer=""
    read -r -p "Type done once playit runs again (anything else stops here): " answer </dev/tty || true
    [ "$answer" = done ]
}

# Whether the agent is up again and stays up for a few seconds.
agent_running() {
    local i pid name
    if [ "$MODE" = systemd ]; then
        for i in $(seq 1 30); do
            if systemctl is-active --quiet -- "$UNIT"; then
                sleep 5
                systemctl is-active --quiet -- "$UNIT" && return 0
            fi
            sleep 1
        done
        return 1
    fi

    [ ${#ARGV[@]} -gt 0 ] || return 0
    name=$(basename -- "${ARGV[0]}")
    for i in $(seq 1 30); do
        pid=$(pgrep -x -u "$RUN_USER" -- "${name:0:15}" | head -n 1 || true)
        if [ -n "$pid" ] && [ "$pid" != "$PLAYIT_PID" ] && in_host_namespaces "$pid"; then
            sleep 5
            kill -0 "$pid" 2>/dev/null && { PLAYIT_PID=$pid; return 0; }
        fi
        sleep 1
    done
    return 1
}

# ---------------------------------------------------------------------------------------------
# Backups, in a root-only directory.
# ---------------------------------------------------------------------------------------------
BACKUP_DIR=""

backup_root() {
    if [ -e "$BACKUP_ROOT" ] || [ -L "$BACKUP_ROOT" ]; then
        [ -d "$BACKUP_ROOT" ] && [ ! -L "$BACKUP_ROOT" ] || die "$BACKUP_ROOT is not a directory."
        [ "$(stat -c %u -- "$BACKUP_ROOT")" = 0 ] || die "$BACKUP_ROOT is not owned by root."
    else
        mkdir -p -m 700 -- "$BACKUP_ROOT"
    fi
    chmod 700 -- "$BACKUP_ROOT"
}

# Backups older than BACKUP_KEEP_DAYS hold old agent keys: remove them (never following links).
prune_backups() {
    find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -mtime +"$BACKUP_KEEP_DAYS" -exec rm -rf -- {} + 2>/dev/null || true
}

backup() {
    backup_root
    BACKUP_DIR=$(mktemp -d -- "$BACKUP_ROOT/$(date +%Y%m%d-%H%M%S).XXXX")
    chmod 700 -- "$BACKUP_DIR"
    if [ -f "$SECRET_PATH" ]; then
        cp --no-dereference --preserve=mode,ownership,timestamps -- "$SECRET_PATH" "$BACKUP_DIR/playit.toml"
        [ -f "$BACKUP_DIR/playit.toml" ] && [ ! -L "$BACKUP_DIR/playit.toml" ] || die "The secret file changed while it was backed up. Nothing changed."
        # A hard link to another user's file would carry that user's ownership: refuse it.
        [ "$(stat -c %U -- "$BACKUP_DIR/playit.toml")" = "$FILE_OWNER" ] || die "The secret file changed owner while it was backed up. Nothing changed."
        printf '1' >"$BACKUP_DIR/had_secret"
        printf '%s' "$(stat -c %a -- "$SECRET_PATH")" >"$BACKUP_DIR/mode"
    else
        printf '0' >"$BACKUP_DIR/had_secret"
        printf '600' >"$BACKUP_DIR/mode"
    fi
    {
        printf 'MODE\t%s\n' "$MODE"
        printf 'UNIT\t%s\n' "$UNIT"
        printf 'RUN_USER\t%s\n' "$RUN_USER"
        printf 'SECRET_PATH\t%s\n' "$SECRET_PATH"
        printf 'FILE_OWNER\t%s\n' "$FILE_OWNER"
    } >"$BACKUP_DIR/state.tsv"
    basename -- "$BACKUP_DIR" >"$BACKUP_ROOT/latest"
    say "Backed up the playit secret file to $BACKUP_DIR"
}

# A backup directory to roll back to: a direct, root-only child of BACKUP_ROOT whose files are
# plain files (no links) owned by root, or, for the copy of the secret file, by its owner.
backup_dir_valid() {
    local dir=$1 root file uid
    [ -d "$dir" ] && [ ! -L "$dir" ] || return 1
    root=$(realpath -e -- "$BACKUP_ROOT" 2>/dev/null) || return 1
    [ "$(dirname -- "$(realpath -e -- "$dir")")" = "$root" ] || return 1
    [ "$(stat -c %u -- "$dir")" = 0 ] && [ "$(stat -c %a -- "$dir")" = 700 ] || return 1
    for file in state.tsv had_secret mode; do
        [ -f "$dir/$file" ] && [ ! -L "$dir/$file" ] && [ "$(stat -c %u -- "$dir/$file")" = 0 ] || return 1
    done
    if [ -e "$dir/playit.toml" ] || [ -L "$dir/playit.toml" ]; then
        [ -f "$dir/playit.toml" ] && [ ! -L "$dir/playit.toml" ] || return 1
        uid=$(stat -c %u -- "$dir/playit.toml")
        uid_allowed "$uid" || return 1
    fi
    return 0
}

# Reads a backup's state (plain key/value lines; nothing is executed) and checks it like a switch.
load_state() {
    local dir=$1 key value mode
    MODE="" UNIT="" RUN_USER=root SECRET_PATH="" FILE_OWNER=root
    while IFS=$'\t' read -r key value; do
        case "$key" in
            MODE) MODE=$value ;;
            UNIT) UNIT=$value ;;
            RUN_USER) RUN_USER=$value ;;
            SECRET_PATH) SECRET_PATH=$value ;;
            FILE_OWNER) FILE_OWNER=$value ;;
        esac
    done <"$dir/state.tsv"
    [ "$MODE" = systemd ] || [ "$MODE" = manual ] || die "$dir has an unknown mode."
    id -u "$RUN_USER" >/dev/null 2>&1 || die "$dir names an unknown user."
    if [ "$MODE" = systemd ]; then
        local user=$RUN_USER
        read_unit "$UNIT"
        RUN_USER=$user
    fi
    check_secret_path
    mode=$(cat "$dir/mode")
    [[ "$mode" =~ ^[0-7]{3,4}$ ]] || die "$dir has a bad mode."
    # Never more open than 640 (group) on a restore either.
    FILE_MODE=$(printf '%o' $(( 8#$mode & 8#640 )))
}

# Puts the backed-up file back. Returns 1, with a warning, when it could not.
restore_file() {
    local dir=$1 reason
    if path_forbidden "$SECRET_PATH"; then warn "Refusing $SECRET_PATH: it is inside game server data."; return 1; fi
    if reason=$(path_unsafe "$SECRET_PATH"); then warn "Refusing $SECRET_PATH: $reason."; return 1; fi
    WRITE_AS=$(path_writer "$SECRET_PATH")
    if ! uid_allowed "$(id -u "$WRITE_AS")"; then warn "Refusing to restore as $WRITE_AS."; return 1; fi
    if [ "$(cat "$dir/had_secret")" = 1 ]; then
        if [ ! -f "$dir/playit.toml" ] || [ -L "$dir/playit.toml" ]; then warn "$dir has no secret file."; return 1; fi
        if ! install_as "$WRITE_AS" "$SECRET_PATH" "$FILE_MODE" <"$dir/playit.toml"; then warn "Could not write $SECRET_PATH."; return 1; fi
        if ! cmp -s -- "$dir/playit.toml" "$SECRET_PATH"; then warn "$SECRET_PATH does not match the backup after the restore."; return 1; fi
    else
        if ! run_as "$WRITE_AS" rm -f -- "$SECRET_PATH"; then warn "Could not remove $SECRET_PATH."; return 1; fi
    fi
    return 0
}

# Restores the file and restarts playit. Returns 1 when the file was not restored (the panel
# must not be told then); a playit that does not come back is only warned about.
restore() {
    local dir=$1
    if ! restore_file "$dir"; then
        warn "Could not restore the old playit secret file from $dir. Nothing was rolled back; fix it by hand."
        return 1
    fi
    if restart_agent && agent_running; then
        say "Restored the old playit secret file from $dir and playit runs again."
    else
        warn "Restored the old playit secret file from $dir, but playit did not come back up. Check it by hand."
    fi
    return 0
}

# ---------------------------------------------------------------------------------------------
# Talking to the panel and to playit. Secrets go into curl through a config on a pipe, never on
# the command line (where `ps` would show them); -q keeps any .curlrc out.
# ---------------------------------------------------------------------------------------------
TOKEN_ID=""
DAEMON_TOKEN=""
SWITCH_TOKEN=""
HTTP_BODY=""
HTTP_CODE=""

# Reads the daemon token and settles the panel URL: the Wings "remote", unless --panel names
# another one, which has to be confirmed (the daemon token is sent to it).
load_wings_config() {
    local remote
    [ -r "$WINGS_CONFIG" ] || die "Cannot read the Wings config at $WINGS_CONFIG. Pass --wings-config PATH."
    TOKEN_ID=$(yaml_value token_id "$WINGS_CONFIG")
    DAEMON_TOKEN=$(yaml_value token "$WINGS_CONFIG")
    [ -n "$TOKEN_ID" ] && [ -n "$DAEMON_TOKEN" ] || die "The Wings config has no token_id/token."
    remote=$(yaml_value remote "$WINGS_CONFIG")
    remote=${remote%/}
    PANEL=${PANEL%/}
    if [ -z "$PANEL" ]; then
        PANEL=$remote
    elif [ "$PANEL" != "$remote" ]; then
        say "The Wings config says the panel is: ${remote:-(nothing)}"
        say "You passed --panel:                 $PANEL"
        confirm_yes "The node's daemon token will be sent to $PANEL." || die "Nothing changed."
    fi
    [ -n "$PANEL" ] || die "The Wings config has no remote; pass --panel https://your-panel."
    case "$PANEL" in
        https://*) ;;
        http://*) [ "$ALLOW_HTTP" -eq 1 ] || die "The panel URL must use https (or pass --allow-http)." ;;
        *) die "The panel URL must start with https://." ;;
    esac
}

# One JSON string field of a flat JSON body (the panel's and playit's answers are compact).
json_field() {
    printf '%s' "$2" | sed -n "s/.*\"$1\":\"\([^\"]*\)\".*/\1/p" | head -n 1
}

json_number() {
    printf '%s' "$2" | sed -n "s/.*\"$1\":\([0-9][0-9]*\).*/\1/p" | head -n 1
}

# panel_post PATH JSON_BODY -> HTTP_CODE, HTTP_BODY
panel_post() {
    local path=$1 body=$2 out proto='=https'
    [ "$ALLOW_HTTP" -eq 1 ] && proto='=https,http'
    out=$(curl -q -sS --proto "$proto" --max-time 30 -X POST \
        -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -K <(printf 'header = "Authorization: Bearer %s.%s"\ndata = "%s"\n' "$TOKEN_ID" "$DAEMON_TOKEN" "${body//\"/\\\"}") \
        -w '\n%{http_code}' "$PANEL$path") || { HTTP_CODE=000; HTTP_BODY=""; return 0; }
    HTTP_CODE=${out##*$'\n'}
    HTTP_BODY=${out%$'\n'*}
}

# Tells the panel the node is back on the old agent. A no-op on the panel when it never switched.
# Only called once the old file really is back.
report_rolled_back() {
    [ -n "$SWITCH_TOKEN" ] || { say "Choose \"Check status\" (or \"Undo switch\") on the node's playit card."; return 0; }
    panel_post /api/remote/playit/agent-switch "{\"token\":\"$SWITCH_TOKEN\",\"outcome\":\"rolled_back\"}"
    if [ "$HTTP_CODE" = 200 ]; then
        say "Told the panel to use the old agent again."
    else
        warn "Could not tell the panel (HTTP $HTTP_CODE). Choose \"Check status\" or \"Undo switch\" on the node's playit card."
    fi
}

# The agent id playit returns for a key, from the agent's run data, or empty.
playit_agent_id() {
    local key=$1 out
    out=$(curl -q -sS --proto '=https' --max-time 20 -X POST \
        -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -K <(printf 'header = "Authorization: Agent-Key %s"\n' "$key") \
        --data '{}' "$PLAYIT_API/v1/agents/rundata" 2>/dev/null) || return 0
    case "$out" in *'"status":"success"'*) json_field agent_id "$out" | tr 'A-F' 'a-f' ;; esac
}

# The tests source the script with MONOFORGE_PLAYIT_LIB=1 to use the functions above on their own.
if [ "${MONOFORGE_PLAYIT_LIB:-0}" = 1 ]; then
    return 0 2>/dev/null || exit 0
fi

# ---------------------------------------------------------------------------------------------
# Options.
# ---------------------------------------------------------------------------------------------
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
        --unit) UNIT_OVERRIDE=${2:-}; shift 2 ;;
        --unit=*) UNIT_OVERRIDE=${1#*=}; shift ;;
        --dry-run) DRY_RUN=1; shift ;;
        --allow-http) ALLOW_HTTP=1; shift ;;
        -y | --yes) die "There is no way to skip the confirmation; type yes when asked." ;;
        -h | --help) usage 0 ;;
        *) warn "Unknown option: $1"; usage 1 ;;
    esac
done

[ "$(id -u)" -eq 0 ] || die "Run with sudo."
command -v curl >/dev/null 2>&1 || die "curl is required."
command -v runuser >/dev/null 2>&1 || die "runuser (util-linux) is required."

# A hang-up ends the run through the EXIT trap (which rolls back); a dead terminal cannot answer.
trap 'exit 129' HUP
if [ -z "${TMUX:-}" ] && [ -z "${STY:-}" ] && [ "$DRY_RUN" -eq 0 ]; then
    say "Tip: run this inside tmux or screen, so a dropped SSH connection cannot interrupt it."
fi

# ---------------------------------------------------------------------------------------------
# Rollback mode.
# ---------------------------------------------------------------------------------------------
if [ "$MODE_ROLLBACK" -eq 1 ]; then
    if [ -n "$ROLLBACK_DIR" ]; then
        dir=$ROLLBACK_DIR
    else
        [ -f "$BACKUP_ROOT/latest" ] && [ ! -L "$BACKUP_ROOT/latest" ] || die "No backup found. Pass --rollback DIR (backups are in $BACKUP_ROOT)."
        name=$(head -n 1 "$BACKUP_ROOT/latest")
        [[ "$name" =~ ^[0-9]{8}-[0-9]{6}\.[A-Za-z0-9]+$ ]] || die "$BACKUP_ROOT/latest does not name a backup."
        dir=$BACKUP_ROOT/$name
    fi
    backup_dir_valid "$dir" || die "$dir is not a backup made by this script (a root-only directory directly in $BACKUP_ROOT)."
    dir=$(realpath -e -- "$dir")
    load_state "$dir"
    say "Rolling back to $dir"
    say "Secret file:   $SECRET_PATH (written as $WRITE_AS, mode $FILE_MODE)"
    [ "$MODE" = systemd ] && say "playit unit:   $UNIT"
    if [ "$DRY_RUN" -eq 1 ]; then
        say "Dry run: nothing changed."
        exit 0
    fi
    confirm_yes "Restore this backup and restart playit?" || die "Nothing changed."
    restore "$dir" || die "The rollback did not finish; the panel was not told."
    if [ -r "$WINGS_CONFIG" ]; then
        read_hidden "Paste the switch token to tell the panel (or press Enter to skip):" SWITCH_TOKEN
        if [ -n "$SWITCH_TOKEN" ]; then
            valid_token "$SWITCH_TOKEN" || die "That is not a switch token. Copy it from the node's playit card."
            load_wings_config
        fi
        report_rolled_back
    fi
    exit 0
fi

# ---------------------------------------------------------------------------------------------
# Switch mode.
# ---------------------------------------------------------------------------------------------
detect
describe
if [ "$DRY_RUN" -eq 1 ]; then
    if [ "$MODE" = systemd ]; then
        say "Would:         back up the secret file, write the new secret as $WRITE_AS and run: systemctl restart $UNIT"
    else
        say "Would:         back up the secret file, write the new secret as $WRITE_AS, then ask you to restart playit:"
        manual_restart_steps
    fi
    say "Dry run: nothing changed."
    exit 0
fi

confirm_yes "This is the playit agent and secret file to switch." || die "Nothing changed."

NEW_SECRET=""
NEW_AGENT=""
if [ "$MANUAL_SECRET" -eq 1 ]; then
    read_hidden "Paste the new agent secret (hidden):" NEW_SECRET
else
    load_wings_config
    read_hidden "Paste the one-time switch token from the panel (hidden):" SWITCH_TOKEN
    valid_token "$SWITCH_TOKEN" || die "That is not a switch token. Copy it from the node's playit card."
    say "Fetching the new agent secret from $PANEL ..."
    panel_post /api/remote/playit/agent-secret "{\"token\":\"$SWITCH_TOKEN\"}"
    case "$HTTP_CODE" in
        200) ;;
        404) die "The panel says the token is unknown, expired or already used. Create a new one on the node's playit card." ;;
        401 | 403) die "The panel did not accept this node's daemon token (from $WINGS_CONFIG)." ;;
        000) die "Could not reach the panel at $PANEL." ;;
        *) die "The panel answered HTTP $HTTP_CODE." ;;
    esac
    NEW_SECRET=$(json_field secret_key "$HTTP_BODY")
    NEW_AGENT=$(json_field agent_id "$HTTP_BODY")
    HTTP_BODY=""
fi
valid_secret "$NEW_SECRET" || die "The agent secret does not look like a playit key."

OLD_AGENT=""
if [ -f "$SECRET_PATH" ]; then
    old_secret=$(run_as "$WRITE_AS" sed -n 's/^[[:space:]]*secret_key[[:space:]]*=[[:space:]]*["'"'"']\{0,1\}\([0-9a-fA-F]*\).*/\1/p' -- "$SECRET_PATH" | head -n 1)
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

confirm_yes "Switch playit on this node to the new agent now? Its old tunnels stop working." || die "Nothing changed."

backup_root
prune_backups
backup
ROLLBACK_NEEDED=1
on_exit() {
    local status=$?
    NEW_SECRET=""
    trap - HUP INT TERM
    if [ "${ROLLBACK_NEEDED:-0}" -eq 1 ] && [ -n "$BACKUP_DIR" ]; then
        # Output is best-effort: the terminal may be gone.
        warn "The switch did not finish; putting the old playit secret file back." 2>/dev/null || true
        if restore "$BACKUP_DIR" 2>/dev/null; then
            report_rolled_back 2>/dev/null || true
        else
            warn "The old secret file could not be put back; the panel was not told. Restore it with: sudo bash $SCRIPT_NAME --rollback $BACKUP_DIR" 2>/dev/null || true
        fi
    fi
    exit "$status"
}
trap on_exit EXIT
trap 'exit 130' INT TERM

write_secret "$NEW_SECRET"
NEW_SECRET=""
say "Wrote the new secret to $SECRET_PATH."
restart_agent || die "playit was not restarted."
agent_running || die "playit did not come back up with the new secret."
say "playit is running again."

if [ -n "$SWITCH_TOKEN" ]; then
    # The report is safe to repeat; a lost answer is retried rather than taken as a refusal.
    for _ in 1 2 3; do
        panel_post /api/remote/playit/agent-switch "{\"token\":\"$SWITCH_TOKEN\",\"outcome\":\"switched\"}"
        [ "$HTTP_CODE" = 000 ] || break
        sleep 5
    done
    [ "$HTTP_CODE" = 200 ] || die "The panel did not accept the switch (HTTP $HTTP_CODE): $(json_field message "$HTTP_BODY")"
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
        if confirm_yes "Roll back to the old agent?"; then
            die "Rolling back."
        fi
        say "Keeping the new agent. The panel retries every ten minutes; run with --rollback to go back."
    fi
else
    say "Manual secret: finish the switch on the node's playit card (\"Finish switch\")."
fi

ROLLBACK_NEEDED=0
say "Done. Backup of the old secret file: $BACKUP_DIR (undo with: sudo bash $SCRIPT_NAME --rollback $BACKUP_DIR)"
say "It holds the old agent key; backups older than $BACKUP_KEEP_DAYS days are removed on the next run."
say "Delete the old agent in the playit.gg dashboard once you no longer need to roll back."
