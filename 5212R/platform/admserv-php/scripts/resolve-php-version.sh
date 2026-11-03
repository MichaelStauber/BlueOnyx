#!/bin/bash
# Resolve newest PHP X.Y.Z for a major.minor line from php.net releases JSON.
# Usage:
#   resolve-php-version.sh 8.4              -> prints e.g. 8.4.27
#   resolve-php-version.sh --sha256 8.4     -> sha256 of tar.gz for latest 8.4
#   resolve-php-version.sh --sha256 8.4 8.4.27  -> sha256 for that exact version
set -euo pipefail

MODE=version
if [ "${1:-}" = "--sha256" ]; then
  MODE=sha256
  shift
fi

MAJOR="${1:-8.4}"
PIN="${2:-}"

API_URL="https://www.php.net/releases/index.php?json&version=${MAJOR}&max=20"

json=""
if command -v curl >/dev/null 2>&1; then
  json=$(curl -fsSL --max-time 30 "$API_URL" 2>/dev/null || true)
elif command -v wget >/dev/null 2>&1; then
  json=$(wget -q -O - --timeout=30 "$API_URL" 2>/dev/null || true)
fi

if [ -z "$json" ]; then
  # offline fallback: only works if PIN given or we exit non-zero for latest
  if [ -n "$PIN" ] && [ "$MODE" = "version" ]; then
    echo "$PIN"
    exit 0
  fi
  echo "ERROR: cannot reach php.net releases API ($API_URL)" >&2
  exit 1
fi

export MODE MAJOR PIN JSON="$json"
python3 - <<'PY'
import json, os, re, sys

mode = os.environ["MODE"]
major = os.environ["MAJOR"]
pin = os.environ.get("PIN") or ""
data = json.loads(os.environ["JSON"])

# API shapes:
# max=1 without list: sometimes a single release object with source[]
# max=N: dict keyed by version string "8.4.27" -> {source:[{filename,sha256},...]}

def versions_from(data):
    if isinstance(data, dict) and any(re.match(r"^\d+\.\d+\.\d+$", k) for k in data.keys()):
        return data
    # single-object form: invent key from source filename
    if isinstance(data, dict) and "source" in data:
        for s in data.get("source") or []:
            m = re.match(r"php-(\d+\.\d+\.\d+)\.tar\.gz$", s.get("filename") or "")
            if m:
                return {m.group(1): data}
    return {}

vers = versions_from(data)
if not vers:
    print("ERROR: unexpected php.net JSON", file=sys.stderr)
    sys.exit(1)

def sort_key(v):
    return tuple(int(x) for x in v.split("."))

# only versions matching major.minor prefix
prefix = major if major.count(".") >= 1 else major
cands = [v for v in vers.keys() if v.startswith(prefix + ".") or v == prefix]
if not cands:
    cands = list(vers.keys())
cands.sort(key=sort_key, reverse=True)

def tar_gz_meta(ver):
    entry = vers[ver]
    for s in entry.get("source") or []:
        if (s.get("filename") or "").endswith(".tar.gz"):
            return s
    return {}

if pin:
    if pin not in vers and pin not in cands:
        # still allow sha of pin if present under different key
        if pin not in vers:
            print(f"ERROR: version {pin} not in php.net response", file=sys.stderr)
            sys.exit(1)
    ver = pin
else:
    ver = cands[0]

if mode == "version":
    print(ver)
    sys.exit(0)

meta = tar_gz_meta(ver)
sha = meta.get("sha256") or ""
if not sha:
    print(f"ERROR: no sha256 for php-{ver}.tar.gz", file=sys.stderr)
    sys.exit(1)
print(sha)
PY
