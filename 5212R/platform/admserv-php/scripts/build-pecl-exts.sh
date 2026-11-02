#!/bin/bash
# Build PECL extensions into a staged admserv-php tree (INSTALL_ROOT result).
#
# Staged php-config still reports --prefix=/home/solarspeed/admserv-php (final path).
# On builder10 that path is live 8.3 - PECL must compile against STAGE headers instead.
#
# Usage: build-pecl-exts.sh <STAGE_PREFIX> <ZEND_API> [LIVE_PREFIX]
set -euo pipefail

STAGE="${1:?STAGE prefix}"
ZEND_API="${2:?ZEND_API}"
LIVE_PREFIX="${3:-/home/solarspeed/admserv-php}"

STAGE=$(cd "$STAGE" && pwd)
EXT_DIR="${STAGE}/lib/php/${ZEND_API}"
DESTDIR=$(cd "${STAGE}/../../.." && pwd)
BUILD_ROOT=$(cd "${DESTDIR}/.." && pwd)
WORK="${BUILD_ROOT}/pecl-build"
WRAP="${WORK}/wrap"

test -x "${STAGE}/bin/php-config" || { echo "ERROR: missing ${STAGE}/bin/php-config"; exit 1; }
test -x "${STAGE}/bin/phpize" || { echo "ERROR: missing ${STAGE}/bin/phpize"; exit 1; }
test -d "${STAGE}/include/php" || { echo "ERROR: missing ${STAGE}/include/php"; exit 1; }
mkdir -p "$EXT_DIR" "$WORK" "$WRAP"

# Rewrite LIVE_PREFIX -> STAGE only as a full path segment (never inside STAGE itself).
# Use perl \Q\E; require boundary: start, whitespace, or -I/-L before the live prefix.
rewrite_paths() {
  LIVE_PREFIX="$LIVE_PREFIX" STAGE="$STAGE" perl -pe '
    my $l = $ENV{LIVE_PREFIX};
    my $s = $ENV{STAGE};
    s/(^|[\s=]|-I|-L)\Q$l\E/$1$s/g;
  '
}

# Explicit php-config wrapper (most options used by pecl/phpize)
cat > "${WRAP}/php-config" << EOF
#!/bin/bash
set -euo pipefail
STAGE="${STAGE}"
LIVE_PREFIX="${LIVE_PREFIX}"
ZEND_API="${ZEND_API}"
REAL="${STAGE}/bin/php-config"
rewrite() {
  LIVE_PREFIX="\$LIVE_PREFIX" STAGE="\$STAGE" perl -pe '
    my \$l = \$ENV{LIVE_PREFIX};
    my \$s = \$ENV{STAGE};
    s/(^|[\\s=]|-I|-L)\\Q\$l\\E/\$1\$s/g;
  '
}
case "\${1:-}" in
  --prefix)
    echo "\$STAGE"
    ;;
  --includes)
    echo "-I\${STAGE}/include/php -I\${STAGE}/include/php/main -I\${STAGE}/include/php/TSRM -I\${STAGE}/include/php/Zend -I\${STAGE}/include/php/ext -I\${STAGE}/include/php/ext/date/lib"
    ;;
  --extension-dir)
    echo "\${STAGE}/lib/php/\${ZEND_API}"
    ;;
  --include-dir)
    echo "\${STAGE}/include"
    ;;
  --php-binary)
    echo "\${STAGE}/bin/php"
    ;;
  *)
    "\$REAL" "\$@" | rewrite
    ;;
esac
EOF
chmod 755 "${WRAP}/php-config"

# phpize: copy staged script and rewrite embedded prefix carefully
perl -pe '
  BEGIN { $l = shift; $s = shift; }
  s/(^|[\s=]|-I|-L|\")\Q$l\E/$1$s/g;
' "$LIVE_PREFIX" "$STAGE" \
  < "${STAGE}/bin/phpize" > "${WRAP}/phpize"
chmod 755 "${WRAP}/phpize"

INC=$("${WRAP}/php-config" --includes)
echo "php-config --includes => $INC"
case "$INC" in
  *"${STAGE}/include/php"*) echo "OK: includes use STAGE" ;;
  *)
    echo "ERROR: php-config wrapper includes not under STAGE"
    exit 1
    ;;
esac
# must NOT still reference live-only includes as primary without stage
if echo "$INC" | grep -q "${LIVE_PREFIX}/include" && ! echo "$INC" | grep -q "${STAGE}/include"; then
  echo "ERROR: includes still live-only"
  exit 1
fi

export PATH="${WRAP}:${STAGE}/bin:${PATH}"
export PHP_CONFIG="${WRAP}/php-config"

build_one() {
  local name="$1" version="$2"
  local url="https://pecl.php.net/get/${name}-${version}.tgz"
  local tgz="${WORK}/${name}-${version}.tgz"
  echo "=== PECL ${name} ${version} -> ${EXT_DIR}/${name}.so ==="
  if [ ! -f "$tgz" ]; then
    wget -O "${tgz}.partial" --no-check-certificate "$url"
    mv "${tgz}.partial" "$tgz"
  fi
  rm -rf "${WORK}/${name}-${version}"
  tar -C "$WORK" -xzf "$tgz"
  local dir="${WORK}/${name}-${version}"
  if [ ! -d "$dir" ]; then
    dir=$(find "$WORK" -maxdepth 1 -type d -name "${name}-*" | head -1)
  fi
  (
    cd "$dir"
    if [ -f Makefile ]; then make distclean >/dev/null 2>&1 || true; fi
    "${WRAP}/phpize"
    # Fix any remaining live-prefix path segments in generated files (boundary-safe)
    find . -type f \( -name Makefile -o -name configure -o -name \*.m4 -o -name libtool \) -print0 2>/dev/null \
      | while IFS= read -r -d '' f; do
          LIVE_PREFIX="$LIVE_PREFIX" STAGE="$STAGE" perl -i -pe '
            my $l = $ENV{LIVE_PREFIX};
            my $s = $ENV{STAGE};
            s/(^|[\s=]|-I|-L|\")\Q$l\E/$1$s/g;
          ' "$f" || true
        done
    ./configure --with-php-config="${WRAP}/php-config"
    find . -type f \( -name Makefile -o -name libtool \) -print0 2>/dev/null \
      | while IFS= read -r -d '' f; do
          LIVE_PREFIX="$LIVE_PREFIX" STAGE="$STAGE" perl -i -pe '
            my $l = $ENV{LIVE_PREFIX};
            my $s = $ENV{STAGE};
            s/(^|[\s=]|-I|-L|\")\Q$l\E/$1$s/g;
          ' "$f" || true
        done
    make -j"$(nproc 2>/dev/null || echo 2)"
    install -m 0755 "modules/${name}.so" "${EXT_DIR}/${name}.so"
  )
  rm -rf "$dir"
  test -f "${EXT_DIR}/${name}.so"

  # Load test with staged PHP only (-n ignores ini; absolute extension path)
  if ! "${STAGE}/bin/php" -n -d "extension=${EXT_DIR}/${name}.so" -r "exit(extension_loaded('${name}')?0:1);" 2>/dev/null; then
    echo "ERROR: ${name}.so does not load in staged PHP"
    "${STAGE}/bin/php" -n -d "extension=${EXT_DIR}/${name}.so" -v 2>&1 | head -20 || true
    exit 1
  fi
  # Confirm not built against 8.3 API
  if strings "${EXT_DIR}/${name}.so" | grep -q 'API20230831'; then
    echo "ERROR: ${name}.so still embeds API20230831 (built against live 8.3)"
    exit 1
  fi
  if ! strings "${EXT_DIR}/${name}.so" | grep -q 'API20240924'; then
    echo "WARNING: ${name}.so has no API20240924 string (check manually)"
  fi
  echo "OK ${EXT_DIR}/${name}.so loads in staged PHP (8.4 API)"
}

build_one redis 6.2.0
build_one apcu 5.1.24

ls -la "$EXT_DIR"
echo "PECL extensions done (API-verified against staged PHP)."
