#!/bin/bash
# Resolve latest phpSysInfo release from GitHub (phpsysinfo/phpsysinfo).
# Prints: VERSION TARBALL_URL SHA256 (sha empty if unavailable)
# Override: PSI_VERSION=3.4.7
set -euo pipefail

REPO="phpsysinfo/phpsysinfo"
API="https://api.github.com/repos/${REPO}/releases/latest"

version="${PSI_VERSION:-}"
if [ -z "$version" ]; then
  version=$(curl -fsSL "$API" 2>/dev/null | python3 -c '
import sys, json
try:
    d = json.load(sys.stdin)
    t = d.get("tag_name") or ""
    if t.startswith("v"):
        t = t[1:]
    print(t)
except Exception:
    pass
' 2>/dev/null || true)
fi

# strip leading v if user passed v3.4.7
version="${version#v}"

if [ -z "$version" ]; then
  echo "ERROR: could not resolve phpSysInfo version" >&2
  exit 1
fi

url="https://github.com/${REPO}/archive/refs/tags/v${version}.tar.gz"
sha=""
# optional: no official sha on GitHub tag archives

echo "$version $url $sha"
