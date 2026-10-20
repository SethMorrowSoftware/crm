#!/usr/bin/env bash
#
# Castle CRM: prepare a plain Ubuntu server and hand over to the web installer.
#
#   git clone https://github.com/SethMorrowSoftware/crm.git
#   cd crm
#   sudo ./install.sh                                   # asks for anything it needs
#   sudo ./install.sh --domain crm.example.com --email you@example.com --yes
#
# The cPanel route (docs/INSTALL.md) assumes the host already provides a web
# server, PHP, a database and a cron screen. On a bare Ubuntu server none of
# that exists, so this script supplies it and then stops at exactly the point a
# cPanel install starts: the eight-step installer at /install/. The venue,
# the owner's password and the seed choices are still made there, in a
# browser, so there is one installer and not two that could drift apart.
#
# What it does, in order. Each step is safe to repeat; re-running the script
# after a failure, or after `git pull`, picks up where things stand.
#
#   1. Installs Apache, PHP (8.1+) with the extensions the installer checks,
#      MariaDB (unless a MySQL or MariaDB server is already installed), cron,
#      and certbot when a domain is given.
#   2. Copies this checkout to --dir (default /var/www/castle-crm), .git and
#      all, so upgrades are `sudo git pull` there. Running the script from
#      inside --dir installs in place.
#   3. Makes the code owned by root and only config/, storage/ and install/
#      writable by the web server, so a compromised request cannot rewrite
#      the application.
#   4. Creates a database and a user with a random password, and saves both to
#      /root/castle-crm-database.txt for step 3 of the web installer. Skipped
#      once config/config.php exists, so a re-run never rotates a live
#      password.
#   5. Writes an Apache site with AllowOverride All (the app's .htaccess does
#      the real work), and closes /install/ to the web once setup has finished.
#   6. Writes /etc/cron.d/castle-crm, which runs cron.php every minute as the
#      web server's user. Until the installer finishes, cron.php exits at once.
#   7. With --domain and --email, asks Let's Encrypt for a certificate.
#
# Supported: Ubuntu 22.04 and 24.04 LTS (PHP 8.1 and 8.3 from the Ubuntu
# archive). Other releases are refused unless --force is given.

set -euo pipefail

# ---------------------------------------------------------------------------
# Defaults and arguments
# ---------------------------------------------------------------------------

APP_DIR=/var/www/castle-crm
DOMAIN=""
EMAIL=""
DB_NAME=castle_crm
DB_USER=castle_crm
ASSUME_YES=0
FORCE=0
SKIP_SSL=0
CREDENTIALS_FILE=/root/castle-crm-database.txt
SITE_NAME=castle-crm

usage() {
  cat <<'USAGE'
Usage: sudo ./install.sh [options]

  --domain NAME     The address staff will use, e.g. crm.example.com. Its DNS
                    must already point at this server for HTTPS to work.
                    Leave it out to serve on this server's IP address.
  --email ADDRESS   Where Let's Encrypt sends expiry warnings. Needed for HTTPS.
  --no-ssl          Do not request a certificate, even with --domain.
  --dir PATH        Where the application lives (default /var/www/castle-crm).
  --db-name NAME    Database name (default castle_crm).
  --db-user NAME    Database user (default castle_crm).
  --yes             Do not ask questions; use the options given.
  --force           Run on an Ubuntu release this script was not tested on.
  -h, --help        Show this help.
USAGE
}

while [ $# -gt 0 ]; do
  case "$1" in
    --domain)   DOMAIN="${2:-}"; shift 2 ;;
    --email)    EMAIL="${2:-}"; shift 2 ;;
    --no-ssl)   SKIP_SSL=1; shift ;;
    --dir)      APP_DIR="${2:-}"; shift 2 ;;
    --db-name)  DB_NAME="${2:-}"; shift 2 ;;
    --db-user)  DB_USER="${2:-}"; shift 2 ;;
    --yes|-y)   ASSUME_YES=1; shift ;;
    --force)    FORCE=1; shift ;;
    -h|--help)  usage; exit 0 ;;
    *)          echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
  esac
done

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------

if [ -t 1 ]; then
  BOLD=$'\033[1m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RED=$'\033[31m'; RESET=$'\033[0m'
else
  BOLD=""; GREEN=""; YELLOW=""; RED=""; RESET=""
fi

step() { printf '\n%s==> %s%s\n' "$BOLD" "$1" "$RESET"; }
ok()   { printf '%s    ok%s  %s\n' "$GREEN" "$RESET" "$1"; }
warn() { printf '%s  warn%s  %s\n' "$YELLOW" "$RESET" "$1" >&2; }
die()  { printf '%s error%s  %s\n' "$RED" "$RESET" "$1" >&2; exit 1; }

ask() {
  # ask VAR "Question" — leaves VAR alone when not interactive.
  local var="$1" question="$2" answer
  if [ "$ASSUME_YES" -eq 1 ] || [ ! -t 0 ]; then
    return
  fi
  read -r -p "$question " answer
  printf -v "$var" '%s' "$answer"
}

# Services: systemd on a real server, the SysV wrappers in a container.
svc() {
  local action="$1" name="$2"
  if [ -d /run/systemd/system ]; then
    systemctl "$action" "$name"
  else
    service "$name" "$action"
  fi
}

svc_enable() {
  if [ -d /run/systemd/system ]; then
    systemctl enable --now "$1" >/dev/null 2>&1 || systemctl start "$1"
  else
    service "$1" status >/dev/null 2>&1 || service "$1" start
  fi
}

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"

[ "$(id -u)" -eq 0 ] || die "Run this as root: sudo ./install.sh"

if [ ! -f "$SOURCE_DIR/index.php" ] || [ ! -f "$SOURCE_DIR/install/index.php" ]; then
  die "Run install.sh from a Castle CRM checkout; $SOURCE_DIR does not look like one."
fi

# shellcheck disable=SC1091
. /etc/os-release
if [ "${ID:-}" != "ubuntu" ]; then
  [ "$FORCE" -eq 1 ] || die "This script is for Ubuntu; found ${PRETTY_NAME:-an unknown system}. --force to try anyway."
  warn "Not Ubuntu (${PRETTY_NAME:-unknown}); continuing because of --force."
fi
case "${VERSION_ID:-}" in
  22.04|24.04) ;;
  *)
    [ "$FORCE" -eq 1 ] || die "Tested on Ubuntu 22.04 and 24.04; found ${VERSION_ID:-unknown}. --force to try anyway."
    warn "Ubuntu ${VERSION_ID:-unknown} is untested; continuing because of --force."
    ;;
esac

for name in "$DB_NAME" "$DB_USER"; do
  [[ "$name" =~ ^[A-Za-z0-9_]{1,32}$ ]] || die "Database and user names may use letters, digits and _ only (max 32): $name"
done

case "$APP_DIR" in
  /*) ;;
  *) die "--dir must be an absolute path." ;;
esac
APP_DIR="${APP_DIR%/}"

if [ -z "$DOMAIN" ]; then
  ask DOMAIN "Domain name for the CRM (e.g. crm.example.com), or Enter to use this server's IP:"
fi
DOMAIN="${DOMAIN,,}"
if [ -n "$DOMAIN" ] && ! [[ "$DOMAIN" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]]; then
  die "That does not look like a domain name: $DOMAIN"
fi
if [ -n "$DOMAIN" ] && [ "$SKIP_SSL" -eq 0 ] && [ -z "$EMAIL" ]; then
  ask EMAIL "Email for Let's Encrypt certificate notices (Enter to skip HTTPS for now):"
fi
if [ -n "$EMAIL" ] && ! [[ "$EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]; then
  die "That does not look like an email address: $EMAIL"
fi

USE_SSL=0
if [ -n "$DOMAIN" ] && [ -n "$EMAIL" ] && [ "$SKIP_SSL" -eq 0 ]; then
  USE_SSL=1
fi

printf '\n%sCastle CRM installer for Ubuntu%s\n' "$BOLD" "$RESET"
printf '  Application:  %s\n' "$APP_DIR"
address_label="${DOMAIN:-this server, by IP address}"
printf '  Address:      %s\n' "$address_label"
printf '  HTTPS:        %s\n' "$([ "$USE_SSL" -eq 1 ] && echo "Let's Encrypt, notices to $EMAIL" || echo 'not now')"
printf '  Database:     %s (user %s)\n' "$DB_NAME" "$DB_USER"

if [ "$ASSUME_YES" -eq 0 ] && [ -t 0 ]; then
  read -r -p "Continue? [Y/n] " reply
  case "${reply,,}" in n|no) echo "Stopped; nothing was changed."; exit 1 ;; esac
fi

# ---------------------------------------------------------------------------
# 1. Packages
# ---------------------------------------------------------------------------

step "Installing packages"

export DEBIAN_FRONTEND=noninteractive

packages=(
  apache2 libapache2-mod-php
  php-cli php-mysql php-mbstring php-curl php-xml php-zip php-gd php-intl php-apcu
  cron curl ca-certificates git
)

# An existing MySQL or MariaDB server is used as it is: installing MariaDB
# over MySQL would remove it, and whatever else uses it with it.
if command -v mysqld >/dev/null 2>&1 || command -v mariadbd >/dev/null 2>&1; then
  ok "A MySQL/MariaDB server is already installed; using it."
else
  packages+=(mariadb-server)
fi

if [ "$USE_SSL" -eq 1 ]; then
  packages+=(certbot python3-certbot-apache)
fi

apt-get update -q
apt-get install -y -q --no-install-recommends "${packages[@]}"
ok "Packages installed."

PHP_BIN="$(command -v php)"
php_ok=$("$PHP_BIN" -r 'echo PHP_VERSION_ID >= 80100 ? "yes" : "no";')
[ "$php_ok" = "yes" ] || die "Castle CRM needs PHP 8.1 or newer; this server has $("$PHP_BIN" -r 'echo PHP_VERSION;')."

missing=()
for ext in pdo_mysql mbstring json openssl curl fileinfo; do
  "$PHP_BIN" -m | grep -qix "$ext" || missing+=("$ext")
done
[ "${#missing[@]}" -eq 0 ] || die "PHP is missing required extensions: ${missing[*]}"
ok "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') with every required extension."

# The module and the CLI must be the same PHP, or the installer passes its
# checks under one version and cron runs under another.
PHP_MINOR=$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')

# libapache2-mod-php needs the prefork MPM; Ubuntu switches to it on install,
# but a server that already ran Apache with php-fpm may still have event.
a2dismod -q mpm_event >/dev/null 2>&1 || true
a2enmod -q mpm_prefork "php${PHP_MINOR}" rewrite headers expires deflate setenvif >/dev/null

# ---------------------------------------------------------------------------
# 2. Application files
# ---------------------------------------------------------------------------

step "Placing the application in $APP_DIR"

if [ "$SOURCE_DIR" = "$APP_DIR" ]; then
  ok "Installing in place."
elif [ -e "$APP_DIR" ] && [ -n "$(ls -A "$APP_DIR" 2>/dev/null)" ]; then
  if [ -f "$APP_DIR/install/index.php" ] && [ -f "$APP_DIR/index.php" ]; then
    # Never copy over an existing install: it may be newer, or have a config.
    ok "Castle CRM is already in $APP_DIR; leaving its files alone."
    warn "To upgrade it, run: cd $APP_DIR && sudo git pull && sudo ./install.sh"
  else
    die "$APP_DIR exists and is not empty. Choose another --dir, or empty it."
  fi
else
  mkdir -p "$APP_DIR"
  # cp -a keeps .git, so the copy can be upgraded with `git pull`, and keeps
  # the storage guards (.htaccess, index.html) that ship inside storage/.
  cp -a "$SOURCE_DIR"/. "$APP_DIR"/
  ok "Copied $SOURCE_DIR to $APP_DIR."
fi

WEB_USER=www-data

# Code belongs to root; the web server reads it and writes only where it must.
chown -R root:root "$APP_DIR"
# Readable by all, writable by root alone; X keeps the executable bits git
# tracks, so the checkout does not show every script as modified.
chmod -R u+rwX,go+rX,go-w "$APP_DIR"

for dir in storage/logs storage/cache storage/uploads storage/exports storage/backups storage/sessions; do
  mkdir -p "$APP_DIR/$dir"
done
# config/ for config.php, install/ for the lock file the installer writes
# when it finishes, storage/ for everything at run time.
chown -R "$WEB_USER:$WEB_USER" "$APP_DIR/storage"
find "$APP_DIR/storage" -type d -exec chmod 775 {} +
for dir in config install; do
  chown "root:$WEB_USER" "$APP_DIR/$dir"
  chmod 2775 "$APP_DIR/$dir"
done
[ -f "$APP_DIR/config/config.php" ] && chown "$WEB_USER:$WEB_USER" "$APP_DIR/config/config.php" && chmod 640 "$APP_DIR/config/config.php"
[ -f "$APP_DIR/install/.lock" ] && chown "$WEB_USER:$WEB_USER" "$APP_DIR/install/.lock"

# git refuses to work in a repository owned by somebody else; root owns this
# one, and `sudo git pull` is how it is upgraded.
if [ -d "$APP_DIR/.git" ]; then
  git config --system --get-all safe.directory 2>/dev/null | grep -qxF "$APP_DIR" \
    || git config --system --add safe.directory "$APP_DIR"
fi
ok "Code is root's; config/, install/ and storage/ are writable by $WEB_USER."

# ---------------------------------------------------------------------------
# 3. Database
# ---------------------------------------------------------------------------

step "Database"

if ! svc_enable mariadb >/dev/null 2>&1; then
  svc_enable mysql >/dev/null 2>&1 || true
fi

for _ in $(seq 1 30); do
  mysqladmin ping --silent >/dev/null 2>&1 && break
  sleep 1
done
mysqladmin ping --silent >/dev/null 2>&1 || die "The database server is not running. Check: journalctl -u mariadb"

DB_PASS=""
if [ -f "$APP_DIR/config/config.php" ]; then
  ok "config/config.php exists, so the installer has already run; database left alone."
else
  # As root over the local socket, which Ubuntu's MariaDB and MySQL both allow.
  mysql -e "SELECT 1" >/dev/null 2>&1 \
    || die "Could not reach the database as root over the local socket. If root has a password, run: sudo mysql -p, create the database and user yourself, and re-run with --db-name/--db-user."

  DB_PASS="$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32)"

  # The user is created for localhost and 127.0.0.1 alike, because the web
  # installer's default host "localhost" means the socket and a hand-typed
  # 127.0.0.1 means TCP, and both should work.
  mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

  umask 077
  cat > "$CREDENTIALS_FILE" <<CREDS
# Castle CRM database, created by install.sh on $(date -u +%Y-%m-%dT%H:%M:%SZ).
# Enter these on step 3 of the web installer. Once setup has finished they
# live in $APP_DIR/config/config.php and this file can be deleted.
host=localhost
port=3306
database=${DB_NAME}
username=${DB_USER}
password=${DB_PASS}
table_prefix=cc_
CREDS
  umask 022
  ok "Database ${DB_NAME} and user ${DB_USER} ready; details saved to ${CREDENTIALS_FILE}."
fi

# ---------------------------------------------------------------------------
# 4. Apache
# ---------------------------------------------------------------------------

step "Web server"

SITE_FILE="/etc/apache2/sites-available/${SITE_NAME}.conf"
SERVER_NAME_LINE="ServerName ${DOMAIN:-localhost}"

cat > "$SITE_FILE" <<SITE
# Castle CRM. Written by install.sh; re-running it rewrites this file.
#
# The application's own .htaccess carries the routing, the denials and the
# security headers, so this site only has to let it.
<VirtualHost *:80>
    ${SERVER_NAME_LINE}
    DocumentRoot ${APP_DIR}

    <Directory ${APP_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # The checkout's history. .htaccess hides dotted paths too; this does not
    # depend on it.
    <DirectoryMatch "^${APP_DIR}/\.git">
        Require all denied
    </DirectoryMatch>

    # On cPanel the operator deletes install/ when setup is done. Here it stays,
    # because it holds the schema and \`git pull\` would bring it straight back,
    # so the web is closed to it instead the moment the lock file appears.
    # Step 8 is let through: it is the page that shows the finished summary,
    # and the installer itself shows it only to the session that finished.
    RewriteEngine On
    RewriteCond ${APP_DIR}/install/.lock -f
    RewriteCond %{QUERY_STRING} !(^|&)step=8(&|$)
    RewriteRule ^/install(/|$) - [R=404,L]

    ErrorLog \${APACHE_LOG_DIR}/${SITE_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${SITE_NAME}-access.log combined
</VirtualHost>
SITE

a2ensite -q "$SITE_NAME" >/dev/null
if [ -z "$DOMAIN" ] && [ -e /etc/apache2/sites-enabled/000-default.conf ]; then
  # Without a domain this site has to answer for the bare IP, which Ubuntu's
  # placeholder page otherwise does.
  a2dissite -q 000-default >/dev/null
  ok "Disabled Apache's default placeholder site."
fi

# AH00558 is Apache noting the machine has no fully qualified name, which
# the site's own ServerName makes irrelevant.
apache2ctl configtest 2>&1 | grep -v -e '^Syntax OK$' -e 'AH00558' | sed 's/^/    /' >&2 || true
apache2ctl configtest >/dev/null 2>&1 || die "Apache's configuration test failed; see above."

if [ -d /run/systemd/system ]; then
  systemctl enable apache2 >/dev/null 2>&1 || true
fi
if svc status apache2 >/dev/null 2>&1; then
  svc reload apache2 >/dev/null
else
  svc start apache2 >/dev/null
fi
ok "Apache serves $APP_DIR."

if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q '^Status: active'; then
  ufw allow 'Apache Full' >/dev/null
  ok "Firewall (ufw) allows HTTP and HTTPS."
fi

# ---------------------------------------------------------------------------
# 5. Cron
# ---------------------------------------------------------------------------

step "Scheduled work"

cat > /etc/cron.d/castle-crm <<CRON
# Castle CRM: scheduled work, every minute. Written by install.sh.
# cron.php exits immediately until the web installer has finished.
* * * * * ${WEB_USER} ${PHP_BIN} ${APP_DIR}/cron.php >/dev/null 2>&1
CRON
chmod 644 /etc/cron.d/castle-crm
svc_enable cron >/dev/null 2>&1 || warn "Could not start cron; scheduled work will not run until it is."
ok "cron.php runs every minute as ${WEB_USER} (/etc/cron.d/castle-crm)."

# ---------------------------------------------------------------------------
# 6. HTTPS
# ---------------------------------------------------------------------------

SCHEME=http
if [ -n "$DOMAIN" ] && [ -d "/etc/letsencrypt/live/$DOMAIN" ] && command -v certbot >/dev/null 2>&1; then
  # A re-run rewrote the site file above, which drops the HTTPS redirect
  # certbot added to it. Put the existing certificate back rather than ask
  # Let's Encrypt for another.
  step "HTTPS certificate for $DOMAIN"
  if certbot --apache --non-interactive --reinstall --redirect --cert-name "$DOMAIN" -d "$DOMAIN"; then
    SCHEME=https
    ok "Existing certificate reinstalled."
  else
    warn "Could not reinstall the existing certificate for $DOMAIN; see certbot's output above."
  fi
elif [ "$USE_SSL" -eq 1 ]; then
  step "HTTPS certificate for $DOMAIN"
  if certbot --apache --non-interactive --agree-tos --redirect -m "$EMAIL" -d "$DOMAIN"; then
    SCHEME=https
    ok "Certificate installed; plain HTTP now redirects to HTTPS."
  else
    warn "Let's Encrypt could not issue a certificate. Usually $DOMAIN does not point at this server yet."
    warn "Once it does, run: sudo certbot --apache --redirect -m $EMAIL -d $DOMAIN"
  fi
fi

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------

if [ -n "$DOMAIN" ]; then
  HOST="$DOMAIN"
else
  HOST="$(hostname -I 2>/dev/null | awk '{print $1}')"
  HOST="${HOST:-this-server}"
fi

printf '\n%s%sThe server is ready.%s\n\n' "$BOLD" "$GREEN" "$RESET"

if [ -f "$APP_DIR/install/.lock" ]; then
  printf 'Castle CRM is already set up: %s://%s/\n' "$SCHEME" "$HOST"
  exit 0
fi

printf 'Open %s%s://%s/install/%s and follow the eight steps.\n\n' "$BOLD" "$SCHEME" "$HOST" "$RESET"
if [ -n "$DB_PASS" ]; then
  printf 'On step 3, enter:\n'
  printf '  Host:      localhost\n'
  printf '  Database:  %s\n' "$DB_NAME"
  printf '  Username:  %s\n' "$DB_USER"
  printf '  Password:  %s\n' "$DB_PASS"
  printf '  (also saved in %s)\n\n' "$CREDENTIALS_FILE"
fi
printf 'The cron job is already in place, so skip "Add the cron job" on the last page.\n'
printf 'Leave the install/ folder where it is: the web is closed to it once setup finishes.\n'
if [ "$SCHEME" = "http" ]; then
  printf '\n%sThis address is plain HTTP.%s Passwords typed into the installer travel unencrypted.\n' "$YELLOW" "$RESET"
  printf 'Point a domain at this server and re-run with --domain and --email to add HTTPS first.\n'
fi
