#!/usr/bin/env bash
#
# Bring The Castle's guests across from CenterEdge in one command, on the
# Ubuntu server Castle CRM runs on (it has to be on the venue LAN, where the
# CenterEdge SQL Server is):
#
#   cd ~/crm            # wherever install.sh installed it
#   sudo bash tools/centeredge/import.sh
#
# What it does, in order:
#
#   1. Sets up Python with pymssql (once), in /opt/castle-crm/ce-venv.
#   2. Asks for the CenterEdge SQL login (once) and keeps it in
#      /etc/castle-crm/centeredge.env, readable by root only.
#   3. Runs ce_export.py: read-only SELECTs, a few seconds, three CSVs.
#   4. Feeds them through the CRM's own importer (tools/import-csv.php):
#      guests, then children, then visit history. Each is dry-run first, and
#      the import is refused if the columns do not map.
#   5. Deletes the CSVs. They are the guest list.
#
# RE-RUNNABLE. A guest, child or visit already in the CRM is matched, not
# duplicated, so running it again later brings across what is new.
#
#   --consent        CenterEdge's "Bulk email" box is real consent: the desk
#                    asks guests. Imported opt-ins are then honoured.
#   --no-consent     It is not (the default). Nobody imported is emailed until
#                    they opt in through the CRM.
#   --keep-files     Leave the exported CSVs in place instead of deleting them.
#   --reconfigure    Ask for the CenterEdge login again.
#   -h, --help       This text.

set -euo pipefail

if [ -t 1 ]; then
  BOLD=$'\033[1m'; DIM=$'\033[2m'; RED=$'\033[31m'; GREEN=$'\033[32m'
  YELLOW=$'\033[33m'; RESET=$'\033[0m'
else
  BOLD=''; DIM=''; RED=''; GREEN=''; YELLOW=''; RESET=''
fi

section() { printf '\n%s── %s ──%s\n' "$BOLD" "$1" "$RESET"; }
ok()      { printf '  %s✓%s %s\n' "$GREEN" "$RESET" "$1"; }
note()    { printf '  %s%s%s\n' "$DIM" "$1" "$RESET"; }
warn()    { printf '  %s!%s %s\n' "$YELLOW" "$RESET" "$1"; }

die() {
  printf '\n%sImport stopped:%s %s\n' "$RED" "$RESET" "$1" >&2
  shift || true

  for line in "$@"; do
    printf '  %s\n' "$line" >&2
  done

  exit 1
}

CONSENT=""
KEEP_FILES=0
RECONFIGURE=0

while [ $# -gt 0 ]; do
  case "$1" in
    --consent)     CONSENT="yes"; shift ;;
    --no-consent)  CONSENT="no"; shift ;;
    --keep-files)  KEEP_FILES=1; shift ;;
    --reconfigure) RECONFIGURE=1; shift ;;
    -h|--help)     sed -n '2,/^$/p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)             die "Unknown option: $1" "See: sudo bash $0 --help" ;;
  esac
done

[ "$(id -u)" -eq 0 ] || die "Run it with sudo:  sudo bash $0"

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CONFIG="$APP_DIR/config/config.php"
VENV="/opt/castle-crm/ce-venv"
ENV_FILE="/etc/castle-crm/centeredge.env"

[ -f "$CONFIG" ] || die "Castle CRM is not installed in $APP_DIR (there is no config/config.php)." \
  "Run this from the copy install.sh set up."

# The web server's user owns config.php and is the only one that can read it.
WEB_USER="$(stat -c %U "$CONFIG")"

# ---------------------------------------------------------------------------
section "Python and pymssql"
# ---------------------------------------------------------------------------

if [ ! -x "$VENV/bin/python" ] || ! "$VENV/bin/python" -c 'import pymssql' > /dev/null 2>&1; then
  if ! python3 -c 'import ensurepip, venv' > /dev/null 2>&1; then
    note "installing python3-venv"
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq python3-venv > /dev/null \
      || die "Could not install python3-venv." "Try:  sudo apt-get update && sudo apt-get install python3-venv"
  fi

  mkdir -p "$(dirname "$VENV")"
  [ -x "$VENV/bin/python" ] || python3 -m venv "$VENV"

  if ! "$VENV/bin/pip" install -q pymssql > /dev/null 2>&1; then
    # No prebuilt wheel for this Python: build it against FreeTDS.
    note "building pymssql (no ready-made package for this Python)"
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq freetds-dev build-essential python3-dev > /dev/null \
      || die "Could not install the packages pymssql builds against (freetds-dev)."
    "$VENV/bin/pip" install -q pymssql || die "pymssql would not install. The pip output is above."
  fi
fi

ok "pymssql ready in $VENV"

# ---------------------------------------------------------------------------
section "CenterEdge login"
# ---------------------------------------------------------------------------

if [ ! -s "$ENV_FILE" ] || [ "$RECONFIGURE" -eq 1 ]; then
  [ -t 0 ] || die "No CenterEdge login saved yet, and there is no terminal to ask for one." \
    "Run it once by hand:  sudo bash $0"

  note "The SQL Server login CenterEdge uses. A read-only one is best: this only runs SELECTs."
  read -r -p "  CenterEdge server address [192.168.1.2]: " ce_host
  read -r -p "  Port [1433]: " ce_port
  read -r -p "  Database [CenterEdge]: " ce_db
  read -r -p "  SQL username: " ce_user
  read -r -s -p "  SQL password: " ce_pass
  echo

  [ -n "$ce_user" ] && [ -n "$ce_pass" ] || die "A username and a password are both needed."

  install -d -m 0700 "$(dirname "$ENV_FILE")"
  umask 077

  # Quoted by Python's shlex, which is also what ce_export.py reads it with,
  # so a password with quotes or spaces in it survives the round trip.
  CE_HOST="${ce_host:-192.168.1.2}" CE_PORT="${ce_port:-1433}" CE_DB="${ce_db:-CenterEdge}" \
    CE_USER="$ce_user" CE_PASS="$ce_pass" python3 -c '
import os, shlex
for key in ("CE_HOST", "CE_PORT", "CE_DB", "CE_USER", "CE_PASS"):
    print(key + "=" + shlex.quote(os.environ[key]))
' > "$ENV_FILE"

  chmod 0600 "$ENV_FILE"
  unset ce_pass
  ok "saved in $ENV_FILE (root only)"
else
  ok "using the login saved in $ENV_FILE  (--reconfigure to change it)"
fi

# ---------------------------------------------------------------------------
section "Exporting from CenterEdge (read-only)"
# ---------------------------------------------------------------------------

OUT="$(mktemp -d /root/castle-crm-ce-export.XXXXXX)"

cleanup() {
  if [ "$KEEP_FILES" -eq 0 ]; then
    rm -rf "$OUT"
  fi
}
trap cleanup EXIT

"$VENV/bin/python" "$APP_DIR/tools/centeredge/ce_export.py" --env "$ENV_FILE" --out "$OUT" \
  || die "The export from CenterEdge failed (the reason is above)." \
    "Wrong login or address?  sudo bash $0 --reconfigure"

for name in contacts children visits; do
  [ -f "$OUT/$name.csv" ] || die "The export did not write $name.csv."
done

# ---------------------------------------------------------------------------
section "Consent"
# ---------------------------------------------------------------------------

if [ -z "$CONSENT" ]; then
  if [ -t 0 ]; then
    note "CenterEdge has a \"Bulk email\" box. It only counts as consent if guests were"
    note "actually asked at the counter, not ticked for them by default."
    read -r -p "  Did guests tick it themselves? [y/N]: " answer
    case "$answer" in
      y|Y|yes|YES) CONSENT="yes" ;;
      *)           CONSENT="no" ;;
    esac
  else
    CONSENT="no"
  fi
fi

consent_flag=()

if [ "$CONSENT" = "yes" ]; then
  consent_flag=(--consent-claimed)
  ok "opt-ins from CenterEdge will be honoured"
else
  ok "imported without email consent: nobody is emailed until they opt in"
fi

# ---------------------------------------------------------------------------
# Import, in the order the matching needs: children and visits attach to
# guests by CenterEdge customer number.
# ---------------------------------------------------------------------------

import_file() { # screen name, file
  section "Importing $1"

  # Through stdin, because the export is root's and the importer runs as the
  # web server's user, which must not be able to read /root.
  sudo -u "$WEB_USER" php "$APP_DIR/tools/import-csv.php" "$1" - "${consent_flag[@]}" < "$2" \
    || die "Importing $1 stopped (the reason is above). What was already imported stays." \
      "The full report is in the CRM: Settings → Import a file."
}

import_file guests   "$OUT/contacts.csv"
import_file children "$OUT/children.csv"
import_file visits   "$OUT/visits.csv"

section "Done"

if [ "$KEEP_FILES" -eq 1 ]; then
  warn "The exported files are kept in $OUT. They are the guest list: delete them when done."
else
  ok "exported files deleted"
fi

note "Each import's line-by-line report is in the CRM under Settings → Import a file."
note "Run this again any time to bring across what is new; nothing is duplicated."
