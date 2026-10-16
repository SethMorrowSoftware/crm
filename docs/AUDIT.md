# Castle CRM — what was checked, how, and what it found

This is the phase 8 hardening pass: a security review, a measured
accessibility audit, a load test at venue scale, and a backup-and-restore
drill. It records the METHOD as well as the result, because a result without
its method cannot be re-run — and this document is worth nothing to whoever
reads it next unless they can.

Everything here was measured against the application as it actually serves:
in a real browser, over HTTP, against a real database. Where a figure appears
it was observed, not estimated.

Measured **16 October 2026** against `1.0.0-phase8`, on PHP 8.3.6 with
MariaDB 10.11.

---

## 1. Security review

### What was found, and fixed

**Two real exposures, both in what a request could reach.**

**`tests/` and `tools/` were web-executable.** The `.htaccess` denies `app`,
`config`, `storage` and the installer's internals by path, and the rewrite
rules deliberately let a REAL FILE through untouched — which they must, for
the stylesheet and the installer. `tests` and `tools` were not in that deny
list, so every `.php` under them was directly executable on an Apache install.

Measured: `GET /tests/run.php` returned **500**, which means it ran. That file
is the test runner. It boots against the database named in
`config/config.php` and, given `--fresh`, calls `test_drop_all()`.
`tests/install/walk-installer.php` deletes `config/config.php` and drives the
installer from nothing.

Fixed three ways, because one is not enough:

- `.htaccess` now denies `tests` and `tools` by path.
- Every script in them refuses a non-CLI SAPI, so a host that never reads
  `.htaccess` — nginx in front, a vhost with `AllowOverride None` — is still
  covered.
- A static gate (`every directly reachable PHP file is guarded or meant to be
  public`) fails the build if a new script appears in either directory without
  a guard. It recognises a test case by its `Harness::suite()` call and a
  library by having no top-level executable code, so only genuine entry points
  are required to carry one.

A note worth keeping for whoever adds the next guard: `tools/dev-server.php`
is the ROUTER SCRIPT for `php -S`, re-executed on every request the
development server handles, and under the built-in server `PHP_SAPI` is
`cli-server`, not `cli`. A plain `!== 'cli'` guard there 404s the entire
application the moment it is added. It did.

**`/.git/config` was readable.** `<FilesMatch "^\.">` tests the file's own
NAME, so it denies `/.env` and lets through a file called `config` that
happens to live in a directory called `.git`. Measured: **200**. A venue whose
install was deployed by cloning rather than unzipping was publishing its
entire repository history.

Fixed with `RedirectMatch 404 (?i)(^|/)\.(?!well-known/)`. The `.well-known`
exemption is load-bearing: cPanel's AutoSSL answers its ACME challenge there,
and denying it stops certificate renewal — an outage that arrives ninety days
after somebody tightens that file, by which time nobody connects the two.

### What was checked and held

Fourteen checks, in a browser, signed in, against data chosen to break out of
wherever it lands (`"><img src=x onerror=…>` and `' OR 1=1 -- `).

| Checked | Result |
|---|---|
| CSP forbids inline script | `script-src 'self'`, no `unsafe-inline`, no `unsafe-eval` |
| `object-src`, `base-uri`, `frame-ancestors` | all set |
| `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` | all present and correct |
| Session cookie | `HttpOnly`, `SameSite=Lax` (`Secure` is set by config under HTTPS) |
| A guest whose name is a payload | escaped on all 8 pages that draw a name; nothing executed, nothing became an element |
| The CSV export of that guest | no cell begins `=`, `+`, `-` or `@` |
| `/api/v1` with a session cookie and no token | **401** — a session is not an API credential |
| Uploaded import files, by path and by traversal | not readable over HTTP |
| Error pages (missing record, bad route, injected search) | no path, no `SQLSTATE`, no stack trace, no database name |
| A `' OR 1=1 -- ` search | a literal search returning nothing, not a query |

`style-src` does allow `'unsafe-inline'`. That is deliberate and is a far
smaller surface than script: it exists because a progress bar's width and a
chart's bar heights are per-row values, and the alternative is a nonce on
every inline style attribute. No user data reaches a `style` attribute.

### Standing properties, enforced by the suite rather than by review

These were already in place and are listed because an audit that only names
what it changed implies nothing else was looked at:

- **No SQL statement interpolates a variable** — a static gate over every PHP
  file in the project. Dynamic identifiers go through `quoteIdentifier()`
  after an allow-list check.
- **Every mutating route is CSRF-protected**, bar one documented exemption.
- **Every `can:` names a permission that exists**, so a typo fails the build
  rather than silently granting access.
- **Every authenticated route requires authentication**, and an API route
  accepts a token or a session, never both.
- **A record outside your scope is a 404, not a 403** — a 403 confirms the
  record exists, which is how an id space gets walked.
- **An API token's abilities only narrow**, and are checked before the owner
  bypass.
- **A webhook target is refused at save time** if it is a literal internal
  address OR a hostname that resolves to one, re-checked at delivery with the
  approved address pinned to the connection.
- **Imported contacts are consented to nothing** unless the operator affirms
  they hold the records, and that affirmation is audited with their name.

---

## 2. Accessibility audit

### Method

A real browser, **124 page loads**: 31 pages × 2 colour schemes × 2 viewport
widths (1400px and 390px). For each load:

- every form control, button and link must have an accessible name, computed
  the way a screen reader computes one (`aria-label`, `aria-labelledby`, a
  `for=` label, a wrapping label, `title`, then text content);
- exactly one `h1`, and no heading level skipped;
- every `img` has `alt` or is hidden; every `svg` is hidden or labelled,
  checked on the **subtree** because `aria-hidden` is inherited;
- every table has a caption or a label, and every header cell a `scope`;
- no positive `tabindex`, no interactive element nested inside another;
- focus must change something visible — outline, box-shadow or border — on
  every control, tested by focusing each one and comparing computed styles;
- no tap target under 24px tall at phone width;
- **contrast computed after blending every translucent layer**, against 4.5:1
  for normal text and 3:1 for large or bold.

**Contrast over a gradient is measured on PIXELS, not computed.** The page
background is a gradient, so a translucent tint over it has no computed colour
to check — and that is exactly where a nearly-invisible label hides. Those
runs (18 of them) are screenshotted per element and the real contrast taken
from the lightest and darkest pixels in the box.

### What was found, and fixed

**26 tables had no caption.** A screen reader announces such a table as
"table, 7 columns, 42 rows" and then starts reading cells, so somebody arrives
in the middle of a grid of names and dates with nothing having said what the
grid is. Eleven were found by the browser audit; the other fifteen by the
static gate written afterwards, which reaches detail pages the walk had no
record to open.

Every one now carries a `<caption class="sr-only">` naming what it lists, and
a gate (`every table a view draws says what it is`) fails the build on a new
one. HTML emails are exempt and the gate says why: a table in an email is
layout, and `sr-only` does not exist there to hide a caption with.

**The logo `svg` is now `aria-hidden` on the element** as well as on its
wrapper. The wrapper was already hidden and the subtree with it, so this
changed nothing for a user — it is consistency with the icon set, which hides
each icon itself.

### Result

**Zero findings** across all 124 loads: no contrast failure in either theme,
no unnamed control, no unlabelled table, no skipped heading, no invisible
focus, no tap target under 24px, no positive tabindex.

Two false positives in the audit itself are recorded because they are easy to
reintroduce:

- `innerText` is `''` for anything not rendered, so a perfectly labelled field
  inside a collapsed `<details>` read as unlabelled — 400 phantom findings on
  the first run. An accessible name is computed from `textContent`.
- `aria-hidden` applies to the whole subtree, so checking an element's own
  attribute reported the logo on every page.

---

## 3. Load test at venue scale

### Method

`tools/loadtest.php --seed --contacts=50000` builds a realistic shape rather
than a uniform one, because a uniform dataset hides exactly the queries that
hurt — every index looks equally good on it. A fifth of contacts have no email
(a phone number from a party booking), a tenth no phone; creation dates spread
over two decades; a third consented; visits are a long tail (most guests once,
a few twenty times) clustered into summer and weekends.

It refuses to run against a database holding more than 50 contacts it did not
create, so a mistyped `config/config.php` cannot bury a venue's real data.

**50,000 contacts and 106,123 visits, built in 17.5 seconds, 33MB on disk.**

### Queries

`--measure` times 32 queries — the list pages, the dashboard panels, all 23
reports over a 365-day window, and two segment audiences.

Everything under **56ms**, nothing over 2MB of memory. The slowest were
`visit_frequency` (55.8ms), `new_vs_returning` (50.2ms) and `list_growth`
(37.0ms) — the three that aggregate the whole visit history.

### Pages

Queries are not pages. A page runs a dozen queries, holds every row it draws
in memory, and on shared hosting dies at the 128M limit with a blank 500 and
nothing in the response body. So every page was driven in a browser against
the same database.

**31 pages and 9 reports, all HTTP 200, slowest 190ms.** No page over 1s, no
response over 800KB, no page drawing more than 329 rows. The five slowest:

| | |
|---|---|
| contacts, search by phone | 190ms |
| report: lapsed_guests | 167ms |
| contacts, last page (offset 49,950) | 148ms |
| contacts, search by email | 148ms |
| contacts, search by name | 135ms |

Deep pagination was checked deliberately (`?page=1000`) because `LIMIT …
OFFSET 49950` is where a list page usually falls over. It did not.

### The exports, at a real 128M limit

The most memory-exposed operation in the application is the one that touches
every row of the biggest table. Measured with `-d memory_limit=128M`:

| | rows | time | peak memory |
|---|---|---|---|
| CSV export, contacts | 50,000 | 566ms | **6.0MB** |
| CSV export, visits | 106,123 | 478ms | **6.0MB** |
| Full database backup | 156,529 | 1.96s | **8.0MB** |

Flat memory across a 100,000-row export is the proof that it streams rather
than assembling.

---

## 4. Backup and restore drill

Not a verification that a file was written — a real one.

1. Fingerprinted the database: row counts and the sum of
   `lifetime_value_cents` and `spend_cents`.
2. Took a backup through the application: **3.4MB gzipped, 101 tables,
   156,529 rows, 1.96s**.
3. **`DROP DATABASE`**, then recreated it empty. Confirmed 0 tables.
4. Restored from the gzipped backup alone: **6 seconds**.
5. Compared the fingerprint: **identical** — 50,000 contacts, 106,123 visits,
   and both money sums to the penny.
6. Signed in and used the application: the dashboard, the contacts list (25
   rows) and the `top_guests` report (100 rows) all drew correctly.

Step 6 is the one that matters. A restore that produces a database the
application cannot use is not a restore, and a drill that stops at step 5
would not have noticed.

Two things are **not** in that file and are documented in the backup's own
header as well as in `docs/INSTALL.md`: `config/config.php`, which holds the
encryption key without which the stored provider credentials cannot be
decrypted, and `storage/uploads/`.

---

## 5. The update path

An upgrade on shared hosting is "extract the new zip over the old files".
Nothing runs afterwards — no deploy hook, no SSH for most operators, no
migration command they will think to type. So the application applies pending
schema files itself on the next request, under a lock, advancing its version
marker only when every file applied.

**That path was broken, and the breakage is the most instructive finding in
this pass.** It was triggered by comparing the shipped `VERSION` against a
stored marker — and `VERSION` had sat at `1.0.0-phase1` for three phases. So
the check returned on its first line and three schema files were never applied
by any upgrade. Found by opening the webhooks page on a real install path:
HTTP 500, and `Unknown column 'd.status'` in the log. The reporting and
API-token pages were in the same state.

The whole point of that class is that an operator never has to run a
migration. Hanging it on a constant somebody has to remember to edit put a
human back in the one loop there was not supposed to be one in. It now also
triggers on a fingerprint of the schema directory's own filenames — one
`glob()` of a directory the OS has cached, next to a string comparison — so a
forgotten version bump costs nothing.

Both markers are written only on full success, and an integration test pins
that: a failed upgrade leaves both alone so the next request retries, rather
than recording work it did not do.

**There is no version check that phones home, deliberately.** A "check for
updates" that contacts a server would mean every install reporting its
existence, its version and its IP to somewhere, on a schedule, for a venue
that did not ask for that. The upgrade path is documented in
`docs/INSTALL.md`; the changelog says what changed.

---

## 6. How to re-run all of this

```
php tests/run.php                              # 1,726 tests
php tools/loadtest.php --seed --contacts=50000  # point config at a scratch DB first
php tools/loadtest.php --measure
php tools/loadtest.php --reset
```

The browser passes — the accessibility audit, the security walk, the load
walk and the per-module feature walks — are driven with Playwright against a
running development server. They are not in `tests/` because they need a
browser and CI has none; what they establish is pinned by static gates that
do run in CI, which is why those gates exist in the shapes they do.
