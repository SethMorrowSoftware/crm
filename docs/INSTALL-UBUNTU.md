# Installing Castle CRM on an Ubuntu server

The other way to install: a server of your own (a VPS, a cloud instance, a
box in the office) running plain **Ubuntu 22.04 or 24.04 LTS**, with no
cPanel. One script installs everything the CRM needs and then hands you to
the same eight-step web installer the cPanel route uses.

If you have cPanel hosting, follow [INSTALL.md](INSTALL.md) instead.

## What you need

- A fresh Ubuntu 22.04 or 24.04 server you can SSH into with `sudo`.
  1 GB of memory is enough.
- Ports 80 and 443 open to the internet (your cloud provider's firewall or
  security group).
- For HTTPS, a domain such as `crm.thecastlefuncenter.com` whose DNS **A
  record already points at the server**. Without one, the CRM is reachable on
  the server's IP address over plain HTTP, which is fine for a quick look but
  not for real passwords or guest data.

## Install

```bash
git clone https://github.com/SethMorrowSoftware/crm.git
cd crm
sudo ./install.sh --domain crm.example.com --email you@example.com
```

Leave out `--domain` and `--email` and the script asks for them (press Enter
to skip HTTPS for now). Add `--yes` to run without any questions.

It takes a few minutes and finishes by printing:

- the address of the web installer, `https://crm.example.com/install/`, and
- the database name, username and password to type on its step 3. They are
  also saved in `/root/castle-crm-database.txt`, readable only by root.

Open that address and follow the eight steps as [INSTALL.md](INSTALL.md)
describes them. Two differences from cPanel:

- **Skip "Add the cron job"** on the last page. The script already put it in
  `/etc/cron.d/castle-crm`.
- **Do not delete the `install/` folder.** It holds the database schema and
  `git pull` would bring it back. The web server stops serving it the moment
  setup finishes, so Settings → Health showing "Installer: still present but
  locked" is expected here.

## What the script did

| | |
|---|---|
| Packages | Apache, PHP (8.1 on 22.04, 8.3 on 24.04) with every extension the installer checks, MariaDB, cron, git, and certbot when HTTPS was asked for. An existing MySQL or MariaDB server is used rather than replaced. |
| Application | Copied the checkout to `/var/www/castle-crm` (change with `--dir`), `.git` included. Code is owned by root; only `config/`, `install/` and `storage/` are writable by the web server. |
| Database | Database `castle_crm` and user `castle_crm` with a random 32-character password, reachable only from the server itself. |
| Web server | The Apache site `/etc/apache2/sites-available/castle-crm.conf`. The CRM's own `.htaccess` does the routing and keeps `config/`, `storage/`, `tests/`, `.git` and the like private. Ubuntu's placeholder site is switched off when no domain was given. |
| Cron | `cron.php` every minute as `www-data`. It does nothing until setup finishes. |
| HTTPS | A Let's Encrypt certificate, renewed automatically by certbot's timer, with HTTP redirecting to HTTPS. |
| Firewall | If `ufw` is on, HTTP and HTTPS are allowed. |

Every step can be repeated. Running `sudo ./install.sh` again never rotates
the database password once setup has finished, never copies over an existing
install, and puts an existing certificate back in place.

## Adding HTTPS later

Point the domain at the server, then from the application folder:

```bash
cd /var/www/castle-crm
sudo ./install.sh --domain crm.example.com --email you@example.com
```

Turn on **Settings → System → Force HTTPS** once the padlock shows.

## Upgrading

1. Take a backup first (**Settings → Backups → Back up now**) and download it.
2. Pull the new version and re-run the script, which re-applies ownership
   and the Apache site:

   ```bash
   cd /var/www/castle-crm
   sudo git pull
   sudo ./install.sh --yes
   ```

3. Visit any page. Pending database migrations run automatically.
4. Check **Settings → System health**.

## Where things are

| | |
|---|---|
| Application | `/var/www/castle-crm` |
| Configuration | `/var/www/castle-crm/config/config.php` (database password and encryption key: back it up somewhere safe) |
| Application logs, and mail while it is in log mode | `/var/www/castle-crm/storage/logs/` |
| Web server logs | `/var/log/apache2/castle-crm-error.log` and `castle-crm-access.log` |
| Cron | `/etc/cron.d/castle-crm` |

The command-line tools work as on cPanel, run as the web server's user so
the files they write stay readable by it:

```bash
cd /var/www/castle-crm
sudo -u www-data php tools/reset-password.php owner@example.com
sudo -u www-data php tools/backup.php
```

## Trying the script out

`tests/install/ubuntu-smoke.sh` walks the web installer with a made-up venue
and owner, signs in, checks that private paths stay private and runs cron. CI
runs it after `install.sh` on clean Ubuntu 22.04 and 24.04 containers. Only
ever run it on a throwaway server: it completes the installer for you.
