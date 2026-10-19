#!/usr/bin/env bash
#
# Run what .github/workflows/ci.yml runs, on this machine.
#
# The repository is private, so Actions minutes are not something to lean on.
# This script is the same three jobs — lint, the full suite, and the web
# installer walk-through — against whatever PHP binaries and database are here.
#
#   tools/ci-local.sh                    # every php8.x on PATH, else `php`
#   PHP_BINS="php8.1 php8.4" tools/ci-local.sh
#   tools/ci-local.sh --quick            # lint and unit only, no database
#
# The database is the one tests/run.php already uses: CC_TEST_DB_HOST, _PORT,
# _USER and _PASS, defaulting to root with no password on 127.0.0.1:3306. The
# account needs CREATE USER, because the installer job makes an unprivileged
# user the way cPanel would.
#
# WHAT IT DOES NOT COVER. The workflow also runs MySQL 8.0 and 5.7; this runs
# whichever server is listening. If that is MariaDB, MySQL-only regressions
# still need the workflow, or a MySQL server pointed at with CC_TEST_DB_*.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 2

QUICK=0
[ "${1:-}" = "--quick" ] && QUICK=1

DB_HOST="${CC_TEST_DB_HOST:-127.0.0.1}"
DB_PORT="${CC_TEST_DB_PORT:-3306}"
DB_USER="${CC_TEST_DB_USER:-root}"
DB_PASS="${CC_TEST_DB_PASS-}"

if [ -n "${PHP_BINS:-}" ]; then
  read -r -a BINS <<< "$PHP_BINS"
else
  BINS=()
  for candidate in php8.1 php8.2 php8.3 php8.4 php8.5; do
    command -v "$candidate" > /dev/null 2>&1 && BINS+=("$candidate")
  done
  [ ${#BINS[@]} -eq 0 ] && BINS=(php)
fi

RESULTS=()
FAILED=0

record() { # name, exit code
  if [ "$2" -eq 0 ]; then
    RESULTS+=("PASS  $1")
  else
    RESULTS+=("FAIL  $1")
    FAILED=1
  fi
}

section() { printf '\n\033[1m── %s ──\033[0m\n' "$1"; }

summary() {
  section "Summary"
  printf '%s\n' "${RESULTS[@]}"
  exit $FAILED
}

mysql_admin() {
  MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$@"
}

# ── lint ────────────────────────────────────────────────────────────────────

section "Lint: PHP syntax (${BINS[0]})"
lint=0
while IFS= read -r -d '' file; do
  if ! "${BINS[0]}" -l "$file" > /dev/null 2>&1; then
    "${BINS[0]}" -l "$file"
    lint=1
  fi
done < <(find . -name '*.php' -not -path './vendor/*' -not -path './.git/*' -print0)
record "PHP syntax" $lint

section "Lint: JavaScript syntax"
if command -v node > /dev/null 2>&1; then
  js=0
  for file in assets/js/*.js; do
    node --check "$file" || js=1
  done
  record "JavaScript syntax" $js
else
  RESULTS+=("SKIP  JavaScript syntax (no node on PATH)")
fi

section "Unit and static suites (${BINS[0]})"
"${BINS[0]}" tests/run.php --unit --min-tests=670
record "unit + static (${BINS[0]})" $?

[ $QUICK -eq 1 ] && summary

# ── the full suite, once per PHP ────────────────────────────────────────────

mkdir -p storage/{logs,cache,uploads,exports,backups,sessions}

if ! mysql_admin -e 'SELECT 1' > /dev/null 2>&1; then
  echo "No database at ${DB_USER}@${DB_HOST}:${DB_PORT}. Start one, or set CC_TEST_DB_*." >&2
  RESULTS+=("FAIL  database reachable")
  FAILED=1
  summary
fi

SERVER=$(mysql_admin -N -e 'SELECT VERSION()' 2>/dev/null)

for bin in "${BINS[@]}"; do
  version=$("$bin" -r 'echo PHP_VERSION;')
  section "Full suite: PHP ${version} · ${SERVER}"
  "$bin" tests/run.php --require-db --require-http --min-tests=1720
  record "full suite (PHP ${version} · ${SERVER})" $?
done

# ── the installer, from nothing, as an unprivileged cPanel-style user ───────

section "Web installer end-to-end (${BINS[0]})"

# walk-installer.php deletes config/config.php and install/.lock, which on a
# developer's machine are THEIR install. Set both aside and put them back
# whatever happens, including Ctrl-C.
STASH=$(mktemp -d)
for kept in config/config.php install/.lock; do
  [ -f "$kept" ] && cp -p "$kept" "$STASH/$(basename "$kept")"
done
restore() {
  rm -f config/config.php install/.lock
  [ -f "$STASH/config.php" ] && cp -p "$STASH/config.php" config/config.php
  [ -f "$STASH/.lock" ] && cp -p "$STASH/.lock" install/.lock
  rm -rf "$STASH"
}
trap restore EXIT

INSTALL_DB=castle_install_test
INSTALL_USER=castle_ci
INSTALL_PASS=castle-ci-password

mysql_admin <<SQL
DROP DATABASE IF EXISTS \`${INSTALL_DB}\`;
CREATE DATABASE \`${INSTALL_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${INSTALL_USER}'@'%' IDENTIFIED BY '${INSTALL_PASS}';
CREATE USER IF NOT EXISTS '${INSTALL_USER}'@'localhost' IDENTIFIED BY '${INSTALL_PASS}';
GRANT ALL PRIVILEGES ON \`${INSTALL_DB}\`.* TO '${INSTALL_USER}'@'%';
GRANT ALL PRIVILEGES ON \`${INSTALL_DB}\`.* TO '${INSTALL_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

CC_INSTALL_DB_HOST="$DB_HOST" CC_INSTALL_DB_PORT="$DB_PORT" \
CC_INSTALL_DB_NAME="$INSTALL_DB" CC_INSTALL_DB_USER="$INSTALL_USER" \
CC_INSTALL_DB_PASS="$INSTALL_PASS" \
  "${BINS[0]}" tests/install/walk-installer.php
record "web installer (${BINS[0]})" $?

summary
