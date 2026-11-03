#!/usr/bin/bash
# cced_unstuck.sh — recover a stuck CCEd instance (best effort).
#
# Called from GUI/admserv-php-fpm when CCE looks dead. Without guards, many
# FPM workers stampede in parallel: kill cced + systemctl restart cced-api
# while construct/firewalld already run → worse deadlock.
#
# Guards:
#   1) flock — only one unstuck at a time
#   2) cooldown — skip if we ran successfully within COOLDOWN_SEC
#   3) skip while cce_construct is active (or construct lock is held by a live job)
#   4) skip cced-api restart if unit is already activating/reloading or pre-script runs
#   5) only act if CCE is actually not READY (probe via cceclient)
#
# Always exit 0 so callers (GUI) never fail the request on unstuck policy skips.

set -u

LOCK_FILE="${CCED_UNSTUCK_LOCK:-/run/cced_unstuck.lock}"
COOLDOWN_FILE="${CCED_UNSTUCK_COOLDOWN:-/run/cced_unstuck.cooldown}"
COOLDOWN_SEC="${CCED_UNSTUCK_COOLDOWN_SEC:-90}"
CONSTRUCT_LOCK="${CCE_CONSTRUCT_LOCK:-/var/lock/subsys/cce_construct.lock}"
CCECLIENT="${CCECLIENT:-/usr/sausalito/bin/cceclient}"
CCED_BIN="${CCED_BIN:-/usr/sausalito/sbin/cced}"
LOGGER="${LOGGER:-/usr/bin/logger}"

log() {
    $LOGGER -t cced_unstuck -- "$*" 2>/dev/null || true
}

# Non-blocking flock: if another unstuck holds the lock, leave quietly.
exec 9>"$LOCK_FILE" 2>/dev/null || exit 0
if ! flock -n 9; then
    log "skip: another cced_unstuck holds $LOCK_FILE"
    exit 0
fi

# Cooldown after a completed unstuck cycle
now=$(date +%s 2>/dev/null || echo 0)
if [[ -f "$COOLDOWN_FILE" ]]; then
    last=$(cat "$COOLDOWN_FILE" 2>/dev/null || echo 0)
    if [[ "$last" =~ ^[0-9]+$ ]] && [[ "$now" =~ ^[0-9]+$ ]]; then
        age=$((now - last))
        if (( age >= 0 && age < COOLDOWN_SEC )); then
            log "skip: cooldown ${age}s < ${COOLDOWN_SEC}s"
            exit 0
        fi
    fi
fi

# Do not unstuck during constructor runs (first-boot / long handler batches).
construct_busy=0
if pgrep -f '/usr/sausalito/bin/cce_construct' >/dev/null 2>&1; then
    construct_busy=1
fi
if [[ -f "$CONSTRUCT_LOCK" ]]; then
    # Stale lock without a construct process is OK to ignore (swatch may clear it).
    if pgrep -f '/usr/sausalito/bin/cce_construct' >/dev/null 2>&1 \
        || pgrep -f '/usr/sausalito/constructor/' >/dev/null 2>&1; then
        construct_busy=1
    fi
fi
if (( construct_busy )); then
    log "skip: cce_construct / constructor active"
    exit 0
fi

# Optional: skip if cced-api is already mid-start (pre script / activating).
api_busy=0
if pgrep -f 'cced-api-pre\.sh' >/dev/null 2>&1 \
    || pgrep -f 'gen_api_admin\.pl' >/dev/null 2>&1; then
    api_busy=1
fi
if command -v systemctl >/dev/null 2>&1; then
    st=$(systemctl is-active cced-api.service 2>/dev/null || true)
    # activating / deactivating / reloading = leave alone
    case "$st" in
        activating|deactivating|reloading) api_busy=1 ;;
    esac
    # systemd "restart" in progress often shows as activating
fi

# Probe: is CCE actually dead?
cce_ok=0
if [[ -x "$CCECLIENT" ]]; then
    # Short timeout so a hung socket cannot block every FPM worker forever.
    if command -v timeout >/dev/null 2>&1; then
        out=$(echo BYE | timeout 3 "$CCECLIENT" 2>/dev/null || true)
    else
        out=$(echo BYE | "$CCECLIENT" 2>/dev/null || true)
    fi
    if echo "$out" | grep -qE '^200 READY|^202 GOODBYE'; then
        cce_ok=1
    fi
fi

if (( cce_ok )); then
    # CCE answers — only ensure cced-api if it is fully dead, not mid-restart.
    if (( api_busy )); then
        log "skip: CCE ready; cced-api already busy"
        exit 0
    fi
    if command -v systemctl >/dev/null 2>&1; then
        st=$(systemctl is-active cced-api.service 2>/dev/null || true)
        if [[ "$st" == "active" ]]; then
            log "skip: CCE ready and cced-api active"
            exit 0
        fi
        # CCE up, API down — restart API only (no kill storm).
        log "CCE ready but cced-api is '$st' — restart cced-api only"
        /usr/bin/systemctl restart cced-api.service &>/dev/null || :
        echo "$now" >"$COOLDOWN_FILE" 2>/dev/null || :
        exit 0
    fi
    log "skip: CCE ready"
    exit 0
fi

# CCE not READY — full unstuck (still single-flight via flock).
log "CCE not READY — killing stuck cced and rehash"

/usr/bin/killall -9 /usr/sausalito/sbin/cced &>/dev/null || :
/usr/bin/killall -9 pperld &>/dev/null || :
/usr/bin/killall -9 cced.init &>/dev/null || :
/usr/bin/killall -9 cced &>/dev/null || :
# Narrow kill: only sausalito cced binary paths / known names, not this script.
/bin/ps aux 2>/dev/null \
    | /bin/grep -E '[/]usr/sausalito/sbin/cced|[/]usr/sausalito/sbin/cced.init|pperld' \
    | /bin/grep -v cced_unstuck \
    | /bin/awk '{print $2}' \
    | /usr/bin/xargs -r -I {} kill -9 {} >/dev/null 2>&1 || :

if [[ -x /usr/sausalito/sbin/cced.init ]]; then
    /usr/sausalito/sbin/cced.init rehash &>/dev/null || :
fi

# Restart cced-api only if not already transitioning.
if (( api_busy )); then
    log "skip cced-api restart: already activating/pre-script running"
else
    log "restart cced-api.service"
    /usr/bin/systemctl restart cced-api.service &>/dev/null || :
fi

echo "$now" >"$COOLDOWN_FILE" 2>/dev/null || :
exit 0
