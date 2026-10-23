#!/usr/bin/env bash
#
# Castle CRM — install on an Ubuntu server.
#
#   git clone https://github.com/SethMorrowSoftware/crm.git /var/www/castle-crm
#   cd /var/www/castle-crm
#   sudo ./install.sh --domain crm.example.com --admin-email you@example.com
#
# That is the whole procedure. The script installs Apache, PHP and MariaDB,
# creates the database and its user, applies the 99-table schema, seeds the
# venue and the playbook library, creates your owner account, writes the vhost,
# installs the cron line, and prints the address and password to sign in with.
#
# Nothing it builds can message a guest: a finished install is in Safe Mode,
# every playbook is off, all 112 automations are inactive and mail goes to a
# log file. Leaving that state is a deliberate act in the application itself.
#
# The web installer at /install/ still exists and still works — that is the
# path a cPanel venue takes, and `--finish-in-browser` stops here and hands
# over to it. Everything else this script does is the server configuration
# a shared host does on your behalf and a bare Ubuntu box does not.
#
# RE-RUNNABLE. Run it again after `git pull` and it refreshes the packages, the
# permissions, the vhost and the cron line, and leaves your data and your
# configuration alone. It never re-installs over an existing install: the
# application upgrades its own schema on the first request after the code
# changes (see cc_apply_pending_upgrade in app/bootstrap.php).
#
#   ./install.sh --help     every option
#
# Tested on Ubuntu 22.04, 24.04 and 26.04, and on Debian 12, which is close
# enough to the same machine. Each uses its own release's PHP. Ubuntu 20.04
# ships PHP 7.4 and Castle CRM needs 8.1, so that one needs a PHP PPA first;
# the script says so rather than failing later. A PPA left behind on a release
# it does not publish (26.04) is set aside, since it would stop apt cold.

set -euo pipefail

# ---------------------------------------------------------------------------
# Output
# ---------------------------------------------------------------------------

if [ -t 1 ]; then
  BOLD=$'\033[1m'; DIM=$'\033[2m'; RED=$'\033[31m'; GREEN=$'\033[32m'
  YELLOW=$'\033[33m'; RESET=$'\033[0m'
else
  BOLD=''; DIM=''; RED=''; GREEN=''; YELLOW=''; RESET=''
fi

section() { printf '\n%s── %s ──%s\n' "$BOLD" "$1" "$RESET"; }

say()     { printf '  %s\n' "$1"; }
ok()      { printf '  %s✓%s %s\n' "$GREEN" "$RESET" "$1"; }
note()    { printf '  %s%s%s\n' "$DIM" "$1" "$RESET"; }
warn()    { printf '  %s!%s %s\n' "$YELLOW" "$RESET" "$1"; }

die() {
  printf '\n%sInstall stopped:%s %s\n' "$RED" "$RESET" "$1" >&2
  shift || true

  for line in "$@"; do
    printf '  %s\n' "$line" >&2
  done

  exit 1
}

# ---------------------------------------------------------------------------
# Services, through whatever this machine has
# ---------------------------------------------------------------------------
#
# systemd on a server, `service` in a container, and neither in an image built
# for something else. Every caller treats failure as something to report, not
# as a reason to abandon an install that is otherwise finished: a service that
# has to be started by hand is a line in the summary, while a half-installed
# application is a mess to unpick.
service_start() { # name
  if command -v systemctl > /dev/null 2>&1 && systemctl list-units > /dev/null 2>&1; then
    systemctl enable --now "$1" > /dev/null 2>&1 && return 0
  fi

  if command -v service > /dev/null 2>&1; then
    service "$1" start > /dev/null 2>&1 && return 0
  fi

  return 1
}

service_reload() { # name
  if command -v systemctl > /dev/null 2>&1 && systemctl list-units > /dev/null 2>&1; then
    { systemctl reload "$1" > /dev/null 2>&1 || systemctl restart "$1" > /dev/null 2>&1; } && return 0
  fi

  if command -v service > /dev/null 2>&1; then
    service "$1" restart > /dev/null 2>&1 && return 0
  fi

  return 1
}

usage() {
  cat <<'USAGE'
Castle CRM — install on an Ubuntu server.

  sudo ./install.sh --domain crm.example.com --admin-email you@example.com

Where it goes
  --path DIR             Serve the application from DIR. Defaults to this
                         checkout. A different DIR is copied there, .git and
                         all, so `git pull` still upgrades it.
  --domain NAME          The hostname staff will use. Defaults to this
                         machine's hostname.
  --web-server WHICH     apache (default), nginx, or none to write no vhost.
  --php-version X.Y      Defaults to the newest 8.1+ this release of Ubuntu
                         offers.

Who signs in
  --admin-email EMAIL    The owner account. Asked for if not given.
  --admin-name NAME      Default "Owner".
  --admin-password-file F
                         Read the owner's password from F instead of
                         generating one. Never passed as an option: every
                         process on the machine can read a command line.

The venue
  --venue-name NAME      Default "The Castle Fun Center".
  --timezone AREA/CITY   The venue's calendar — not the server's. Defaults to
                         this machine's, or America/New_York when that is UTC,
                         because a venue on UTC puts tonight's party on
                         tomorrow's run sheet.
  --seed-demo            Add example guests, deals and bookings.
  --no-seed-venue        Skip the Castle's attractions, packages and hours.
  --no-seed-playbooks    Skip the eight playbooks and 112 automations.

The database
  --db-name NAME         Default castle_crm.
  --db-user USER         Default castle_crm.
  --db-host HOST         Default localhost.
  --db-port PORT         Default 3306.
  --db-password-file F   Use the password in F rather than generating one.
  --external-db          Do not install or configure MariaDB. The database
                         must already exist at --db-host with the user and
                         --db-password-file given here.

Finishing
  --finish-in-browser    Provision the server, then hand over to the web
                         installer at http://DOMAIN/install/.
  --letsencrypt EMAIL    Request a TLS certificate with certbot afterwards.
                         Needs --domain to be a name that resolves here.

Other
  --skip-packages        Install nothing from apt; everything is present.
  --force-config         Overwrite an existing vhost / PHP ini / cron file.
  -y, --yes              Do not ask anything.
  -h, --help             This.
USAGE
}

# ---------------------------------------------------------------------------
# Defaults
# ---------------------------------------------------------------------------

SOURCE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

# Kept for the "run it again like this" lines in the refusals below, which are
# only useful if they carry the options this run was given.
INVOCATION=("$@")

APP_DIR=""
DOMAIN=""
WEB_SERVER="apache"
PHP_VERSION=""
ADMIN_EMAIL=""
ADMIN_NAME="Owner"
ADMIN_PASSWORD_FILE=""
VENUE_NAME="The Castle Fun Center"
TIMEZONE=""
SEED_DEMO=0
SEED_VENUE=1
SEED_PLAYBOOKS=1
DB_NAME="castle_crm"
DB_USER="castle_crm"
DB_HOST="localhost"
DB_PORT="3306"
DB_PASSWORD_FILE=""
EXTERNAL_DB=0
FINISH_IN_BROWSER=0
LETSENCRYPT_EMAIL=""
SKIP_PACKAGES=0
FORCE_CONFIG=0
ASSUME_YES=0

# On Ubuntu and Debian both Apache and nginx run as this user, and so does
# PHP-FPM's default pool. Everything the application writes is owned by it.
WEB_USER="www-data"
WEB_GROUP="www-data"

while [ $# -gt 0 ]; do
  case "$1" in
    --path)                 APP_DIR="${2:?--path needs a directory}"; shift 2 ;;
    --domain)               DOMAIN="${2:?--domain needs a hostname}"; shift 2 ;;
    --web-server)           WEB_SERVER="${2:?--web-server needs apache, nginx or none}"; shift 2 ;;
    --php-version)          PHP_VERSION="${2:?--php-version needs a version}"; shift 2 ;;
    --admin-email)          ADMIN_EMAIL="${2:?--admin-email needs an address}"; shift 2 ;;
    --admin-name)           ADMIN_NAME="${2:?--admin-name needs a name}"; shift 2 ;;
    --admin-password-file)  ADMIN_PASSWORD_FILE="${2:?--admin-password-file needs a path}"; shift 2 ;;
    --venue-name)           VENUE_NAME="${2:?--venue-name needs a name}"; shift 2 ;;
    --timezone)             TIMEZONE="${2:?--timezone needs a zone}"; shift 2 ;;
    --seed-demo)            SEED_DEMO=1; shift ;;
    --no-seed-venue)        SEED_VENUE=0; shift ;;
    --no-seed-playbooks)    SEED_PLAYBOOKS=0; shift ;;
    --db-name)              DB_NAME="${2:?--db-name needs a name}"; shift 2 ;;
    --db-user)              DB_USER="${2:?--db-user needs a name}"; shift 2 ;;
    --db-host)              DB_HOST="${2:?--db-host needs a host}"; shift 2 ;;
    --db-port)              DB_PORT="${2:?--db-port needs a port}"; shift 2 ;;
    --db-password-file)     DB_PASSWORD_FILE="${2:?--db-password-file needs a path}"; shift 2 ;;
    --external-db)          EXTERNAL_DB=1; shift ;;
    --finish-in-browser)    FINISH_IN_BROWSER=1; shift ;;
    --letsencrypt)          LETSENCRYPT_EMAIL="${2:?--letsencrypt needs an email address}"; shift 2 ;;
    --skip-packages)        SKIP_PACKAGES=1; shift ;;
    --force-config)         FORCE_CONFIG=1; shift ;;
    -y|--yes)               ASSUME_YES=1; shift ;;
    -h|--help)              usage; exit 0 ;;
    *)                      usage >&2; die "Unknown option: $1" ;;
  esac
done

APP_DIR="${APP_DIR:-$SOURCE_DIR}"

case "$WEB_SERVER" in
  apache|nginx|none) ;;
  *) die "--web-server must be apache, nginx or none (got \"$WEB_SERVER\")." ;;
esac

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------

section "Checking the machine"

[ "$(id -u)" -eq 0 ] || die \
  "This needs root: it installs packages, writes to /etc and creates a database." \
  "Run it again as:  sudo ./install.sh ${INVOCATION[*]}"

# The checkout we were run from must actually be Castle CRM, not an empty
# directory somebody copied the script into.
for required in index.php cron.php app/bootstrap.php install/schema tools/cli-install.php; do
  [ -e "$SOURCE_DIR/$required" ] || die \
    "$SOURCE_DIR does not look like a Castle CRM checkout ($required is missing)." \
    "Clone the repository and run the install.sh inside it."
done

OS_ID=""
OS_VERSION=""
OS_NAME="this system"

if [ -r /etc/os-release ]; then
  # shellcheck disable=SC1091
  . /etc/os-release
  OS_ID="${ID:-}"
  OS_VERSION="${VERSION_ID:-}"
  OS_NAME="${PRETTY_NAME:-$OS_ID $OS_VERSION}"
fi

ok "$OS_NAME"

OS_CODENAME="${VERSION_CODENAME:-${UBUNTU_CODENAME:-}}"

# apt-get update, and the one way it fails that this script can mend.
#
# The ondrej/php PPA publishes only the Ubuntu releases it has built for, and
# it is always behind the newest one. On a release it does not cover (26.04
# "resolute" when this was written) its sources entry makes every apt-get
# update fail with "does not have a Release file", which stops this script
# before it has done anything — and the distribution's own PHP is new enough
# there, so the PPA was never needed. That entry is moved out of apt's way, to
# a folder it can be put back from, and the update is tried once more. Any
# other failure is reported as it is.
APT_DISABLED_SOURCES=/etc/apt/sources.list.d.castle-crm-disabled

apt_update() {
  local output stale

  if output=$(LC_ALL=C apt-get update -qq 2>&1); then
    return 0
  fi

  if ! grep -q 'ondrej/php.*does not have a Release file' <<< "$output"; then
    printf '%s\n' "$output" >&2
    return 1
  fi

  stale=$(grep -l -s 'ondrej/php' /etc/apt/sources.list.d/*.list /etc/apt/sources.list.d/*.sources || true)

  if [ -z "$stale" ]; then
    printf '%s\n' "$output" >&2
    die "The ondrej/php PPA does not publish ${OS_NAME}, and apt cannot update while it is listed." \
        "It is not under /etc/apt/sources.list.d, so remove its line from /etc/apt/sources.list" \
        "yourself, then run this again. ${OS_NAME}'s own PHP is the one to use."
  fi

  mkdir -p "$APT_DISABLED_SOURCES"

  local file
  for file in $stale; do
    mv "$file" "$APT_DISABLED_SOURCES/"
    warn "Set aside $file: the ondrej/php PPA has nothing for ${OS_CODENAME:-this release}."
  done

  note "It is in $APT_DISABLED_SOURCES if you ever want it back. This release's own PHP is used instead."

  apt-get update -qq
}

if [ "$SKIP_PACKAGES" -eq 0 ]; then
  command -v apt-get > /dev/null 2>&1 || die \
    "No apt-get, so this is not Ubuntu or Debian." \
    "Install PHP 8.1+, a MySQL-compatible database and a web server yourself," \
    "then run this again with --skip-packages."

  case "$OS_ID" in
    ubuntu|debian) ;;
    *) warn "Not Ubuntu or Debian — carrying on, because apt-get is here." ;;
  esac
fi

if [ -z "$TIMEZONE" ]; then
  TIMEZONE=$(timedatectl show --property=Timezone --value 2> /dev/null || true)
fi

if [ -z "$TIMEZONE" ] && [ -r /etc/timezone ]; then
  TIMEZONE=$(head -n1 /etc/timezone)
fi

# A VENUE'S CLOCK, not the server's.
#
# Most VPS images are on UTC, and a venue on UTC is wrong in a way that is
# easy to miss and hard to find: after 8pm Eastern the CRM's "today" is
# already tomorrow, so a party booked for tonight lands on the wrong run
# sheet and a birthday segment picks the wrong children. The venue this was
# written for is in Chester NY, which is the installer's own default; anywhere
# else, pass --timezone.
TIMEZONE_FROM_HOST="$TIMEZONE"

case "$TIMEZONE" in
  ""|UTC|Etc/UTC|Etc/GMT|GMT|Etc/Universal|Universal) TIMEZONE="America/New_York" ;;
esac

if [ -z "$DOMAIN" ]; then
  DOMAIN=$(hostname --fqdn 2> /dev/null || hostname)
  DOMAIN="${DOMAIN:-localhost}"
fi

# ---------------------------------------------------------------------------
# Which PHP
# ---------------------------------------------------------------------------

php_version_at_least_81() { # version
  [ "$(printf '%s\n8.1\n' "$1" | sort -V | head -n1)" = "8.1" ]
}

# Ubuntu 22.04 is the first release whose own PHP is 8.1.
php_version_at_least_81_release() { # Ubuntu VERSION_ID
  [ "$(printf '%s\n22.04\n' "$1" | sort -V | head -n1)" = "22.04" ]
}

if [ "$SKIP_PACKAGES" -eq 1 ] && [ -z "$PHP_VERSION" ]; then
  command -v php > /dev/null 2>&1 || die "--skip-packages was given, but there is no php on PATH."
  PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
fi

if [ -z "$PHP_VERSION" ]; then
  section "Refreshing the package lists"
  export DEBIAN_FRONTEND=noninteractive
  apt_update || die "apt-get update failed. Check this machine's network access."
  ok "package lists are current"

  # The distribution's own choice first, read from what its php-cli package
  # depends on: that is the PHP the release supports and patches, whatever its
  # number (8.1 on 22.04, 8.3 on 24.04, 8.5 on 26.04). A fixed list of
  # versions is what once stopped at 8.4 and told a 26.04 server it had no PHP.
  default_php=$(apt-cache depends php-cli 2> /dev/null \
    | sed -n 's/^ *Depends: php\([0-9][0-9]*\.[0-9][0-9]*\)-cli$/\1/p' | head -n1 || true)

  if [ -n "$default_php" ] && php_version_at_least_81 "$default_php"; then
    PHP_VERSION="$default_php"
  else
    # No php-cli metapackage to ask: newest first of the versions that exist.
    for candidate in 8.5 8.4 8.3 8.2 8.1; do
      if apt-cache show "php${candidate}-cli" > /dev/null 2>&1; then
        PHP_VERSION="$candidate"
        break
      fi
    done
  fi

  PACKAGE_LISTS_FRESH=1
fi

if [ -z "$PHP_VERSION" ]; then
  # The PPA is advice only where it is the answer: an Ubuntu release older
  # than 22.04, whose own PHP is too old and which the PPA does publish.
  # Anywhere newer, the release's own PHP is fine and the PPA may not exist.
  if [ "$OS_ID" = "ubuntu" ] && [ -n "$OS_VERSION" ] && ! php_version_at_least_81_release "$OS_VERSION"; then
    die "This release of $OS_NAME offers no PHP 8.1 or newer, and Castle CRM needs 8.1." \
      "On Ubuntu 20.04 (which ships PHP 7.4):" \
      "  sudo add-apt-repository -y ppa:ondrej/php && sudo apt-get update" \
      "then run this again. Ubuntu 22.04 and newer need nothing extra."
  fi

  die "No PHP 8.1 or newer is available from this machine's package sources." \
    "Check that the release's 'universe' component is enabled (sudo add-apt-repository universe)," \
    "or name one that is installable with --php-version."
fi

php_version_at_least_81 "$PHP_VERSION" || die \
  "PHP $PHP_VERSION is too old: Castle CRM needs 8.1 or newer."

ok "PHP $PHP_VERSION"

# ---------------------------------------------------------------------------
# Is this already an install?
# ---------------------------------------------------------------------------

ALREADY_INSTALLED=0

if [ -f "$APP_DIR/config/config.php" ]; then
  ALREADY_INSTALLED=1
fi

# ---------------------------------------------------------------------------
# The plan
# ---------------------------------------------------------------------------

section "The plan"

printf '  %-16s %s\n' "Application" "$APP_DIR"
[ "$APP_DIR" = "$SOURCE_DIR" ] || printf '  %-16s %s\n' "" "(copied from $SOURCE_DIR)"
printf '  %-16s %s\n' "Address" "http://$DOMAIN"
printf '  %-16s %s\n' "Web server" "$WEB_SERVER"
printf '  %-16s %s\n' "PHP" "$PHP_VERSION"
printf '  %-16s %s\n' "Database" "$DB_USER@$DB_HOST:$DB_PORT/$DB_NAME"
printf '  %-16s %s\n' "Venue" "$VENUE_NAME ($TIMEZONE)"

if [ "$TIMEZONE" != "$TIMEZONE_FROM_HOST" ]; then
  printf '  %-16s %s\n' "" "this machine is on ${TIMEZONE_FROM_HOST:-no timezone at all}; a venue needs a"
  printf '  %-16s %s\n' "" "local clock, so the dates in the CRM are the venue's own day."
  printf '  %-16s %s\n' "" "Pass --timezone, or change it later under Settings → Venue."
fi

if [ "$ALREADY_INSTALLED" -eq 1 ]; then
  printf '  %-16s %s\n' "Already set up" "keeping the existing database and configuration"
elif [ "$FINISH_IN_BROWSER" -eq 1 ]; then
  printf '  %-16s %s\n' "Setup" "finished in a browser at http://$DOMAIN/install/"
else
  printf '  %-16s %s\n' "Owner" "${ADMIN_EMAIL:-(will ask)}"
fi

if [ "$ASSUME_YES" -eq 0 ]; then
  if [ ! -t 0 ]; then
    die "Nothing to ask with: no terminal is attached." "Pass --yes to run unattended."
  fi

  printf '\n  Continue? [y/N] '
  read -r answer

  case "$answer" in
    y|Y|yes|YES) ;;
    *) die "Nothing has been changed." ;;
  esac
fi

# The owner's address, which only the headless path needs.
if [ "$ALREADY_INSTALLED" -eq 0 ] && [ "$FINISH_IN_BROWSER" -eq 0 ] && [ -z "$ADMIN_EMAIL" ]; then
  if [ ! -t 0 ]; then
    die "--admin-email is required: it is the address the owner signs in with." \
        "Or pass --finish-in-browser to choose it on screen instead."
  fi

  printf '  Owner email address: '
  read -r ADMIN_EMAIL

  [ -n "$ADMIN_EMAIL" ] || die "No address given."
fi

# ---------------------------------------------------------------------------
# Packages
# ---------------------------------------------------------------------------

PHP_BIN="/usr/bin/php${PHP_VERSION}"

if [ "$SKIP_PACKAGES" -eq 0 ]; then
  section "Installing packages"

  export DEBIAN_FRONTEND=noninteractive

  if [ "${PACKAGE_LISTS_FRESH:-0}" -eq 0 ]; then
    apt_update || die "apt-get update failed."
  fi

  packages=(
    "php${PHP_VERSION}-cli"
    # pdo_mysql, mbstring, curl: hard requirements, checked on every request.
    "php${PHP_VERSION}-mysql"
    "php${PHP_VERSION}-mbstring"
    "php${PHP_VERSION}-curl"
    # Optional in the application and cheap here: image resizing for a logo,
    # locale-aware formatting, and the bytecode cache that makes a vanilla-PHP
    # application as quick as a compiled one.
    "php${PHP_VERSION}-gd"
    "php${PHP_VERSION}-intl"
    "php${PHP_VERSION}-zip"
  )

  # PHP 8.5 builds OPcache in, so 26.04 has no package for it to install.
  if apt-cache show "php${PHP_VERSION}-opcache" > /dev/null 2>&1; then
    packages+=("php${PHP_VERSION}-opcache")
  fi

  case "$WEB_SERVER" in
    apache) packages+=("apache2" "libapache2-mod-php${PHP_VERSION}") ;;
    nginx)  packages+=("nginx" "php${PHP_VERSION}-fpm") ;;
    none)   ;;
  esac

  if [ "$EXTERNAL_DB" -eq 0 ]; then
    packages+=("mariadb-server" "mariadb-client")
  fi

  if [ -n "$LETSENCRYPT_EMAIL" ]; then
    case "$WEB_SERVER" in
      apache) packages+=("certbot" "python3-certbot-apache") ;;
      nginx)  packages+=("certbot" "python3-certbot-nginx") ;;
      none)   packages+=("certbot") ;;
    esac
  fi

  say "apt-get install ${packages[*]}"

  apt-get install -y -qq --no-install-recommends "${packages[@]}" || die \
    "The packages could not be installed." \
    "The apt output above names the one that failed."

  ok "${#packages[@]} packages installed"
fi

[ -x "$PHP_BIN" ] || PHP_BIN="$(command -v php || true)"
[ -x "$PHP_BIN" ] || die "No PHP binary found after installing packages."

# Every extension the application hard-requires, asked of the binary rather
# than assumed from the package list. A here-string and not a pipe into grep:
# `grep -q` stops reading at the first match, and under `pipefail` the SIGPIPE
# that kills `php -m` would make a satisfied check look like a failed one.
php_modules=$("$PHP_BIN" -m)
missing_extensions=""

for extension in pdo_mysql mbstring json openssl curl fileinfo; do
  grep -qix "$extension" <<< "$php_modules" || missing_extensions="$missing_extensions $extension"
done

if [ -n "$missing_extensions" ]; then
  die "This PHP is missing:$missing_extensions" \
      "Install php${PHP_VERSION}-{mysql,mbstring,curl} and run this again."
fi

ok "PHP has every required extension"

# ---------------------------------------------------------------------------
# The code
# ---------------------------------------------------------------------------

section "Putting the application in place"

if [ "$APP_DIR" != "$SOURCE_DIR" ]; then
  if [ -e "$APP_DIR/index.php" ]; then
    ok "$APP_DIR already holds a checkout — using it as it is"
    note "Nothing was copied. Upgrade it there with: sudo git -C $APP_DIR pull"
  elif [ -d "$APP_DIR" ] && [ -n "$(ls -A "$APP_DIR" 2> /dev/null)" ]; then
    die "$APP_DIR is not empty and is not a Castle CRM checkout." \
        "Pick an empty directory, or pass the path of an existing install."
  else
    mkdir -p "$APP_DIR"
    # `/.` so the dotfiles come too: .htaccess IS the request policy on
    # Apache, and .git is what makes `git pull` an upgrade.
    cp -a "$SOURCE_DIR/." "$APP_DIR/"

    # The CODE, not another install's identity. A checkout that has already
    # been installed holds a config.php with a database password and an
    # encryption key, and an install/.lock; carried over, the first would point
    # this new path at the other install's database and the second would make
    # the step below refuse. The source keeps both.
    rm -f "$APP_DIR/config/config.php" "$APP_DIR/install/.lock"

    ok "copied the checkout to $APP_DIR"
    note "Its own configuration is written fresh; upgrade it with: sudo git -C $APP_DIR pull"
  fi
else
  ok "serving this checkout from $APP_DIR"
fi

# Storage, which the application writes to from its very first request.
for directory in logs cache uploads exports backups sessions; do
  mkdir -p "$APP_DIR/storage/$directory"
done

# ---------------------------------------------------------------------------
# Permissions
#
# The application reads its own code and writes exactly two places: storage/,
# and config/ while it is being set up. Nothing else needs to be writable by
# the web server, and the file holding the database password must not be
# readable by anybody else on the machine.
# ---------------------------------------------------------------------------

run_as_web_user() {
  if command -v runuser > /dev/null 2>&1; then
    runuser -u "$WEB_USER" -- "$@"
  else
    sudo -u "$WEB_USER" -- "$@"
  fi
}

chown -R "$WEB_USER:$WEB_GROUP" "$APP_DIR/storage"
chmod -R u=rwX,g=rX,o= "$APP_DIR/storage"
ok "storage/ belongs to $WEB_USER"

chown "$WEB_USER:$WEB_GROUP" "$APP_DIR/config"
chmod 0750 "$APP_DIR/config"

if [ -f "$APP_DIR/config/config.php" ]; then
  chown "$WEB_USER:$WEB_GROUP" "$APP_DIR/config/config.php"
  chmod 0640 "$APP_DIR/config/config.php"
fi

if [ "$FINISH_IN_BROWSER" -eq 1 ]; then
  # The web installer writes install/.lock when it finishes.
  chown "$WEB_USER:$WEB_GROUP" "$APP_DIR/install"
  chmod 0750 "$APP_DIR/install"
fi

if [ -d "$APP_DIR/.git" ]; then
  # A clone in a web root publishes its own history unless something stops it.
  # The vhost denies dotted paths and so does the nginx site — this is the
  # third layer, and the only one that survives somebody rewriting those.
  chmod o= "$APP_DIR/.git"
  ok ".git is unreadable to anyone but its owner"
fi

# PROVE the web server can read the application, rather than discovering it as
# a 403 on the first page load. A clone in a home directory is the usual cause:
# Ubuntu has created those 0750 since 21.04.
if ! run_as_web_user test -r "$APP_DIR/index.php"; then
  die "$WEB_USER cannot read $APP_DIR/index.php, so the site would 403." \
      "A clone inside a home directory is the usual reason: Ubuntu makes those" \
      "readable only by their owner. Install somewhere the web server can reach:" \
      "  sudo ./install.sh --path /var/www/castle-crm ${INVOCATION[*]}"
fi

run_as_web_user test -w "$APP_DIR/storage/logs" \
  || die "$WEB_USER cannot write $APP_DIR/storage/logs."

ok "$WEB_USER can read the application and write its storage"

# ---------------------------------------------------------------------------
# The database
# ---------------------------------------------------------------------------

DB_PASSWORD=""

read_config_value() { # dotted key
  "$PHP_BIN" -r '
    $config = require $argv[1];
    $value = $config;
    foreach (explode(".", $argv[2]) as $part) {
        $value = is_array($value) && array_key_exists($part, $value) ? $value[$part] : "";
    }
    echo is_scalar($value) ? (string) $value : "";
  ' "$APP_DIR/config/config.php" "$1"
}

generate_password() { # 128 bits, written as 32 hex characters
  # `od` of a fixed number of bytes rather than `tr -dc < /dev/urandom | head`:
  # that pipeline leaves `tr` reading an endless file when `head` closes the
  # pipe, and the SIGPIPE it earns fails the whole command under `pipefail`.
  local raw
  raw=$(od -An -tx1 -N16 < /dev/urandom | tr -d ' \n')
  printf '%s\n' "$raw"
}

generate_passphrase() {
  # Four words and three digits: long enough for the application's password
  # policy, and typeable by somebody reading it off this terminal once.
  local words=(amber anchor arcade beacon bridge canyon cobble comet dragon ember
               falcon garnet harbor island jasper kestrel lantern maple meadow
               nimbus onyx pebble quartz ridge saffron timber umber violet willow)
  local picked=() index

  while [ "${#picked[@]}" -lt 4 ]; do
    index=$(( $(od -An -N2 -tu2 < /dev/urandom | tr -d ' ') % ${#words[@]} ))
    picked+=("${words[$index]}")
  done

  printf '%s-%s-%s-%s-%03d\n' "${picked[0]}" "${picked[1]}" "${picked[2]}" "${picked[3]}" \
    "$(( $(od -An -N2 -tu2 < /dev/urandom | tr -d ' ') % 900 + 100 ))"
}

section "The database"

if [ "$ALREADY_INSTALLED" -eq 1 ]; then
  # An existing install's credentials are in its config file. They are not
  # re-created and the password is NOT rotated: the application is running on
  # it, and this script is only here to refresh the server around it.
  DB_NAME=$(read_config_value 'db.name')
  DB_USER=$(read_config_value 'db.user')
  DB_HOST=$(read_config_value 'db.host')
  DB_PORT=$(read_config_value 'db.port')
  DB_PASSWORD=$(read_config_value 'db.pass')

  ok "already configured: $DB_USER@$DB_HOST/$DB_NAME"

  # Asked rather than assumed: this re-run is also the repair path, and a
  # script that says it refreshed the server while the application cannot
  # reach its own database has not helped anybody.
  if run_as_web_user "$PHP_BIN" -r '
      $config = require $argv[1];
      $db = $config["db"];
      $dsn = !empty($db["socket"])
          ? sprintf("mysql:unix_socket=%s;dbname=%s", $db["socket"], $db["name"])
          : sprintf("mysql:host=%s;port=%d;dbname=%s", $db["host"], (int) $db["port"], $db["name"]);
      new PDO($dsn, $db["user"], $db["pass"]);
  ' "$APP_DIR/config/config.php" > /dev/null 2>&1; then
    ok "and the database answers"
  else
    warn "the configured database did NOT answer. The credentials in"
    warn "$APP_DIR/config/config.php no longer work, or the server is down."
  fi
else
  if [ -n "$DB_PASSWORD_FILE" ]; then
    [ -r "$DB_PASSWORD_FILE" ] || die "Cannot read $DB_PASSWORD_FILE."
    DB_PASSWORD=$(head -n1 "$DB_PASSWORD_FILE")
  elif [ "$EXTERNAL_DB" -eq 1 ]; then
    die "--external-db needs --db-password-file: this script will not guess an" \
        "existing database's password, and it cannot create the user remotely."
  else
    DB_PASSWORD=$(generate_password)
  fi

  if [ "$EXTERNAL_DB" -eq 1 ]; then
    ok "using the database already at $DB_HOST:$DB_PORT"
  else
    # Starting the server is a best effort, and whether it worked is decided
    # below by connecting rather than by what the service manager said: a
    # database that is already up (a container with no systemd, a server
    # started another way) must not be a failed install.
    service_start mariadb || service_start mysql || true

    MYSQL_BIN=$(command -v mariadb || command -v mysql || true)
    [ -n "$MYSQL_BIN" ] || die "No mariadb or mysql client on PATH."

    ready=0

    for _ in $(seq 1 30); do
      if "$MYSQL_BIN" --protocol=socket -u root -e 'SELECT 1' > /dev/null 2>&1; then
        ready=1
        break
      fi
      sleep 1
    done

    [ "$ready" -eq 1 ] || die \
      "The database server is running but root cannot reach it over its socket." \
      "On a stock Ubuntu install root authenticates through the socket and this" \
      "just works. If you have set a root password, create the database yourself:" \
      "  CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
      "  CREATE USER '$DB_USER'@'localhost' IDENTIFIED BY '<password>';" \
      "  GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';" \
      "then run this again with --external-db --db-password-file."

    # Single quotes and backslashes are escaped for the SQL string literal; the
    # statements arrive on stdin, so no password ever reaches a command line.
    escaped_password=${DB_PASSWORD//\\/\\\\}
    escaped_password=${escaped_password//\'/\\\'}

    "$MYSQL_BIN" --protocol=socket -u root <<SQL || die "The database could not be created."
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${escaped_password}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${escaped_password}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

    ok "database $DB_NAME and user $DB_USER ready"
    note "Castle CRM needs full privileges on its own database — the installer"
    note "creates 99 tables and the backup feature reads all of them. It never"
    note "touches another database."
  fi
fi

# ---------------------------------------------------------------------------
# PHP settings for the web SAPI
# ---------------------------------------------------------------------------

section "Server configuration"

# Set by install_template: 1 when it wrote the file, 0 when it kept yours.
# The nginx step below patches what it wrote, and must not touch what it kept.
TEMPLATE_WROTE=0

install_template() { # source, destination, mode
  local source="$1" destination="$2" mode="$3"

  TEMPLATE_WROTE=0

  if [ -e "$destination" ] && [ "$FORCE_CONFIG" -eq 0 ]; then
    note "kept your $destination (--force-config replaces it)"
    return 0
  fi

  sed -e "s|__CC_DOMAIN__|${DOMAIN}|g" \
      -e "s|__CC_PATH__|${APP_DIR}|g" \
      -e "s|__CC_PHP_VERSION__|${PHP_VERSION}|g" \
      -e "s|__CC_PHP__|${PHP_BIN}|g" \
      -e "s|__CC_USER__|${WEB_USER}|g" \
      "$source" > "$destination.castle-tmp"

  chmod "$mode" "$destination.castle-tmp"
  mv "$destination.castle-tmp" "$destination"
  TEMPLATE_WROTE=1
  ok "wrote $destination"
}

php_sapi_dir=""

case "$WEB_SERVER" in
  apache) php_sapi_dir="/etc/php/${PHP_VERSION}/apache2/conf.d" ;;
  nginx)  php_sapi_dir="/etc/php/${PHP_VERSION}/fpm/conf.d" ;;
esac

if [ -n "$php_sapi_dir" ] && [ -d "$php_sapi_dir" ]; then
  install_template "$APP_DIR/tools/server/php-castle-crm.ini" \
    "$php_sapi_dir/99-castle-crm.ini" 0644
fi

# ---------------------------------------------------------------------------
# The web server
# ---------------------------------------------------------------------------

case "$WEB_SERVER" in
  apache)
    # mod_rewrite is the front controller; headers, expires, deflate and
    # setenvif are what the shipped .htaccess uses, each inside an <IfModule>
    # so a missing one degrades instead of 500ing.
    a2enmod rewrite headers expires deflate setenvif > /dev/null
    ok "enabled mod_rewrite, headers, expires, deflate, setenvif"

    install_template "$APP_DIR/tools/server/apache-vhost.conf" \
      "/etc/apache2/sites-available/castle-crm.conf" 0644

    # ServerTokens is server-wide, not per-vhost, so it cannot live in the
    # template. Ubuntu ships `ServerTokens OS`, which names the Apache version
    # and the distribution in the response to every request — the same thing
    # the shipped .htaccess unsets X-Powered-By and the ini sets expose_php=Off
    # for. This machine serves one application, so it is safe to set globally.
    # `zz-` BECAUSE THE ORDER DECIDES IT. apache2.conf includes
    # conf-enabled/*.conf alphabetically and the last ServerTokens wins;
    # Ubuntu's own security.conf sets `ServerTokens OS`, so anything sorting
    # before it is silently overruled — which is what the first version of
    # this did, and the Server header went on naming the version.
    if [ ! -e /etc/apache2/conf-available/zz-castle-crm.conf ] || [ "$FORCE_CONFIG" -eq 1 ]; then
      cat > /etc/apache2/conf-available/zz-castle-crm.conf <<'TOKENS'
# Written by Castle CRM's install.sh. Stops the Apache version and the
# distribution appearing in the Server header of every response — the same
# thing the shipped .htaccess does for X-Powered-By.
#
# The zz- prefix is load order: conf-enabled/*.conf is included
# alphabetically, and Ubuntu's security.conf sets ServerTokens OS.
ServerTokens Prod
ServerSignature Off
TOKENS
      chmod 0644 /etc/apache2/conf-available/zz-castle-crm.conf
      a2enconf zz-castle-crm > /dev/null
      ok "Apache no longer announces its version"
    fi

    a2ensite castle-crm > /dev/null

    if [ -L /etc/apache2/sites-enabled/000-default.conf ]; then
      # Apache serves the alphabetically first enabled vhost to any request
      # whose Host matches nothing — which would be the stock "It works!" page
      # on this machine's IP address, not Castle CRM.
      a2dissite 000-default > /dev/null
      ok "disabled Apache's default site"
    fi

    apache2ctl configtest > /dev/null 2>&1 || die \
      "Apache rejects its own configuration now." \
      "Run: apache2ctl configtest"

    if service_reload apache2; then
      ok "Apache is serving $APP_DIR"
    else
      warn "Apache would not reload. Its configuration is written and valid;"
      warn "start it yourself with: systemctl restart apache2"
    fi
    ;;

  nginx)
    # One server per port 80. apache2 is installed by default on some images
    # and by an earlier run of this script on others, and nginx simply fails
    # to bind — which reads as "nginx is broken" rather than "something else
    # has the port".
    if pgrep -x apache2 > /dev/null 2>&1; then
      warn "apache2 is running and holding port 80. nginx cannot start until it"
      warn "stops: sudo systemctl disable --now apache2"
    fi

    service_start "php${PHP_VERSION}-fpm" \
      || warn "php${PHP_VERSION}-fpm would not start, and nginx needs it to run any page."

    install_template "$APP_DIR/tools/server/nginx-site.conf" \
      "/etc/nginx/sites-available/castle-crm" 0644

    # NO IPv6 ON THIS MACHINE, AND NGINX WILL NOT START AT ALL.
    #
    # `listen [::]:80` is in every stock Ubuntu site and is right on a normal
    # VPS. On a host with the IPv6 stack switched off it is
    # `socket() [::]:80 failed (97: Address family not supported by protocol)`
    # — an [emerg], so nginx refuses to start and serves nothing, over either
    # protocol. MEASURED on a container with no /proc/net/if_inet6.
    if [ "$TEMPLATE_WROTE" -eq 1 ] && [ ! -e /proc/net/if_inet6 ]; then
      sed -i 's|^\(\s*\)listen \[::\]:80;|\1# listen [::]:80;  # commented out by install.sh: no IPv6 on this machine|' \
        /etc/nginx/sites-available/castle-crm
      ok "commented out the IPv6 listener — this machine has no IPv6 stack"
    fi

    ln -sfn ../sites-available/castle-crm /etc/nginx/sites-enabled/castle-crm

    if [ -e /etc/nginx/sites-enabled/default ]; then
      rm -f /etc/nginx/sites-enabled/default
      ok "disabled nginx's default site"
    fi

    nginx -t > /dev/null 2>&1 || die \
      "nginx rejects its own configuration now." \
      "Run: nginx -t"

    if service_reload nginx; then
      ok "nginx is serving $APP_DIR"
    else
      warn "nginx would not reload. Its configuration is written and valid;"
      warn "start it yourself with: systemctl restart nginx"
    fi
    ;;

  none)
    note "No vhost written. Point your own at $APP_DIR, with index.php as the"
    note "front controller. tools/server/ holds both configurations to copy from."
    ;;
esac

ufw_status=""

if command -v ufw > /dev/null 2>&1; then
  ufw_status=$(ufw status 2> /dev/null || true)
fi

if grep -q '^Status: active' <<< "$ufw_status"; then
  case "$WEB_SERVER" in
    apache) ufw allow 'Apache Full' > /dev/null 2>&1 && ok "opened the firewall for HTTP and HTTPS" ;;
    nginx)  ufw allow 'Nginx Full'  > /dev/null 2>&1 && ok "opened the firewall for HTTP and HTTPS" ;;
  esac
fi

# ---------------------------------------------------------------------------
# Cron — the one piece of scheduling the application cannot do for itself
# ---------------------------------------------------------------------------

install_template "$APP_DIR/tools/server/cron.d-castle-crm" "/etc/cron.d/castle-crm" 0644

if pgrep -x cron > /dev/null 2>&1 || pgrep -x crond > /dev/null 2>&1; then
  ok "cron runs cron.php every minute as $WEB_USER"
else
  warn "The cron line is installed, but no cron daemon is running on this"
  warn "machine, so nothing scheduled will happen. Start it with:"
  warn "  systemctl enable --now cron"
fi

# ---------------------------------------------------------------------------
# Setup
# ---------------------------------------------------------------------------

BASE_URL="http://$DOMAIN"
ADMIN_PASSWORD=""
GENERATED_PASSWORD=0

if [ "$ALREADY_INSTALLED" -eq 1 ]; then
  section "Already installed"

  ok "kept the existing install: its database, settings and accounts are untouched"
  note "Any new schema file in this version applies itself on the next request."

  # Take install/ back from the web server, which only needed it while the web
  # installer was writing its lock. See the note on the lock below.
  if [ -f "$APP_DIR/install/.lock" ]; then
    chown root:root "$APP_DIR/install" "$APP_DIR/install/.lock"
    chmod 0755 "$APP_DIR/install"
    chmod 0644 "$APP_DIR/install/.lock"
    ok "the web installer is locked, and only root can unlock it"
  else
    warn "install/.lock is missing, so /install/ would let somebody reconfigure"
    warn "this application. Finish setup, or create the file to close it."
  fi

elif [ "$FINISH_IN_BROWSER" -eq 1 ]; then
  section "Finish in a browser"

  say "Open ${BASE_URL}/install/ and walk the eight steps. At step 3, enter:"
  printf '\n'
  printf '    %-16s %s\n' "Database host" "$DB_HOST"
  printf '    %-16s %s\n' "Database name" "$DB_NAME"
  printf '    %-16s %s\n' "Username" "$DB_USER"
  printf '    %-16s %s\n' "Password" "$DB_PASSWORD"
  printf '\n'
  warn "That password is not written down anywhere yet — copy it now. The"
  warn "installer stores it in config/config.php when it finishes."
  say "Afterwards, run this script again to tighten the permissions the web"
  say "installer needed: sudo ./install.sh --yes"

else
  section "Setting up Castle CRM"

  if [ -n "$ADMIN_PASSWORD_FILE" ]; then
    [ -r "$ADMIN_PASSWORD_FILE" ] || die "Cannot read $ADMIN_PASSWORD_FILE."
    ADMIN_PASSWORD=$(head -n1 "$ADMIN_PASSWORD_FILE")
  else
    ADMIN_PASSWORD=$(generate_passphrase)
    GENERATED_PASSWORD=1
  fi

  # The secrets go to the installer in files, not in options: a command line is
  # readable by every account on the machine (`ps auxww`). The directory is
  # 0700 and owned by the web user, which is the only account that reads it,
  # and it is removed however this script exits.
  secret_dir=$(mktemp -d /run/castle-crm-install.XXXXXXXX 2> /dev/null \
    || mktemp -d /tmp/castle-crm-install.XXXXXXXX)
  trap 'rm -rf "$secret_dir"' EXIT

  umask 077
  printf '%s' "$DB_PASSWORD" > "$secret_dir/db"
  printf '%s' "$ADMIN_PASSWORD" > "$secret_dir/admin"
  umask 022

  chown -R "$WEB_USER:$WEB_GROUP" "$secret_dir"
  chmod 0700 "$secret_dir"

  installer_options=(
    "--db-host=$DB_HOST"
    "--db-port=$DB_PORT"
    "--db-name=$DB_NAME"
    "--db-user=$DB_USER"
    "--db-pass-file=$secret_dir/db"
    "--admin-email=$ADMIN_EMAIL"
    "--admin-name=$ADMIN_NAME"
    "--admin-password-file=$secret_dir/admin"
    "--venue-name=$VENUE_NAME"
    "--timezone=$TIMEZONE"
    "--base-url=$BASE_URL"
    # Its progress lines, not its own closing summary: this script prints one
    # below, and two in a row is noise.
    "--quiet"
  )

  [ "$SEED_VENUE" -eq 1 ]     || installer_options+=("--no-seed-venue")
  [ "$SEED_PLAYBOOKS" -eq 1 ] || installer_options+=("--no-seed-playbooks")
  [ "$SEED_DEMO" -eq 1 ]      && installer_options+=("--seed-demo")

  # The installer closes itself by writing install/.lock, so for the length of
  # this one command the directory is writable by the web server. It is handed
  # straight back below — see the note there.
  chown "$WEB_USER:$WEB_GROUP" "$APP_DIR/install"
  chmod 0750 "$APP_DIR/install"

  # Run as the web server's user so that everything it creates — the config
  # file, the log, the cache — is owned by the account that serves the pages
  # and runs the cron job.
  if ! (cd "$APP_DIR" && run_as_web_user "$PHP_BIN" tools/cli-install.php "${installer_options[@]}"); then
    die "Setup did not complete." \
        "Nothing was written to config/config.php, so this can be run again once" \
        "the cause above is fixed. storage/logs/ has the detail."
  fi

  rm -rf "$secret_dir"
  trap - EXIT

  # THE LOCK, AND WHO MAY REMOVE IT.
  #
  # install/.lock is what makes /install/ answer 403 instead of offering to
  # point this venue's application at somebody else's database. The installer
  # has just written it; this takes the directory back, because removing a
  # file needs write permission on its DIRECTORY and not on the file, and an
  # install/ left writable by www-data is a lock that anything running as the
  # web server can simply delete.
  #
  # The engine's write is a silenced one, so the fallback below is not
  # theoretical: without it, a failure to write the lock would leave the web
  # installer open and say nothing at all.
  if [ ! -f "$APP_DIR/install/.lock" ]; then
    {
      printf 'Castle CRM was installed on %s UTC.\n' "$(date -u '+%Y-%m-%dT%H:%M:%S')"
      printf 'Set up from the command line by install.sh; the web installer is closed.\n'
      printf 'Unlike a cPanel install, do NOT delete this directory: install/schema is\n'
      printf 'what applies the next version schema on the first request after a git pull.\n'
    } > "$APP_DIR/install/.lock"
  fi

  chown root:root "$APP_DIR/install" "$APP_DIR/install/.lock"
  chmod 0755 "$APP_DIR/install"
  chmod 0644 "$APP_DIR/install/.lock"

  chown "$WEB_USER:$WEB_GROUP" "$APP_DIR/config/config.php"
  chmod 0640 "$APP_DIR/config/config.php"
fi

# ---------------------------------------------------------------------------
# TLS
# ---------------------------------------------------------------------------

if [ -n "$LETSENCRYPT_EMAIL" ]; then
  section "HTTPS"

  certbot_plugin=""

  case "$WEB_SERVER" in
    apache) certbot_plugin="--apache" ;;
    nginx)  certbot_plugin="--nginx" ;;
    none)   certbot_plugin="" ;;
  esac

  if [ -z "$certbot_plugin" ]; then
    warn "--letsencrypt needs a web server this script configured; skipping."
  elif certbot "$certbot_plugin" -d "$DOMAIN" --non-interactive --agree-tos \
       -m "$LETSENCRYPT_EMAIL" --redirect; then
    BASE_URL="https://$DOMAIN"
    ok "certificate issued and HTTP redirected to HTTPS"
    note "Now turn on Settings → System → Require HTTPS inside the application,"
    note "so the application refuses an insecure request as well."
  else
    warn "certbot could not issue a certificate for $DOMAIN."
    warn "The usual cause is DNS: $DOMAIN has to resolve to this machine, and"
    warn "port 80 has to be reachable from the internet. The CRM is already"
    warn "running over HTTP; re-run certbot once DNS is right."
  fi
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

printf '\n%s%s%s\n' "$BOLD" "$(printf '═%.0s' $(seq 1 68))" "$RESET"

if [ "$ALREADY_INSTALLED" -eq 1 ]; then
  printf '%sCastle CRM: server configuration refreshed.%s\n' "$BOLD" "$RESET"
elif [ "$FINISH_IN_BROWSER" -eq 1 ]; then
  printf '%sCastle CRM: the server is ready for the web installer.%s\n' "$BOLD" "$RESET"
else
  printf '%sCastle CRM is installed.%s\n' "$BOLD" "$RESET"
fi

printf '%s\n\n' "$(printf '═%.0s' $(seq 1 68))"

printf '  %-14s %s\n' "Address" "$BASE_URL"

if [ "$FINISH_IN_BROWSER" -eq 1 ]; then
  printf '  %-14s %s\n' "Finish at" "$BASE_URL/install/"
elif [ "$ALREADY_INSTALLED" -eq 0 ]; then
  printf '  %-14s %s\n' "Sign in" "$ADMIN_EMAIL"

  if [ "$GENERATED_PASSWORD" -eq 1 ]; then
    printf '  %-14s %s%s%s\n' "Password" "$BOLD" "$ADMIN_PASSWORD" "$RESET"
    printf '  %-14s %s\n' "" "Copy it now; it is stored only as a hash. Change it under"
    printf '  %-14s %s\n' "" "Account once you are in, or reset it from the command line"
    printf '  %-14s %s\n' "" "with: sudo -u $WEB_USER $PHP_BIN $APP_DIR/tools/reset-password.php"
  fi
fi

printf '\n'
printf '  %-14s %s\n' "Application" "$APP_DIR"
printf '  %-14s %s\n' "Database" "$DB_USER@$DB_HOST/$DB_NAME"
printf '  %-14s %s\n' "Cron" "/etc/cron.d/castle-crm — every minute, as $WEB_USER"
printf '  %-14s %s\n' "Logs" "$APP_DIR/storage/logs/"
printf '  %-14s %s\n' "Upgrades" "sudo git -C $APP_DIR pull  (the schema follows by itself)"

if [ "$FINISH_IN_BROWSER" -eq 0 ]; then
  printf '  %-14s %s\n' "Installer" "closed by install/.lock — keep the directory, it holds"
  printf '  %-14s %s\n' "" "the schema that applies the next version"
fi

if [ "$ALREADY_INSTALLED" -eq 0 ] && [ "$FINISH_IN_BROWSER" -eq 0 ]; then
  cat <<SAFETY

  Nothing can reach a guest yet, and that is deliberate:

    · Safe Mode is ON — every email and text goes to the owner's own address.
    · All 112 automations and all 8 playbooks are inactive.
    · Mail has no provider: messages are written to storage/logs/ instead.

  Your first three jobs are on the dashboard checklist: confirm the venue
  details and prices, configure an email provider under Settings → Email, and
  take a backup under Settings → Backups.
SAFETY
fi

if [ -z "$LETSENCRYPT_EMAIL" ] && [ "$WEB_SERVER" != "none" ]; then
  cat <<TLS

  This install is plain HTTP. Staff passwords and guest details would travel
  in the clear, so get a certificate before anybody signs in from outside:

    sudo ./install.sh --letsencrypt you@example.com --yes

  then turn on Settings → System → Require HTTPS.
TLS
fi

printf '\n'
