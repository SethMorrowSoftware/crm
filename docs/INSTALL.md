# Installing Castle CRM on cPanel

This is the whole procedure, written for somebody who has used cPanel to upload
files and nothing more. It takes about twenty minutes, and most of that is
waiting for an upload.

If something here does not match what you see, the mismatch is probably your
host's cPanel theme. The names in **bold** are cPanel's own labels; hunt for
those rather than following screenshots.

---

## Before you start

You need four things:

1. **cPanel access** to a domain or subdomain.
2. **PHP 8.1 or newer** available on that domain. Almost every host has it;
   you may have to select it (see step 2).
3. **A MySQL database** you can create. Every shared plan allows this.
4. **About 50 MB of disk**, plus room for backups — reckon on 200 MB total for
   a venue's first couple of years.

You do **not** need: Composer, Node, npm, a build step, SSH, a VPS, or a
developer. That is the point of the architecture; see `docs/SPEC.md` §3.9.

---

## 1. Upload the files

1. In cPanel, open **File Manager**.
2. Navigate to the folder the domain serves. For a main domain that is
   `public_html`; for a subdomain it is usually `public_html/subdomain` or a
   folder you chose when you created it.
3. Click **Upload** and upload the Castle CRM zip.
4. Back in File Manager, select the zip, choose **Extract**, and extract it
   into that same folder.
5. Delete the zip.

You should now see `index.php`, `cron.php`, `.htaccess`, and the folders
`app`, `assets`, `config`, `install`, `storage` and `tools` in the web root.

> **If you extracted into a subfolder by mistake** — you will see a single
> folder such as `castle-crm` instead of the files above. Open it, select
> everything, choose **Move**, and move it up one level. Castle CRM works fine
> in a subfolder, but it is easier to reason about at the root.

### Permissions

File Manager sets these correctly almost always. If the installer complains
later, select the `storage` folder, choose **Permissions**, and set it to
**0755** with **Recurse into subdirectories**.

---

## 2. Choose PHP 8.1 or newer

1. In cPanel, open **MultiPHP Manager**.
2. Tick the domain you are installing on.
3. Choose **8.1**, **8.2** or **8.3** from the **PHP Version** list and click
   **Apply**.

Castle CRM checks this on every request and will tell you plainly if the
version is too old, so you cannot get this wrong silently.

While you are here, confirm the extensions. Open **Select PHP Version** (or
**MultiPHP INI Editor**) and make sure these are ticked:

| Extension | Needed for | If missing |
|---|---|---|
| `pdo_mysql` | everything | **Required.** The installer cannot continue. |
| `mbstring` | names and messages in any language | **Required.** |
| `openssl` | encrypting stored provider passwords | **Required.** |
| `json` | settings, merge data, API | **Required.** (On by default.) |
| `curl` | outbound integrations, later phases | Install works; integrations will not. |
| `zlib` | compressed backups | Backups still work, just larger. |
| `intl` | locale-aware formatting | Optional. |

The installer checks all of these on its first screen and says which are
missing, so you need not audit them by hand.

---

## 3. Create the database

1. In cPanel, open **MySQL® Databases**.
2. Under **Create New Database**, enter a name — `castlecrm` is fine. cPanel
   prefixes it with your account name, giving something like
   `myaccount_castlecrm`. **Write the full name down.**
3. Scroll to **MySQL Users → Add New User**. Create a user — `castlecrm` —
   and use the **Password Generator**. **Write the full username and the
   password down.**
4. Scroll to **Add User To Database**. Select the user and the database, click
   **Add**, then tick **ALL PRIVILEGES** and click **Make Changes**.

Castle CRM needs full privileges on its own database: the installer creates
tables, and the backup feature reads them all. It never touches any other
database.

---

## 4. Run the installer

Visit **`https://your-domain.com/install/`** in a browser.

Eight steps, each of which explains itself. The notes below cover only the
decisions that are easy to get wrong.

### Step 2 — Database

Enter the values from step 3. Host is almost always `localhost`. Leave the
table prefix as `cc_` unless you are sharing a database with another
application, in which case make it unique.

The installer tests the connection before it lets you continue, so a typo is
caught here rather than halfway through.

### Step 3 — Venue details

Your address is required, and not for decoration: US law requires a physical
postal address in every bulk marketing email, and Castle CRM adds it to the
footer automatically from this field. Put the real one.

The timezone decides when quiet hours apply. Get it right or a reminder will
go out at 3am.

### Step 4 — Your account

Use a real email address you can receive mail at. Use a password you do not
use anywhere else: this account can see every guest's details.

> **If you lose this password** before email is working, you are not locked
> out. See [Recovering a lost password](#recovering-a-lost-password) below.

### Step 7 — What to seed

| Seed | Recommendation |
|---|---|
| Venue data (attractions, packages, pipelines) | **Keep it.** Prices came from your public website and may be stale — the installer marks them for you to confirm. |
| The 8 playbooks and their automations | **Keep it.** Everything arrives switched off. It is a library, not a machine that starts running. |
| Example guests and bookings | **Leave it off** for a real install. Useful if you want to explore first; every row is fictional, unreachable and tagged `demo-data` so you can delete it later. |

Email templates are always seeded, because the password-reset message is one
of them.

### Step 8 — Finish

**Do not close this page until you have copied two things from it:**

1. **The cron line.** You need it for step 5.
2. **The DNS records.** You need them for step 6.

---

## 5. Set up cron

This is the only server configuration Castle CRM needs, and the only step that
is not optional. Without it, nothing scheduled happens: no reminders, no
nightly backup, no queued mail, no retention pruning.

1. In cPanel, open **Cron Jobs**.
2. Under **Add New Cron Job**, set **Common Settings** to
   **Once Per Minute (\* \* \* \* \*)**.
3. Paste the command from installer step 8. It looks like:

   ```
   /usr/local/bin/php /home/myaccount/public_html/cron.php >/dev/null 2>&1
   ```

4. Click **Add New Cron Job**.

Once a minute sounds like a lot. It is not: each run has a wall-clock budget
of about 50 seconds, stops cleanly when it runs out, picks up where it left
off next minute, and does nothing at all when there is nothing to do.

### Check it worked

Sign in and open **Settings → System health**. Within a couple of minutes the
cron row should turn green. There is a **Test cron** button that sets a marker
the next run clears, so you can confirm it without server access.

### If your host has no cron

Some very cheap plans do not offer it. Castle CRM can be triggered over HTTP
instead, using the token printed on installer step 8:

```
https://your-domain.com/cron.php?token=THE_TOKEN_FROM_STEP_8
```

Point any free uptime monitor at that URL on a one-minute schedule. Keep the
token secret — it is the authorisation.

---

## 6. Email and DNS

Castle CRM installs with its mail driver set to **log**: nothing is sent, and
every message is written to `storage/logs/` instead. That is deliberate, and
it is not a broken state — a password reset still works, because the link is
in the log where an administrator can retrieve it.

To send real email, open **Settings → Email**:

1. Choose a driver. **SMTP** is the right answer for anything beyond a handful
   of messages a day; your own cPanel mailbox works, and so does a sending
   service.
2. Set the **From** address to one at your own domain. A Gmail or Yahoo
   address will be rejected outright by most recipients, because those
   providers' DMARC policies forbid anyone else sending as them.
3. Save, then use **Send a test**.

Then do the DNS work, which decides whether your mail reaches an inbox or a
spam folder. The shortcut: in cPanel open **Email Deliverability** and click
**Repair** on your domain. It writes SPF and DKIM for you. If you are editing
DNS by hand, the exact records are on the **Settings → Email** page.

Expect the first test message to land in spam. That is normal for a new
sending domain; the DNS records are the fix.

---

## 7. Get a certificate and require HTTPS

1. In cPanel, open **SSL/TLS Status**.
2. Tick your domain and click **Run AutoSSL**. It is free and takes a few
   minutes.
3. Once `https://your-domain.com` loads with a padlock, sign in, open
   **Settings → System**, and tick **Require HTTPS for every page**.

Do these in that order. Turning on the HTTPS requirement before a certificate
works would redirect you to an address that does not answer, and lock you out
of the page you need to turn it off again. The settings page warns you if you
are reading it over plain HTTP and will not let you make that mistake quietly.

---

## 8. Delete the install folder

The installer locks itself when it finishes, but removing it entirely is
better. In **File Manager**, select the `install` folder and choose **Delete**.

---

## 9. Turn Safe Mode off — when you are ready

Castle CRM installs with **Safe Mode on**. Every message it would send to a
guest is redirected to the owner's address instead, with the intended
recipient noted at the top. No guest can be contacted, at all, by any route.

Use it. Activate a playbook, let it run, read what arrives in your own inbox
as a parent would read it. Then, when the words are yours and the prices are
right, open **Settings → System** and turn Safe Mode off. You will be asked to
type your venue's name to confirm, and the change is recorded in the audit log
with your name on it.

That friction is on purpose. It is the moment the software starts talking to
your customers.

---

## Backups

Castle CRM takes its own, nightly, as part of the cron run, and keeps the most
recent seven. **Settings → Backups** lists them, takes one on demand, and lets
you download one.

Two things that are **not** in a database backup, and that you must keep
yourself:

1. **`config/config.php`.** It holds the encryption key. The provider
   passwords inside a backup cannot be decrypted without it. Download a copy
   and keep it somewhere separate from the backups.
2. **`storage/uploads/`.** Attachments live there. Back it up with File
   Manager if you use them.

### Restoring

1. In cPanel, open **phpMyAdmin** and select the Castle CRM database.
2. Click **Import**, choose the backup file, and click **Go**. A `.sql.gz`
   file imports as-is; do not unzip it first.
3. Put `config/config.php` back if you are restoring onto a fresh install.

The restore instructions are also written into the top of every backup file,
so you have them even without this document.

### From the command line

If your database outgrows what a web request can dump, cPanel's **Terminal**
gives you:

```
php ~/public_html/tools/backup.php            # take one, no time limit
php ~/public_html/tools/backup.php --list     # what is on disk
php ~/public_html/tools/backup.php --export=contacts > contacts.csv
```

---

## Bringing your guests across

**Settings → Import a file**, or `/imports`. Needs the same permission as
backups, because importing is the mirror image of exporting.

Export your old guest list as CSV, with the column names in the first row.
At minimum you need **an email address or a phone number** per person — that
is what identifies them. A name is not enough: two different families called
Smith are not one guest, and an import cannot undo merging them.

The flow is four steps, and the third is the one that matters:

1. **Upload.** The file is checked for being a CSV at all and stored outside
   the web root. Nothing is read beyond the heading row yet.
2. **Map the columns.** Castle CRM guesses from your column names — `E-Mail
   Address`, `Mobile`, `DOB` and `ZIP` all land in the right place — and you
   correct anything wrong. Columns it has no use for are ignored.
3. **Check the file.** It reads the whole thing against the real database and
   **writes nothing**, then reports exactly what it would add, who is already
   here, and which rows it cannot read — by row number, so you can find them
   in your spreadsheet. The refusals download as a CSV you can fix and
   re-upload. **The import button does not appear until this has run**, and
   the server refuses a commit without it.
4. **Import.** A big file carries on in the background, a batch a minute, so
   you can close the tab.

Three things worth knowing before you start:

- **Imported guests are consented to nothing**, whatever your file says.
  See §2 of `docs/PRIVACY.md` for why, and for the one box that changes it.
- **Dates that could be read two ways are refused, not guessed.**
  `03/04/2024` is two different dates; a birthday wrong by a month sends the
  offer to the wrong week every year. Tell the mapping screen whether your
  dates are day-first or month-first and they read fine. `2024-03-04` and
  `25/03/2024` are never ambiguous.
- **Re-uploading the same file is safe.** People are matched on email, then
  phone; a second run reports them as already here and changes nothing.

### Visit history

A second file, uploaded the same way, with one row per guest per day they
came: an email, phone number or your old card number to find them by, the
date, and what they spent.

**Import your guests first.** A visit file matches people who are already
here; it never creates them, because a POS export would otherwise become tens
of thousands of nameless records whose only field is a card number.

One guest on one day is **one visit**, however many transactions the file
holds for them — so a till export with a row per purchase is read correctly,
and lifetime value is derived from the visits rather than added up, which is
what makes a re-run harmless.

If your host's `upload_max_filesize` is small (2MB is common, and the page
tells you yours), split the file and import the parts. The limit is PHP's,
not Castle CRM's.

---

## Recovering a lost password

Three routes, in order of preference.

**1. The reset email.** If mail is configured, use **Forgot password** on the
sign-in page.

**2. The log.** If the mail driver is `log`, or sending failed, the whole
message — reset link included — is in `storage/logs/castle-YYYY-MM-DD.log`.
Open it in File Manager, find the most recent `MAIL` or
`Password reset email could not be sent` entry, and copy the link into your
browser. Links last one hour.

**3. The command line.** In cPanel's **Terminal**:

```
php ~/public_html/tools/reset-password.php                        # list accounts
php ~/public_html/tools/reset-password.php you@example.com --generate
```

That prints a new password once and signs out every other session for the
account. It refuses to run over the web — filesystem access is the
authorisation.

---

## Upgrading

1. Take a backup first (**Settings → Backups → Back up now**) and download it.
2. Upload and extract the new version over the old one, overwriting.
   `config/config.php` and `storage/` are not in the archive, so your
   configuration and data survive.
3. Visit any page. Pending database migrations run automatically and are
   recorded, so a half-finished upgrade can be completed rather than redone.
4. Check **Settings → System health**.

---

## Troubleshooting

**A blank white page.** PHP hit a fatal error with error display off. Look at
`storage/logs/` for the last entry. Nine times in ten it is a PHP version or
a missing extension — see step 2.

**"Castle CRM needs PHP 8.1 or newer."** Step 2. The message names the version
you are actually running.

**500 error on every page, immediately after upload.** Usually `.htaccess`
versus a host that disallows a directive in it. Rename `.htaccess` to
`.htaccess.off` and reload: if the site comes up, tell your host which
directive they object to. Castle CRM works without the rewrite rules, using
`?_route=` URLs, but tidy URLs are worth a support ticket.

**The installer redirects in a loop.** Delete `install/.lock` and
`config/config.php`, then start again. This happens if an install was
interrupted between writing the config and finishing the seeds.

**"storage is not writable."** Step 1, Permissions.

**Cron never turns green on the health page.** Check the path to PHP in your
cron line. cPanel's **Cron Jobs** page shows the correct one; `/usr/bin/php`
and `/usr/local/bin/php` are both common. Also check that the cron line points
at the real path of `cron.php` — the installer prints the one it detected.

**Email sends but never arrives.** Spam folder first. Then step 6's DNS work.
Then check the venue's **From** address really is at a domain you control.

**Everything is slow.** Shared hosting, usually. Check
**Settings → System health** for the database and cron rows, and check your
host's resource-usage page for throttling — a plan that has hit its CPU or
I/O limit is throttled rather than stopped, which reads as "slow" and not as
an error anywhere.

---

## Where things live

| Path | What it is | Safe to delete? |
|---|---|---|
| `config/config.php` | database credentials, encryption key | **No. Never.** |
| `storage/logs/` | application log, one file per day | Yes, oldest first |
| `storage/backups/` | database backups | Pruned automatically |
| `storage/uploads/` | attachments | **No** |
| `storage/cache/` | cache and the cron lock | Yes, it rebuilds |
| `storage/sessions/` | unused; sessions are in the database | Yes |
| `install/` | the web installer | **Yes, once installed** |
| `tools/` | command-line recovery and backup | Keep. Not web-reachable. |
| `docs/` | this and the specification | Yes, if you like |
