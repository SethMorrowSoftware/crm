#!/usr/bin/env bash
#
# SessionStart hook — give a Claude Code cloud session a working test database.
#
# `php tests/run.php` needs MySQL or MariaDB: of the ~1,757 tests, only the unit
# and static suites (`--unit`) run without one, and `docs/TESTING.md` asks for a
# real engine run before any push touching a JSON column, a GROUP BY, a
# DISTINCT, or anything near a date or a decimal. A cloud container starts with
# PHP but no database server, so without this hook that is either ten minutes of
# hand-building or a run that quietly skips most of the suite.
#
# WHY NOT DOCKER. `docs/TESTING.md` reaches for `docker run ... mysql:8.0`, and
# that is still the right advice on a laptop. It cannot work here: the Docker
# daemon will not start in a cloud container (`service docker start` fails with
# `ulimit: error setting limit (Operation not permitted)` and `docker info` then
# reports no /var/run/docker.sock). MariaDB from Ubuntu's own archive needs no
# daemon and no image pull, so that is what this installs.
#
# WHY NOT THE PACKAGED SERVICE SCRIPTS. `invoke-rc.d` is refused by
# /usr/sbin/policy-rc.d (it exits 101 for everything) and /etc/init.d/mysql
# hits the same ulimit wall as Docker. The server is therefore started by
# calling `mysqld_safe` directly.
#
# WHICH ENGINE THAT MAKES IT. MariaDB, which is this repository's development
# database and one of CI's four database jobs — so a green run here means the
# same thing a green local run means, and no more. The MySQL-only differences
# `docs/TESTING.md` records (JSON normalising on storage, DISTINCT + ORDER BY
# error 3065) are invisible to a MariaDB run, in a cloud session exactly as on
# a developer's machine. Those still need CI, or a MySQL host.
#
# EXIT CODE. This script exits 0 even when it could not deliver a database, and
# prints what went wrong on stdout instead. SessionStart stdout becomes session
# context, so the session is told plainly that the database is missing and that
# database-backed suites will skip; a non-zero exit would surface the text to
# the human but not reliably into that context, and a session that silently
# gets no database reads as a broken test suite to whoever is in it.

set -uo pipefail

# Only cloud sessions. A developer's own machine has its own database and its
# own my.cnf, and this would fight both.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

# And only a disposable, Anthropic-hosted container. CLAUDE_CODE_REMOTE is also
# true on a self-hosted runner, which is an organisation's own long-lived host
# that may run several sessions at once; installing a server there, rewriting
# an account's authentication and granting ALL ON *.* would be done to that
# host, and parallel sessions would share one castle_crm_test. The hosted
# environment identifies itself as `cloud_default`; anywhere else this needs an
# explicit CASTLE_PROVISION_TEST_DB=1 from whoever owns the machine.
if [ "${CLAUDE_CODE_REMOTE_ENVIRONMENT_TYPE:-}" != "cloud_default" ] \
   && [ "${CASTLE_PROVISION_TEST_DB:-}" != "1" ]; then
  echo "Castle CRM SessionStart hook: stood down without installing anything. This is not an Anthropic-hosted cloud container (CLAUDE_CODE_REMOTE_ENVIRONMENT_TYPE='${CLAUDE_CODE_REMOTE_ENVIRONMENT_TYPE:-}'), and provisioning a database means installing MariaDB and granting root access on this host. Set CASTLE_PROVISION_TEST_DB=1 in the environment if that is wanted here."
  exit 0
fi

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"

# Defaults copied from tests/bootstrap.php, which is the file that decides them.
DB_HOST="${CC_TEST_DB_HOST:-127.0.0.1}"
DB_PORT="${CC_TEST_DB_PORT:-3306}"
DB_NAME="${CC_TEST_DB_NAME:-castle_crm_test}"
DB_USER="${CC_TEST_DB_USER:-root}"
DB_PASS="${CC_TEST_DB_PASS-}"

# The account the server is administered through, which is not necessarily the
# one the suite connects as. A fresh MariaDB install authenticates
# 'root'@'localhost' via unix_socket, so running as the container's root user is
# what lets this script issue the grants below in the first place; that is the
# bootstrap and it is always root, whatever CC_TEST_DB_USER says.
ADMIN_USER=root

# An empty password is the only thing this hook can provision, and that is
# deliberate rather than an omission: when the suite's user is root — the
# default — setting a password on it would lock this script out of the very
# socket connection it uses to issue its own grants, so supporting one means a
# second credential path for no gain in a container nobody else can reach. A
# session that arrives with CC_TEST_DB_PASS set is pointed at a database this
# hook does not own, so it stands down before installing anything.
if [ -n "$DB_PASS" ]; then
  echo "Castle CRM SessionStart hook: stood down without installing anything. CC_TEST_DB_PASS is set, so this session is pointed at a database this hook does not manage — it only provisions the suite's default, which is ${DB_USER} with an empty password on a local MariaDB. If that database is reachable, \`php tests/run.php\` will use it as it stands."
  exit 0
fi

LOG_DIR="${PROJECT_DIR}/storage/logs"
LOG="${LOG_DIR}/session-start-hook.log"

mkdir -p "$LOG_DIR" 2>/dev/null || true
: > "$LOG" 2>/dev/null || LOG=/dev/null

log() { printf '[%s] %s\n' "$(date -u '+%H:%M:%S')" "$*" >> "$LOG" 2>/dev/null || true; }

# Report a failure to both the session (stdout) and the human (stderr), then
# stand down without taking the session with us.
give_up() {
  local what="$1" detail="$2"
  local msg
  msg=$(cat <<EOF
Castle CRM SessionStart hook: NO TEST DATABASE.

  Failed at: ${what}
  ${detail}

  Consequence: \`php tests/run.php\` will report "Database unavailable" and skip
  every database-backed suite — about 1,080 of ~1,765 tests, including all of
  tests/integration and tests/http. That is a hook problem, not a test-suite
  problem. \`php tests/run.php --unit\` is unaffected and still meaningful.
  \`php tests/run.php --require-db\` turns the skip into a hard failure, which is
  the honest way to run while this is broken.

  Full log: ${LOG}
  To retry by hand: ${PROJECT_DIR}/.claude/hooks/session-start.sh
EOF
)
  printf '%s\n' "$msg"
  printf '%s\n' "$msg" >&2
  exit 0
}

# ---------------------------------------------------------------------------
# 1. Install the server
#
# `apt-get update` first is not optional: mariadb-server is not an available
# package without it on a fresh container.
# ---------------------------------------------------------------------------
if ! command -v mysqld_safe > /dev/null 2>&1; then
  log "installing mariadb-server"
  if ! apt-get update >> "$LOG" 2>&1; then
    give_up "apt-get update" "The package lists could not be refreshed; check network access to the Ubuntu archive."
  fi
  if ! DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      mariadb-server mariadb-client >> "$LOG" 2>&1; then
    give_up "apt-get install mariadb-server mariadb-client" "The install failed. The tail of the log usually names the package that broke."
  fi
  log "mariadb installed"
else
  log "mariadb already present"
fi

command -v mysqld_safe > /dev/null 2>&1 \
  || give_up "locating mysqld_safe" "mariadb-server reports as installed but mysqld_safe is not on PATH."

# ---------------------------------------------------------------------------
# 2. Start it
#
# The socket directory is normally made by the service script that cannot run
# here, so make it before starting. Everything below is idempotent: on a
# resumed session the server is usually already up and this falls straight
# through to the checks.
# ---------------------------------------------------------------------------
if ! mysqladmin --protocol=socket -u"$ADMIN_USER" ping > /dev/null 2>&1; then
  mkdir -p /var/run/mysqld
  chown mysql:mysql /var/run/mysqld

  # The package's postinst normally initialises the data directory, but it runs
  # the same denied service machinery, so check rather than assume.
  if [ ! -d /var/lib/mysql/mysql ]; then
    log "initialising the data directory"
    mysql_install_db --user=mysql --datadir=/var/lib/mysql >> "$LOG" 2>&1 \
      || give_up "mysql_install_db" "The data directory at /var/lib/mysql could not be initialised."
  fi

  log "starting mysqld_safe"
  # setsid + nohup so the server outlives this hook process.
  setsid nohup mysqld_safe --skip-syslog < /dev/null >> "$LOG" 2>&1 &
  disown 2>/dev/null || true

  # Poll rather than sleep a fixed time. It came up in about two seconds on the
  # container this was written against; the ceiling is for a slow one.
  up=
  for _ in $(seq 1 60); do
    if mysqladmin --protocol=socket -u"$ADMIN_USER" ping > /dev/null 2>&1; then
      up=yes
      break
    fi
    sleep 1
  done
  [ -n "$up" ] || give_up "waiting for mysqld to accept connections" \
    "The server did not answer \`mysqladmin ping\` within 60 seconds. The log holds mysqld's own startup output."
  log "server is up"
else
  log "server already running"
fi

# ---------------------------------------------------------------------------
# 3. Make the suite's account reachable over TCP
#
# This is the step with the trap. tests/bootstrap.php connects as root over
# 127.0.0.1 with no password, and PDO fails with
#
#   SQLSTATE[HY000] [1698] Access denied for user 'root'@'localhost'
#
# unless the `…@'localhost'` account itself is switched off unix_socket
# authentication: the server resolves a 127.0.0.1 connection back to
# `localhost` and matches that account, so creating `…@'127.0.0.1'` alone
# changes nothing and the `mysql` CLI keeps working (over its socket) while PHP
# keeps failing. Setting the plugin on the `localhost` account is the fix. The
# literal-host account is created too, so the same holds if the server is ever
# started with --skip-name-resolve.
#
# The password is empty, which is what the suite defaults to and all a
# throwaway database inside an ephemeral container needs. Nothing secret is
# created here and nothing is written to the repository.
# ---------------------------------------------------------------------------
sql() { mysql --protocol=socket -u"$ADMIN_USER" "$@" 2>> "$LOG"; }

log "granting ${DB_USER} access over TCP"
for host in localhost 127.0.0.1; do
  sql <<SQL || give_up "granting ${DB_USER}@${host} access over TCP" \
    "The CREATE USER/ALTER USER/GRANT statements were refused. The log holds the server's own error."
CREATE USER IF NOT EXISTS '${DB_USER}'@'${host}' IDENTIFIED VIA mysql_native_password USING PASSWORD('');
ALTER USER '${DB_USER}'@'${host}' IDENTIFIED VIA mysql_native_password USING PASSWORD('');
GRANT ALL PRIVILEGES ON *.* TO '${DB_USER}'@'${host}' WITH GRANT OPTION;
SQL
done

# ON *.* rather than ON `castle_crm_test`.*: tests/bootstrap.php reads
# information_schema to enumerate tables for test_drop_all(), and the installer
# walk creates its own database.
sql <<SQL || give_up "creating the ${DB_NAME} database" "CREATE DATABASE was refused. The log holds the server's own error."
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
FLUSH PRIVILEGES;
SQL

# Prove it the way the suite will: over TCP, through PDO, as the suite's user.
# `mysql` succeeding over its socket is exactly the false positive this hook
# exists to avoid, so the check has to be the real connection.
CC_PDO_HOST="$DB_HOST" CC_PDO_PORT="$DB_PORT" CC_PDO_NAME="$DB_NAME" \
CC_PDO_USER="$DB_USER" CC_PDO_PASS="$DB_PASS" \
php -r '
try {
    new PDO(
        sprintf("mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4", getenv("CC_PDO_HOST"), (int) getenv("CC_PDO_PORT"), getenv("CC_PDO_NAME")),
        getenv("CC_PDO_USER"),
        (string) getenv("CC_PDO_PASS"),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}' >> "$LOG" 2>&1 \
  || give_up "connecting as the test suite does (PDO, ${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME})" \
     "The server is running and the \`mysql\` client works, but PDO was refused. The log holds the SQLSTATE."

# ---------------------------------------------------------------------------
# 4. Timezone tables — best effort, never fatal
#
# App\Services\LocalDay probes CONVERT_TZ with a named zone and picks its mode
# from the answer: `named` when the tables are loaded (fully correct across a
# DST boundary) and `offset` when they are not (today's offset applied to every
# row). Both modes are supported and both are meant to be runnable, so a
# failure here is reported and not raised. Loading them makes a cloud session
# match a correctly configured production host.
#
# The generator's output goes to a file first; piping it straight into `mysql`
# failed on the container this was written against.
# ---------------------------------------------------------------------------
# A single-value query, reduced to the one thing the caller wants to branch on.
# Anything that is not the expected answer — an error, an empty result, a
# version that does not know the column — reads as "no".
sql_says() {
  local want="$1" query="$2" got
  got="$(sql -N -B -e "$query" 2>/dev/null)" || return 1
  [ "$got" = "$want" ]
}

if ! sql_says 1 "SELECT COUNT(*) > 0 FROM mysql.time_zone_name"; then
  log "loading timezone tables"
  TZ_SQL="$(mktemp -t castle-tz-XXXXXX.sql)"
  if mysql_tzinfo_to_sql /usr/share/zoneinfo > "$TZ_SQL" 2>> "$LOG" && [ -s "$TZ_SQL" ]; then
    sql mysql < "$TZ_SQL" >> "$LOG" 2>&1 || log "feeding the timezone tables to the server failed"
  else
    log "mysql_tzinfo_to_sql produced nothing"
  fi
  rm -f "$TZ_SQL"
fi

# Report the mode the way LocalDay decides it, rather than inferring it from
# whether the load command exited 0.
if sql_says 1 "SELECT CONVERT_TZ('2024-07-01 12:00:00','UTC','America/New_York') IS NOT NULL"; then
  TZ_MODE="named"
  TZ_NOTE=""
else
  TZ_MODE="offset"
  TZ_NOTE=" (timezone tables are not loaded, so LocalDay falls back to today's fixed offset; see ${LOG})"
fi

# ---------------------------------------------------------------------------
# 5. Session environment
#
# The values match tests/bootstrap.php's own defaults, so the suite would find
# the database without them. They are exported anyway so a run is explicit
# about what it connected to and does not depend on those defaults staying put.
# ---------------------------------------------------------------------------
if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
  {
    echo "export CC_TEST_DB_HOST=\"${DB_HOST}\""
    echo "export CC_TEST_DB_PORT=\"${DB_PORT}\""
    echo "export CC_TEST_DB_NAME=\"${DB_NAME}\""
    echo "export CC_TEST_DB_USER=\"${DB_USER}\""
    echo "export CC_TEST_DB_PASS=\"${DB_PASS}\""
  } >> "$CLAUDE_ENV_FILE"
fi

# The suite writes logs, caches and uploads; CI makes these too.
#
# The directories only, and deliberately not `chmod -R`: git does not track a
# directory's mode but it does track a file's executable bit, so recursing here
# flips the mode on the tracked `.htaccess`, `.gitkeep` and `index.html` guards
# inside storage/ and leaves nine files showing as modified in `git status`
# before the session has done anything. CI can afford that on a throwaway
# checkout; a session someone is about to commit from cannot.
mkdir -p "${PROJECT_DIR}"/storage/{logs,cache,uploads,exports,backups,sessions}
chmod 0775 "${PROJECT_DIR}/storage" "${PROJECT_DIR}"/storage/{logs,cache,uploads,exports,backups,sessions} 2>/dev/null || true

SERVER_VERSION="$(sql -N -B -e 'SELECT VERSION()' 2>/dev/null || echo 'unknown')"

echo "Test database ready: MariaDB ${SERVER_VERSION}, ${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME}, LocalDay mode \`${TZ_MODE}\`.${TZ_NOTE} \`php tests/run.php\` needs no further setup. MySQL-only behaviour (JSON normalisation, DISTINCT + ORDER BY) is still only covered by CI."
