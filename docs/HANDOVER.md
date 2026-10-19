# Handover: where things stand, and what is left to do

Written 19 October 2026, at the end of the session that took the CRM from
"phases 1–8 built" to "customised for The Castle". Read this first on a new
computer. It assumes nothing about the old one except what it says to copy.

---

## 1. Where things stand

Everything is merged into `main`, which is the repository's default branch and
its only branch. Every PR below passed the full local CI (`tools/ci-local.sh`:
PHP 8.1, 8.2, 8.3 and 8.4, plus the web installer walk-through) before merging.

| PR | What it did |
|---|---|
| #11 | Runs on PHP 8.4; fixed sign-in crashing on a PHP without Argon2; `tools/ci-local.sh` |
| #12 | The Castle's real party list and prices from CenterEdge; **Settings → Party packages** |
| #13 | `tools/centeredge/ce_export.py`; a **Children** import type |
| #14 | Fixed every birthday and party date showing a day early; UI polish; `tools/dev-install.php`; `tools/build-release.sh` |
| #15 | `tools/centeredge/ce_sync.py` (nightly sync); exact-match API filters |
| #16 | **Settings → Message templates** |
| #17 | **Settings → Hours** (seasons, closures, rides down) |
| #18 | **Settings → Offers** (coupons); two table layout fixes |
| #19 | **Settings → Attractions**; corrected an over-claim in `PRIVACY.md` |
| #20 | Restored #14, which #15's squash had silently reverted |

**Nothing has been deployed to the live site yet.** It is installed at
<https://thecastletechteam.com/crm> (cPanel shared hosting at LiveCode
Hosting) on the code from before #11. Section 3 is the upgrade.

`docs/CHANGELOG.md` → "Unreleased — next" has the detail of every change.

---

## 2. Setting up the new computer

### 2a. The repository

```bash
gh auth login                 # GitHub.com → HTTPS → browser
gh auth setup-git
git clone https://github.com/SethMorrowSoftware/crm.git
cd crm
```

### 2b. To run the tests and CI (optional, for development)

The CI needs PHP and a MySQL-compatible database, nothing else. With root, the
distribution packages are simplest:

```bash
sudo apt install php-cli php-mysql php-mbstring php-curl mariadb-server
```

Without root, the old machine used self-contained PHP builds from
<https://static-php.dev> (`php-8.x.y-cli-linux-x86_64.tar.gz`, copied to
`~/.local/bin/php8.1` … `php8.4`) and a MariaDB started as the user — see
`docs/TESTING.md` → "Running the same thing locally". Then:

```bash
tools/ci-local.sh            # the whole CI, ~15 minutes
tools/ci-local.sh --quick    # lint, unit and static only, seconds
```

**Only one full run at a time on a machine**, across every checkout and
worktree: they all use the same `castle_crm_test` database. A `--quick` run
alongside is fine.

### 2c. To look at the app with example data

```bash
php tools/dev-install.php                      # builds castle_crm_dev
php -S 127.0.0.1:8777 -t . tools/dev-server.php
# http://127.0.0.1:8777 — demo@castle.test / demo-passphrase-99
```

Screenshots of every page: `npm install --no-save playwright`, then
`node tools/screenshots.js`.

### 2d. To reach CenterEdge (must be on the venue LAN)

CenterEdge's SQL Server is at **192.168.1.2:1433**, database `CenterEdge`.
**It is used strictly read-only** — every tool here only runs `SELECT`. The
new computer can only reach it from the venue network.

```bash
python3 -m venv ~/.local/share/castle-crm/ce-venv
~/.local/share/castle-crm/ce-venv/bin/pip install pymssql

mkdir -p ~/.config/castle-crm
cat > ~/.config/castle-crm/centeredge.env <<'ENV'
CE_HOST=192.168.1.2
CE_PORT=1433
CE_DB=CenterEdge
CE_USER=...
CE_PASS='...'
ENV
chmod 600 ~/.config/castle-crm/centeredge.env
```

The username and password are **not** in this repository and never should be.
Ask whoever holds them; the old machine had them in that same file.

---

## 3. To do: deploy to the live site

1. In the CRM, **Settings → Backups → Back up now**, and download the file.
2. On a checkout of `main`: `tools/build-release.sh`. It writes
   `../castle-crm-<version>-<commit>.zip` (about 1.2 MB — no tests,
   screenshots or development tools).
3. cPanel → **File Manager** → `public_html/crm` → **Upload** the zip →
   select it → **Extract** into that same folder, overwriting → delete the
   zip.
4. Open any page. Two database changes apply on their own on that first
   request (`013` adds the real party packages without touching any price
   you have changed; `014` lets imports take children).
5. Check **Settings → System health**, then look at **Settings → Party
   packages**.

`docs/INSTALL.md` → "Upgrading" is the long version.

---

## 4. To do: bring the guests across from CenterEdge

On a computer on the venue LAN (section 2d):

```bash
~/.local/share/castle-crm/ce-venv/bin/python tools/centeredge/ce_export.py
```

It writes `~/castle-crm-exports/<today>/` — about 85,000 guests, 32,000
children and 134,000 visit-days — in seconds. Then **in the live CRM, Settings
→ Import a file**, in this order, each one **dry-run first**:

1. `contacts.csv` as **Guests**. Tick the consent affirmation only if you are
   satisfied CenterEdge's "Bulk email" box was ticked by guests, not by
   default. Without it nobody imported is emailed.
2. `children.csv` as **Children**.
3. `visits.csv` as **Visit history**.

**If the import page says the upload limit is 2 MB** (common on shared
hosting), split each file into parts under that size, keeping the header row
on every part, and import the parts in order. Re-importing is safe: guests,
children and visits are matched, never duplicated.

**The export files are the guest list. Never commit them, and delete them
once imported.** `tools/centeredge/README.md` has the full procedure and what
the export deliberately leaves out (under-18s are never contacts; placeholder
phone numbers are dropped).

---

## 5. To do: switch on the nightly sync

After section 4, so the CRM keeps up with CenterEdge without another export:

1. In the live CRM, **Settings → API tokens** → create a token for a
   dedicated user, ticked for **contacts: read** and **contacts: write** only.
2. On the venue-LAN computer:

   ```bash
   cat > ~/.config/castle-crm/crm-api.env <<'ENV'
   CRM_URL=https://thecastletechteam.com/crm
   CRM_TOKEN=cc_live_...
   ENV
   chmod 600 ~/.config/castle-crm/crm-api.env

   # once, from the day of the import, dry run first
   ~/.local/share/castle-crm/ce-venv/bin/python tools/centeredge/ce_sync.py --since 2026-10-19 --dry-run
   ~/.local/share/castle-crm/ce-venv/bin/python tools/centeredge/ce_sync.py --since 2026-10-19
   ```

3. `crontab -e`, one line (adjust the path to where the repo is):

   ```
   15 3 * * * $HOME/.local/share/castle-crm/ce-venv/bin/python $HOME/crm/tools/centeredge/ce_sync.py >> $HOME/.local/share/castle-crm/ce-sync.log 2>&1
   ```

Consent is **not** synced unless you add `CE_CONSENT_SYNC=1` and the wording
guests agreed to (`CE_CONSENT_DISCLOSURE='…'`) to `crm-api.env`. See
`tools/centeredge/README.md`.

---

## 6. Decisions waiting on the venue

**Erasing a guest on request.** `docs/PRIVACY.md` used to promise a "hard
erase" that was never built; it now says so. To build it properly needs a
policy call: when a guest asks to be erased, are their **signed waivers** and
**consent records** kept (as the venue's legal records) or removed? The
recommendation is to keep both and remove everything else — names, contact
details, notes, messages, form answers, children. Until then, the interim
procedure is in `PRIVACY.md`.

**PatchRetention.** The venue is moving off it and wants its data — most
importantly the SMS opt-in list, which CenterEdge barely has (2,474 text
opt-ins against 45,163 email opt-ins). Waiting on an export from
PatchRetention. A contacts export with phone, email, name and **SMS opt-in
status and date** can likely go straight through **Settings → Import a file**
as Guests; send a sample (headers and one fake row) first to check the columns
map.

---

## 7. Useful facts found along the way

- **CenterEdge charges cards 3.5% over list.** A $399.00 party rings up at
  $412.97. The CRM stores and quotes list prices.
- **InflataPark is about half of all parties** (792 of the last year's), then
  Roller Skating (292), Laser Tag (213) and Bowling (125).
- **The venue's own phone number is on 112 CenterEdge accounts**, and
  123-456-7890 on 150 — staff typing a placeholder. The export drops any
  number on more than ten accounts.
- **Lessons recorded the hard way:** squash a branch on its own merge-base and
  rebase, never `git reset --soft main` after main has moved (that is how #15
  reverted #14); and a test that only checks the database after a POST misses
  a 500 — assert the response too.
