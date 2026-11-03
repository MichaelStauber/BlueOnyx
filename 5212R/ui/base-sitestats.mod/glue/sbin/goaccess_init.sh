#!/usr/bin/bash
#
# Start GoAccess real-time HTML generator (loopback only).
#
# Security model:
#   - GoAccess binds 127.0.0.1:7890 without TLS (not public).
#   - AdmServ terminates TLS and reverse-proxies /goaccess-ws/ with
#     BXGoAccess cookie gate (goaccess-admserv.conf).
#   - /base/sitestats/index.html is gated by .htaccess (BXGoAccess).
#   - connection{} in the HTML is rewritten so the browser opens
#     wss://<GUI-host>:<GUI-port>/goaccess-ws/ (not :7890).
#
# Copyright (c) 2022-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2022-2026 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
#

set -u

OUT_HTML="/usr/sausalito/ui/web/base/sitestats/index.html"
OUT_DIR="$(dirname "$OUT_HTML")"
LOG_FORMAT='%v %h %l %u %^[%d:%t %^] \"%r\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\"'
ACCESS_LOG="/var/log/httpd/access_log"
LISTEN_ADDR="127.0.0.1"
LISTEN_PORT="7890"
WS_URL_PLACEHOLDER="wss://127.0.0.1/goaccess-ws/"

mkdir -p "$OUT_DIR"

patch_connection() {
	local html="$1"
	[ -f "$html" ] || return 0
	grep -q 'BX_GOACCESS_WS_DYNAMIC' "$html" 2>/dev/null && return 0
	python3 - "$html" <<'PY' 2>/dev/null || true
import re, sys
path = sys.argv[1]
try:
    text = open(path, encoding="utf-8", errors="replace").read()
except OSError:
    sys.exit(0)
# GoAccess buildWSURI():
#   if url already has wss?:// keep it; else prefix wss:// or ws://
#   then: protocol + '//' + hostname + ':' + port + pathname
# So url must be either "host/goaccess-ws/" (no scheme) or full "wss://host/goaccess-ws/".
# NEVER pass "https://host/..." - that becomes wss://https://... -> wss://https:port//host/...
repl = (
    "/* BX_GOACCESS_WS_DYNAMIC */"
    "var connection = (function () {"
    "  var p = window.location.port || (window.location.protocol === 'https:' ? '443' : '80');"
    "  return {"
    "    url: window.location.hostname + '/goaccess-ws/',"
    "    port: parseInt(p, 10) || 81,"
    "    ping_interval: 0"
    "  };"
    "})();"
)
# goaccess often omits trailing semicolon before </script>
new, n = re.subn(
    r"var\s+connection\s*=\s*\{.*?\}\s*;?",
    repl,
    text,
    count=1,
    flags=re.DOTALL,
)
if n:
    open(path, "w", encoding="utf-8").write(new)
PY
}

/usr/bin/goaccess "$ACCESS_LOG" \
	--addr="$LISTEN_ADDR" \
	--port="$LISTEN_PORT" \
	--log-format="$LOG_FORMAT" \
	--date-format=%d/%b/%Y \
	--time-format=%T \
	-o "$OUT_HTML" \
	--real-time-html \
	--ws-url="$WS_URL_PLACEHOLDER" &
GA_PID=$!

while kill -0 "$GA_PID" 2>/dev/null; do
	patch_connection "$OUT_HTML"
	sleep 2
done

wait "$GA_PID"
exit $?
