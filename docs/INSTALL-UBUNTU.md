# Installing Castle CRM on an Ubuntu server

Clone the repository, run one script, sign in. That is the whole procedure:

```bash
sudo git clone https://github.com/SethMorrowSoftware/crm.git /var/www/castle-crm
cd /var/www/castle-crm
sudo ./install.sh --domain crm.example.com --admin-email you@example.com
```

About three minutes, most of it `apt`. The script prints the address and a
generated password to sign in with.

> **On cPanel shared hosting instead?** Read [`INSTALL.md`](INSTALL.md). This
> guide is for a server you have root on — a VPS, a cloud instance, a box in
> the back office. Both installs end up with the same application; what
> differs is who does the server configuration, and here it is this script
> rather than cPanel.

---

## Before you start

| You need | Why |
|---|---|
| **Ubuntu 22.04 or 24.04**, with root or `sudo` | Debian 12 works too. Ubuntu 20.04 ships PHP 7.4 and Castle CRM needs 8.1 — see [Older Ubuntu](#older-ubuntu). |
| **About 2 GB of disk** | ~50 MB of application, the rest is MariaDB, PHP, and room for backups. |
| **A domain or subdomain pointing at the server** | Not strictly required — the script will serve on the machine's hostname — but you need one for HTTPS, and you want HTTPS. |

You do **not** need Composer, Node, npm, Docker, or a build step. Castle CRM
is vanilla PHP by design (`docs/SPEC.md` §3.9).

---

## What the script does

In order, and all of it re-runnable:

1. **Installs packages**: Apache and `mod_php`, PHP 8.1+ with `pdo_mysql`,
   `mbstring`, `curl`, `gd`, `intl`, `opcache` and `zip`, and MariaDB.
2. **Creates the database** `castle_crm` and a user with a generated 128-bit
   password, granted on that database only.
3. **Puts the application in place** — this checkout, or a copy of it at
   `--path` — and gives `storage/` to the web server. `.git` is made
   unreadable to anybody else, since a clone in a web root is otherwise
   publishing its own history.
4. **Writes the vhost** from `tools/server/apache-vhost.conf`, enables
   `mod_rewrite`, `headers`, `expires`, `deflate` and `setenvif`, and
   disables Apache's default site so the machine's IP does not answer with
   "It works!".
5. **Installs PHP settings** for the web SAPI (16 MB uploads, a 120-second
   limit, `expose_php = Off`), because `.htaccess` `php_value` lines only
   apply under `mod_php`.
6. **Installs the cron line** at `/etc/cron.d/castle-crm` — every minute, as
   `www-data`. This is the one piece of server configuration Castle CRM
   cannot do for itself.
7. **Sets up the application** through `tools/cli-install.php`: the 99-table
   schema, the permissions and roles, the venue's attractions, packages and
   hours, the eight playbooks and their 112 automations, the email templates,
   and your owner account.
8. **Closes the web installer** by writing `install/.lock` as root.

Steps 7 and 8 are the same engine the eight-screen web installer uses
(`install/engine.php`), so a server install and a cPanel install end in the
same state — including the guardrails:

> **Nothing can reach a guest.** Safe Mode is on, so every email and text is
> redirected to the owner's own address. All 112 automations and all 8
> playbooks are inactive. Mail has no provider: messages are written to
> `storage/logs/` instead of sent. Leaving that state takes a distinct
> permission and typing the venue's name to confirm.

---

## Options

Everything has a default; these are the ones worth knowing.

| Option | Default | |
|---|---|---|
| `--domain NAME` | the machine's hostname | `ServerName`, and the address recorded for links in email. |
| `--admin-email EMAIL` | asked for | The owner account. |
| `--admin-name NAME` | `Owner` | |
| `--admin-password-file F` | a generated passphrase, printed once | Read the password from a file instead. |
| `--path DIR` | this checkout | Serve from `DIR`, copying the checkout there (`.git` included, so `git pull` still upgrades it). |
| `--venue-name NAME` | `The Castle Fun Center` | |
| `--timezone AREA/CITY` | the machine's, or `America/New_York` when that is UTC | **The venue's clock**, not the server's. See the note below. |
| `--web-server nginx` | `apache` | See [nginx](#nginx). `none` writes no vhost at all. |
| `--letsencrypt EMAIL` | off | Get a certificate with certbot afterwards. |
| `--finish-in-browser` | off | Provision the server and stop; finish at `/install/` in a browser. |
| `--seed-demo` | off | Example guests, deals and bookings, all marked as examples. |
| `--no-seed-playbooks`, `--no-seed-venue` | both seeded | Skip the playbook library, or the Castle's own attractions and prices. |
| `--external-db` | off | Use a database server you already have; needs `--db-password-file`. |
| `--skip-packages` | off | Install nothing from `apt`. |
| `--force-config` | off | Overwrite a vhost, PHP ini or cron file this script wrote before. |
| `-y`, `--yes` | off | Never ask anything. |

`./install.sh --help` lists all of them, including the `--db-*` options.

**About the timezone.** Most server images are on UTC, and a venue on UTC is
wrong in a way that is easy to miss: after 8pm Eastern the CRM's "today" is
already tomorrow, so a party booked for tonight lands on the wrong run sheet.
When the machine is on UTC the script uses `America/New_York` and says so.
Pass `--timezone`, or change it later under **Settings → Venue**.

---

## After it finishes

Three things, in this order:

1. **Get a certificate.** A plain-HTTP install sends staff passwords and guest
   details in the clear.

   ```bash
   sudo ./install.sh --letsencrypt you@example.com --yes
   ```

   That runs certbot against the vhost and redirects HTTP to HTTPS. Then turn
   on **Settings → System → Require HTTPS**, so the application refuses an
   insecure request as well.

2. **Work the dashboard checklist.** It asks you to confirm the venue details
   and the seeded prices (they came from a public website and may be stale),
   configure an email provider under **Settings → Email**, and take a backup.

3. **Check cron is running.** **Settings → System health** shows the
   heartbeat. If it says cron has not run, see
   [Troubleshooting](#troubleshooting).

Leave Safe Mode last, deliberately, once you have sent yourself a test of
everything you plan to switch on.

---

## Where everything is

| | |
|---|---|
| Application | `/var/www/castle-crm` (or your `--path`) |
| Configuration | `config/config.php` — the database password and the encryption key, `0640` and owned by `www-data` |
| Logs | `storage/logs/castle-YYYY-MM-DD.log`, and Apache's in `/var/log/apache2/castle-crm-*.log` |
| Backups | `storage/backups/`, written by cron daily and by **Settings → Backups** |
| Apache vhost | `/etc/apache2/sites-available/castle-crm.conf` |
| nginx site | `/etc/nginx/sites-available/castle-crm` |
| PHP settings | `/etc/php/8.x/apache2/conf.d/99-castle-crm.ini` on a dedicated Apache server; otherwise Castle CRM's own PHP-FPM pool, `/etc/php/8.x/fpm/pool.d/castle-crm.conf`, on `/run/php/castle-crm.sock` |
| Cron | `/etc/cron.d/castle-crm` |

The installed copies of the vhost, the site and the cron file are yours to
edit: re-running `install.sh` keeps them unless you pass `--force-config`.
They are rendered from the templates in `tools/server/`.

---

## Upgrading

```bash
sudo git -C /var/www/castle-crm pull
```

That is all. The next request notices the code has changed and applies any new
schema file itself (`cc_apply_pending_upgrade()` in `app/bootstrap.php`) —
there is no migration command to remember. Take a backup first from
**Settings → Backups**; a schema change is not reversible by `git`.

Re-run `sudo ./install.sh --yes` after a pull only if you want the server
configuration refreshed too — new packages, a changed vhost template
(`--force-config`), repaired permissions. It will not re-install over your
data: it detects the existing `config/config.php`, keeps the database and
every account, and tells you so.

**Keep the `install/` directory.** A cPanel install is told to delete it,
because there an upgrade is a fresh unzip that puts it back. Here the
directory is part of the checkout, and `install/schema` is what applies the
next version's tables. `install/.lock` is what closes the web installer, and
the vhost denies `install/schema`, `install/seeds` and `install/steps`
outright.

---

## On a server shared with other applications

Castle CRM can live on a machine that already runs other sites. The script
notices them (any enabled site in `/etc/nginx` or `/etc/apache2` other than
the stock default) and then adds Castle CRM as one more site, changing
nothing the others rely on:

```bash
sudo ./install.sh --domain crm.example.com --admin-email you@example.com
```

- **It needs either a hostname or a port of its own.** The web server tells
  sites apart by name or by port. With a DNS name pointed at the server, pass
  `--domain crm.example.com`. With no name yet, pass a free port instead and
  the CRM is at `http://<server IP>:8088`:

  ```bash
  sudo ./install.sh --port 8088 --path /var/www/castle-crm --admin-email you@example.com
  ```

  Without either, the script refuses to start, having changed nothing. It also
  refuses a name another site already uses, and a port something is already
  listening on. A hostname and HTTPS can be added later by re-running with
  `--domain` and `--letsencrypt`.
- **The web server already on port 80 is used.** nginx or Apache, whichever is
  serving, gets a `castle-crm` site; the other is never installed. If port 80
  belongs to something else (Caddy, Traefik, a Docker container), the script
  stops and says so: run it with `--web-server none` and point that server at
  the application, with PHP on `unix:/run/php/castle-crm.sock`.
- **PHP runs in Castle CRM's own PHP-FPM pool**, `castle-crm`, with its own
  upload and memory limits, so nothing changes for another PHP application.
  On Apache it is reached through `proxy_fcgi`, which works under any MPM, so
  Apache is never switched to prefork and mod_php is never enabled.
- **The default site stays enabled**, and nothing server-wide changes (no
  `ServerTokens`, no `conf.d` PHP file).
- **An existing MySQL or MariaDB server is used as it is.** Castle CRM gets
  its own database and user on it and never touches another database. If root
  cannot log in over the socket, create them yourself and use
  [`--external-db`](#a-database-you-already-have).

## nginx

```bash
sudo ./install.sh --web-server nginx --domain crm.example.com --admin-email you@example.com
```

Installs nginx and PHP-FPM, and writes `/etc/nginx/sites-available/castle-crm`
from `tools/server/nginx-site.conf`.

One thing worth knowing if you ever edit that file: **nginx never reads
`.htaccess`**. On Apache the shipped `.htaccess` is the real request policy —
the front controller rewrite, the security headers, the deny rules — and the
vhost just allows it to be read. The nginx site has to restate all of it, so
every deny rule is there in nginx's own syntax, and
`tests/static/QualityGatesTest.php` holds it to the same list as the
`.htaccess` so the two cannot drift apart. The `^~` on the deny locations is
not decoration: without it a request for `/tests/run.php` would match the
`\.php$` handler and **execute the test runner** against your live database.

Apache and nginx both want port 80. The script uses whichever is already
serving it, and refuses a `--web-server` that would have to fight it for the
port.

---

## Other ways to run it

### Finish in a browser

```bash
sudo ./install.sh --finish-in-browser --domain crm.example.com
```

Does everything except the setup itself, then prints the database credentials
to type into step 3 of the web installer at `http://your-domain/install/`.
Useful if you would rather pick the venue details and the seeds on screen.
Run `sudo ./install.sh --yes` again afterwards: it takes back the write access
the web installer needed on `config/` and `install/`.

### A database you already have

```bash
printf '%s' 'the-password' | sudo tee /root/db-pass > /dev/null
sudo chmod 600 /root/db-pass
sudo ./install.sh --external-db --db-host db.internal --db-name castle_crm \
  --db-user castle_crm --db-password-file /root/db-pass --admin-email you@example.com
```

Create the database and user yourself first, with full privileges on that one
database — the installer creates 99 tables and the backup feature reads all of
them. Castle CRM never touches another database.

Passwords are never taken as options, here or anywhere: every process on the
machine can read a command line (`ps auxww`).

### Older Ubuntu

Ubuntu 22.04, 24.04 and 26.04 need nothing extra: the script uses the PHP
each release ships (8.1, 8.3 and 8.5). Only Ubuntu 20.04, which ships PHP 7.4,
needs a PHP repository added first, then install as usual:

```bash
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update
sudo ./install.sh --domain crm.example.com --admin-email you@example.com
```

The script checks the PHP version before it changes anything and says this
rather than failing halfway.

---

## Troubleshooting

**"The repository 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu resolute
Release' does not have a Release file."** The ondrej/php PPA was added on a
release it does not publish (it trails the newest Ubuntu), and apt refuses to
update while it is listed. That release's own PHP is the one to use. The
script sets the PPA aside by itself and carries on; to remove it by hand:

```bash
sudo rm -f /etc/apt/sources.list.d/ondrej-ubuntu-php-* && sudo apt-get update
```

**403 Forbidden on every page.** The web server cannot read the application.
Almost always a clone inside a home directory: Ubuntu has made those readable
only by their owner since 21.04. Install somewhere the web server can reach:

```bash
sudo ./install.sh --path /var/www/castle-crm --admin-email you@example.com
```

The script checks this before it installs anything and refuses with the same
advice.

**500 on every page, nothing in the browser.** Read
`storage/logs/castle-*.log`, then `/var/log/apache2/castle-crm-error.log`. The
usual cause is `storage/` not being writable by `www-data`; `sudo
./install.sh --yes` repairs the permissions.

**Links 404 but the home page works.** `mod_rewrite` or `AllowOverride All` is
missing, so the front controller never sees the request. Check
`apache2ctl -M | grep rewrite` and that your vhost still has `AllowOverride
All`. Castle CRM also works without rewriting, at
`/index.php?_route=/your/path`, which is a useful thing to try to confirm the
diagnosis.

**Nothing scheduled happens.** No automation fires, no digest arrives, the
health page says the heartbeat is stale. Check the daemon is running
(`systemctl status cron`), then run the tick by hand and read what it says:

```bash
sudo -u www-data php /var/www/castle-crm/cron.php
```

**Nobody can sign in.** Reset a password from the command line — this works
with no mail provider configured, which a fresh install has:

```bash
sudo -u www-data php /var/www/castle-crm/tools/reset-password.php owner@example.com --generate
```

Run it with no arguments to list the accounts, or with `--unlock` to clear a
lockout.

**Email is not arriving.** Expected on a new install: mail defaults to the log
driver and Safe Mode redirects everything to the owner. Configure a provider
under **Settings → Email**, send yourself a test, then leave Safe Mode. A
password reset works in the meantime — the whole message, link included, is
written to `storage/logs/`.

**I lost the database password.** It is in `config/config.php`, which only
root and `www-data` can read.

**Starting over.** Delete `config/config.php` and `install/.lock`, drop the
database, and run the script again. That throws the data away; export anything
you want first from **Settings → Backups**.

---

## What this script does not do

Honest gaps, so nobody assumes otherwise:

- **No off-site backups.** Cron writes a daily backup to `storage/backups/` on
  the same disk as the database. That survives a mistake, not a dead server.
  Copy them somewhere else — `rsync`, a cloud bucket, anything.
- **No OS hardening.** It does not install `unattended-upgrades`, `fail2ban`
  or an SSH policy. It opens the firewall for HTTP and HTTPS if `ufw` is
  already active, and that is the extent of it.
- **No monitoring.** The health page is inside the application, so it cannot
  tell you the machine is down. A one-line uptime check against `/login` from
  anywhere else covers the gap.
- **No mail server.** Castle CRM sends through a provider's API or SMTP, which
  you configure in the application. It does not install Postfix, and sending
  directly from a VPS mostly lands in spam anyway.
