#!/usr/bin/env bash
#
# Build the zip that goes on the server.
#
#   tools/build-release.sh                 # writes ../castle-crm-<version>-<commit>.zip
#   tools/build-release.sh /path/to/out.zip
#
# An upgrade on cPanel is "upload the zip, extract it over the old one"
# (docs/INSTALL.md, Upgrading). GitHub's "Download ZIP" would do, but it ships
# the test suite, the CI workflow, seven megabytes of screenshots and the
# CenterEdge export tool to a public web root — none of which the application
# reads, and some of which (tests/, tools/) .htaccess has to work to keep
# unreachable. This builds from the COMMITTED tree, so nothing uncommitted and
# no local config, guest export or storage file can ride along.
#
# What it leaves out, and why:
#   .github/ .claude/        CI and agent tooling
#   tests/                   the suite; it boots against a database
#   docs/screenshots/        7MB of pictures
#   tools/centeredge/        runs on the venue LAN, never on the web host
#   tools/screenshots.js, tools/screenshot-guard.php, tools/dev-install.php,
#   tools/dev-server.php, tools/loadtest.php, tools/ci-local.sh,
#   tools/build-release.sh   development only
#
# What it keeps: everything the application, the installer and the remaining
# CLI tools (backup.php, reset-password.php) need, and docs/ for the operator.
#
# That includes install.sh, tools/cli-install.php and tools/server/, which a
# cPanel venue cannot use — no root, no apt — but which are the whole install
# on a server, and somebody deploying this zip onto one would otherwise have
# no installer at all. They are inert where they cannot run, and .htaccess
# denies both the .sh and tools/ anyway.

set -euo pipefail

cd "$(dirname "$0")/.."

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "Uncommitted changes. A release is built from a commit; commit or stash first." >&2
  exit 1
fi

version=$(tr -d '[:space:]' < VERSION)
commit=$(git rev-parse --short HEAD)
out="${1:-../castle-crm-${version}-${commit}.zip}"
# No top-level folder: docs/INSTALL.md has the operator extract it straight
# into the folder the domain serves (public_html/crm here), so the files must
# land there, not in public_html/crm/castle-crm/.
prefix=""

exclude=(
  ':(exclude).github'
  ':(exclude).claude'
  ':(exclude)tests'
  ':(exclude)docs/screenshots'
  ':(exclude)tools/centeredge'
  ':(exclude)tools/screenshots.js'
  ':(exclude)tools/dev-install.php'
  ':(exclude)tools/ci-local.sh'
  ':(exclude)tools/build-release.sh'
  ':(exclude)tools/loadtest.php'
  ':(exclude)tools/dev-server.php'
  ':(exclude)tools/screenshot-guard.php'
)

rm -f "$out"
git archive --format=zip -o "$out" HEAD -- . "${exclude[@]}"

# What must never be in it, checked on the zip itself rather than trusted.
listing=$(unzip -Z1 "$out")
for forbidden in "${prefix}config/config.php" "${prefix}tests/" "${prefix}tools/centeredge/" "${prefix}.git/"; do
  if grep -q "^${forbidden}" <<< "$listing"; then
    echo "Refusing: ${forbidden} is in the archive." >&2
    rm -f "$out"
    exit 1
  fi
done

for required in "${prefix}index.php" "${prefix}cron.php" "${prefix}install/index.php" "${prefix}.htaccess" "${prefix}VERSION"; do
  if ! grep -qx "$required" <<< "$listing"; then
    echo "Refusing: ${required} is missing from the archive." >&2
    rm -f "$out"
    exit 1
  fi
done

files=$(wc -l <<< "$listing")
size=$(du -h "$out" | cut -f1)
echo "Built $out — ${files} entries, ${size}, version ${version} at ${commit}."
echo "Upload it in cPanel File Manager and extract it over the existing install."
echo "It carries no config/config.php and nothing from storage/ but its empty folders and their guards, so the live configuration and data are untouched."
