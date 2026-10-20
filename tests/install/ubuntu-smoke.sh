#!/usr/bin/env bash
#
# Prove a server prepared by install.sh actually works: walk the eight-step web
# installer with curl, sign in as the owner it created, check that what must
# stay private does, and run cron once as the web server would.
#
#   sudo tests/install/ubuntu-smoke.sh                       # http://127.0.0.1, /var/www/castle-crm
#   sudo tests/install/ubuntu-smoke.sh http://127.0.0.1 /var/www/castle-crm
#
# For CI and for a fresh test server ONLY: it completes the installer with a
# made-up venue and owner, which is exactly what a real install must not have.
# It refuses to run once the installer has finished.

set -euo pipefail

BASE="${1:-http://127.0.0.1}"
APP_DIR="${2:-/var/www/castle-crm}"
CREDENTIALS_FILE="${CREDENTIALS_FILE:-/root/castle-crm-database.txt}"

OWNER_EMAIL=owner@castle.test
OWNER_PASSWORD='smoke-test passphrase 2026'

fail() { echo "FAIL: $1" >&2; exit 1; }
pass() { echo "  ok  $1"; }

[ -f "$CREDENTIALS_FILE" ] || fail "$CREDENTIALS_FILE is missing; run install.sh first."
[ ! -f "$APP_DIR/install/.lock" ] || fail "The installer has already finished on this server; refusing to walk it again."

cred() { sed -n "s/^$1=//p" "$CREDENTIALS_FILE"; }

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
jar="$work/cookies"

# GET a page; print the body.
get() { curl -sS -b "$jar" -c "$jar" "$BASE$1"; }

# The status code alone.
status() { curl -sS -o /dev/null -w '%{http_code}' -b "$jar" -c "$jar" "$BASE$1"; }

token_from() { grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'; }

# POST an installer step; expect a redirect to the next one.
post_step() {
  local step="$1" next="$2"; shift 2
  local token location
  token="$(get "/install/index.php?step=$step" | token_from)"
  [ -n "$token" ] || fail "no form token on installer step $step"
  location="$(curl -sS -o "$work/body" -w '%{redirect_url}' -b "$jar" -c "$jar" \
    --data-urlencode "_token=$token" "$@" "$BASE/install/index.php?step=$step")"
  if [[ "$location" != *"step=$next"* ]]; then
    grep -o '<li>[^<]*</li>\|class="[a-z-]*error[^"]*"[^>]*>[^<]*' "$work/body" | head -5 >&2 || true
    fail "installer step $step did not move on to step $next"
  fi
  pass "installer step $step"
}

echo "Walking the installer at $BASE/install/"

[ "$(status /install/)" = 200 ] || fail "/install/ is not reachable"

post_step 1 2
post_step 2 3
post_step 3 4 \
  --data-urlencode "db_host=$(cred host)" \
  --data-urlencode "db_port=$(cred port)" \
  --data-urlencode "db_name=$(cred database)" \
  --data-urlencode "db_user=$(cred username)" \
  --data-urlencode "db_pass=$(cred password)" \
  --data-urlencode "db_prefix=$(cred table_prefix)"
post_step 4 5
post_step 5 6 \
  --data-urlencode "venue_name=Smoke Test Venue" \
  --data-urlencode "venue_email=venue@castle.test" \
  --data-urlencode "timezone=America/New_York" \
  --data-urlencode "tax_rate=8.125"
post_step 6 7 \
  --data-urlencode "owner_name=Smoke Owner" \
  --data-urlencode "owner_email=$OWNER_EMAIL" \
  --data-urlencode "owner_password=$OWNER_PASSWORD" \
  --data-urlencode "owner_password_confirmation=$OWNER_PASSWORD"
post_step 7 8 -d seed_venue=1 -d seed_playbooks=1 -d seed_demo=1
post_step 8 8

[ -f "$APP_DIR/config/config.php" ] || fail "config/config.php was not written"
[ -f "$APP_DIR/install/.lock" ] || fail "install/.lock was not written"
pass "config written and installer locked"

get "/install/index.php?step=8" | grep -q '\* \* \* \* \*' || fail "the finish page (step 8) is not shown to the session that finished"
pass "finish page still shown to the operator"

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
