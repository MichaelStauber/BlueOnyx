#!/bin/bash
# Inject BlueOnyx sysinfoAccess cookie gate after PSI_APP_ROOT define.
set -euo pipefail
WEB="${1:?web dir}"
INC="${2:?cookie inc file}"
MARKER="define('PSI_APP_ROOT', dirname(__FILE__));"
python3 - "$WEB" "$INC" "$MARKER" <<'PY'
import sys
from pathlib import Path
web = Path(sys.argv[1])
inc = Path(sys.argv[2]).read_text()
marker = sys.argv[3]
for name in ("index.php", "js.php"):
    p = web / name
    if not p.is_file():
        print(f"skip missing {name}")
        continue
    t = p.read_text()
    if "sysinfoAccess" in t:
        print(f"{name}: cookie already present")
        continue
    if marker not in t:
        raise SystemExit(f"{name}: cannot find PSI_APP_ROOT define")
    t = t.replace(marker, marker + "\n\n" + inc.rstrip() + "\n", 1)
    p.write_text(t)
    print(f"{name}: cookie gate injected")
PY
