#!/bin/bash
# Resolve latest phpMyAdmin 5.2.x (stable line) from phpmyadmin.net.
# Prints: VERSION TARBALL_URL SHA256 (sha may be empty if unavailable)
set -euo pipefail

MAJOR_LINE="${PMA_MAJOR_LINE:-5.2}"
JSON_URL="https://www.phpmyadmin.net/home_page/version.json"
TXT_URL="https://www.phpmyadmin.net/home_page/version.txt"

version=""
if command -v curl >/dev/null 2>&1; then
  version=$(curl -fsSL "$JSON_URL" 2>/dev/null | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    # Prefer matching major.minor line if present in releases[]
    want = '${MAJOR_LINE}.'
    for r in d.get('releases', []):
        v = r.get('version', '')
        if v.startswith(want):
            print(v)
            raise SystemExit
    print(d.get('version', ''))
except Exception:
    pass
" 2>/dev/null || true)
fi

if [ -z "$version" ]; then
  version=$(curl -fsSL "$TXT_URL" 2>/dev/null | head -1 | tr -d ' \r' || true)
fi

if [ -z "$version" ]; then
  echo "ERROR: could not resolve phpMyAdmin version" >&2
  exit 1
fi

# all-languages tarball (matches historical BlueOnyx packaging)
url="https://files.phpmyadmin.net/phpMyAdmin/${version}/phpMyAdmin-${version}-all-languages.tar.gz"
sha_url="https://files.phpmyadmin.net/phpMyAdmin/${version}/phpMyAdmin-${version}-all-languages.tar.gz.sha256"

sha=""
sha=$(curl -fsSL "$sha_url" 2>/dev/null | awk '{print $1}' || true)

echo "$version $url $sha"
