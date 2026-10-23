#!/usr/bin/env bash
#
# Prove a server installed by install.sh actually works: the installer is
# closed, what must stay private does, the owner it created can sign in, and
# cron runs as the web server would run it.
#
#   sudo tests/install/ubuntu-smoke.sh                            # http://localhost, /var/www/castle-crm
#   sudo tests/install/ubuntu-smoke.sh http://localhost /var/www/castle-crm
#
# For CI and for a fresh test server ONLY: it expects the made-up owner CI
# installs with, which is exactly what a real install must not have.

set -euo pipefail

BASE="${1:-http://localhost}"
APP_DIR="${2:-/var/www/castle-crm}"

OWNER_EMAIL="${OWNER_EMAIL:-owner@castle.test}"
OWNER_PASSWORD="${OWNER_PASSWORD:-smoke-test passphrase 2026}"

fail() { echo "FAIL: $1" >&2; exit 1; }
pass() { echo "  ok  $1"; }

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
jar="$work/cookies"

# GET a page; print the body.
get() { curl -sS -b "$jar" -c "$jar" "$BASE$1"; }

# The status code alone.
status() { curl -sS -o /dev/null -w '%{http_code}' -b "$jar" -c "$jar" "$BASE$1"; }

token_from() { grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'; }

echo "Checking the install at $BASE"

[ -f "$APP_DIR/config/config.php" ] || fail "config/config.php was not written"
[ -f "$APP_DIR/install/.lock" ] || fail "install/.lock was not written"
[ "$(stat -c %a "$APP_DIR/config/config.php")" = 640 ] || fail "config/config.php is not 0640"
pass "config written, not world-readable, and installer locked"

echo "Checking what must stay private"

expect_hidden() {
  local code
  code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE$1")"
  case "$code" in
    403|404) pass "$1 → $code" ;;
    *) fail "$1 answered $code; it must be hidden" ;;
  esac
}

expect_hidden /install/
expect_hidden /install/index.php
expect_hidden /config/config.php
expect_hidden /.git/config
expect_hidden /install.sh
expect_hidden /tests/run.php
expect_hidden /tools/cli-install.php
expect_hidden /storage/logs/
expect_hidden /install/schema/001_core.sql

echo "Signing in"

token="$(get /login | token_from)"
[ -n "$token" ] || fail "no form token on /login"
location="$(curl -sS -o /dev/null -w '%{redirect_url}' -b "$jar" -c "$jar" \
  --data-urlencode "_token=$token" \
  --data-urlencode "email=$OWNER_EMAIL" \
  --data-urlencode "password=$OWNER_PASSWORD" \
  "$BASE/login")"
[[ "$location" != *"/login"* ]] || fail "sign-in was refused"
[ "$(status /)" = 200 ] || fail "the dashboard did not load after signing in"
get / | grep -q 'Smoke Owner\|Smoke Test Venue' || fail "the dashboard does not show the venue or the owner"
pass "owner signed in and the dashboard loaded"
[ "$(status /contacts)" = 200 ] || fail "/contacts did not load; is URL rewriting on?"
pass "pretty URLs route through index.php"

echo "Running cron as the web server"

grep -q 'www-data .*cron.php' /etc/cron.d/castle-crm || fail "/etc/cron.d/castle-crm is missing or wrong"
runuser -u www-data -- php "$APP_DIR/cron.php" > "$work/cron.out" 2>&1 \
  || { cat "$work/cron.out" >&2; fail "cron.php failed"; }
pass "cron.php ran as www-data"

echo "All checks passed."
