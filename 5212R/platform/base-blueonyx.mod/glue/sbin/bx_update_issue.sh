#!/bin/sh
# Single writer for /etc/issue. Skip rewrite when content is unchanged.
# If the file did change, HUP only gettys whose MainPID is still agetty
# (never a logged-in shell). Do not call this from a path that waits on
# cced.construct.
#
# Factory credentials stay on the banner while /etc/.bx-factory-console
# exists. network_settings.sh and the GUI wizard remove that stamp.
#
# Do not print Server Name while it still starts with "localhost"
# (kickstart localdomain vs CODB localhost) — that churn HUPs getty
# on every boot.

ISSUE=/etc/issue
FACTORY_STAMP=/etc/.bx-factory-console

# OpenVZ CTs have no useful console banner.
[ -e /proc/user_beancounters ] && exit 0

prod="BlueOnyx"
if [ -f /etc/build ]; then
    # "build YYYYMMDD for a 5212R in en_US" → field 5 is 5212R
    ver=$(awk '{print $5}' /etc/build | head -1)
    case "$ver" in
        52[0-9][0-9]R) ;;
        *) ver=$(grep -oE '52[0-9]{2}R' /etc/build | head -1) ;;
    esac
    [ -n "$ver" ] && prod="BlueOnyx $ver"
fi

# Kernel hostname (/etc/hostname), not hostname -d (/etc/hosts → localdomain).
server=$(uname -n 2>/dev/null || hostname 2>/dev/null || true)
case "$server" in
    localhost|localhost.*) server="" ;;
esac

# Address the operator browses: default-route source, not a CODB slave NIC.
ipv4=$(LC_ALL=C ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i == "src") { print $(i + 1); exit }}')
ipv6=$(LC_ALL=C ip -6 route get 2001:4860:4860::8888 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i == "src") { print $(i + 1); exit }}')
case "$ipv6" in
    fe80:*) ipv6="" ;;
esac

tmp=$(mktemp /tmp/bx-issue.XXXXXX) || exit 1
{
    printf '%s on \\S\n' "$prod"
    printf 'Kernel \\r on an \\m\n'
    printf '\n'
    [ -n "$server" ] && printf 'Server Name:  %s\n' "$server"
    [ -n "$ipv4" ] && printf 'IPv4 Address: %s\n' "$ipv4"
    [ -n "$ipv6" ] && printf 'IPv6 Address: %s\n' "$ipv6"
    if [ -f "$FACTORY_STAMP" ]; then
        printf '\n'
        printf 'Welcome to your new server ...\n'
        printf '  To finish setup, login as "root" with password "blueonyx"\n'
    fi
    printf '\n'
} > "$tmp"

if [ -f "$ISSUE" ] && cmp -s "$tmp" "$ISSUE"; then
    rm -f "$tmp"
    exit 0
fi

cat "$tmp" > "$ISSUE" || { rm -f "$tmp"; exit 1; }
rm -f "$tmp"

# Redraw idle login prompts only. After login, MainPID is the shell.
if command -v systemctl >/dev/null 2>&1; then
    systemctl list-units --type=service --state=running --no-legend 'getty@*' 'serial-getty@*' 2>/dev/null | \
        awk '{ print $1 }' | while read -r unit; do
            [ -n "$unit" ] || continue
            pid=$(systemctl show -p MainPID --value "$unit" 2>/dev/null)
            [ -n "$pid" ] && [ "$pid" != 0 ] || continue
            comm=$(ps -o comm= -p "$pid" 2>/dev/null | tr -d ' ')
            [ "$comm" = "agetty" ] || continue
            systemctl kill -s HUP "$unit" >/dev/null 2>&1 || :
        done
fi
exit 0
