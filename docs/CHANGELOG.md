# Changelog

Castle CRM, for The Castle Fun Center, Chester NY.

Versions follow the phase plan in `docs/SPEC.md` §13. The format is loosely
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the headings favour
what changed for the venue over what changed in the code.

---

## [1.0.0-phase1] — unreleased

Phase 1: everything needed to install Castle CRM on a cPanel host, sign in,
configure the venue, and send a message without being able to reach a guest
by accident.

### The foundation

- **Web installer**, eight steps, no command line. Checks PHP version and
  extensions, tests the database connection before accepting it, writes
  `config/config.php` last so a failed seed leaves a retryable install rather
  than a half-configured one, and locks itself when finished — while still
  showing the final page, which carries the cron line and the DNS records
  nobody can get again afterwards.
- **99 tables, 121 foreign keys.** The whole data model, all phases, applied
  up front: a venue should never be told to run a migration. Money is integer
  cents throughout, and no column anywhere could hold card data.
- **Zero dependencies.** No Composer, no npm, no build step. Vanilla PHP 8.1+,
  MySQL 5.7.8+/MariaDB 10.3+, hand-written CSS and JavaScript. Upload and go.
- **One cron line** is the only server configuration. Each run works to a
  wall-clock budget, holds a lock so two never overlap, and reports a
  heartbeat the health page watches — because silent cron failure is the most
  damaging failure this application has.

### Guardrails

- **Safe Mode, on by default.** Every outbound message is redirected to the
  owner with the intended recipient noted at the top. A finished install
  cannot contact a guest at all. Leaving it needs its own permission and the
  venue's name typed to confirm, and is audited as the decision it is.
- **A separate global sending pause**, which queues rather than fails.
- **Consent and suppression enforced in the send path**, not in the user
  interface, so no controller, campaign or script can opt out of them.
  Transactional mail bypasses marketing consent — the guest asked for it — but
  never bypasses suppression.
- **Quiet hours** in venue-local time and **a weekly cap per guest** across
  every campaign and automation at once.
- **Consent modelled per channel** with its evidence: the source, the IP, and
  the disclosure wording as displayed. `consent_log` is append-only.

### Roles and auditing

- **RBAC as data**: 53 permissions across 8 roles, with per-permission scope
  (all / team / own). Fails closed — an unresolved scope compiles to a query
  returning nothing, never everything.
- **Audit log** of every change with before and after values, retained for two
  years by default and configurable.

### The venue's own shape

- Seeded from The Castle Fun Center's public information: 16 attractions with
  seasons, heights and capacities; 4 party packages; 14 add-ons; 33 resources;
  operating hours; 4 pipelines with response deadlines; 11 loss reasons; 8 SMS
  keywords. Prices are seeded as **editable defaults** that the dashboard asks
  an operator to confirm, because they came from a website and may be stale.
- **8 revenue playbooks with 111 automations**, every one of them inactive.
  It is a library to review, not a machine that starts running.
- **12 email templates**, including the password-reset message the application
  sends itself.

### Email

- **`Mailer`** with three drivers: `log` (the default — writes the whole
  message where an administrator can retrieve it, which is what keeps a
  password reset recoverable on a host with no provider), PHP `mail()`, and
  **SMTP over a hand-written socket client** with implicit TLS, STARTTLS,
  AUTH LOGIN/PLAIN, dot-stuffing and credential redaction in the transcript.
  No PHPMailer, because there is no Composer step.
- **Bulk mail always carries** the venue's postal address and a working
  unsubscribe route, appended in the send path so a template cannot omit them.
- **One-click unsubscribe (RFC 8058)**, which Gmail and Yahoo now require of
  bulk senders. Needs no login, works on the first click, is idempotent, and
  writes the withdrawal to `consent_log` with the IP and method.
- **Settings → Email** for provider configuration, under its own permission
  because it is the one settings page that holds a credential. The stored
  password is never rendered back into the form, and a blank field means keep.
- The DNS records that decide whether a venue's mail reaches an inbox, printed
  with the cPanel shortcut that writes them for you.

### Backups and export

- **Nightly database backup** on the existing cron line, keeping the newest
  seven. Written in pure PHP — `mysqldump` is absent or disabled on most
  shared hosts, and passing a password to a child process exposes it in the
  process list.
- Rows are read in keyset-paginated chunks and written as they arrive, so a
  large table does not need a large memory limit; the file is written as
  `.part` and renamed only on success, because a partial dump somebody
  restores from in an emergency is worse than no dump at all.
- **Restore instructions written into the top of every backup**, along with
  the warning that `config/config.php` is not in it and the credentials
  inside cannot be decrypted without that file.
- **CSV export** of any table on an allowlist, with a UTF-8 BOM so Excel does
  not mangle accented names and formula-injection neutralised so a guest's
  "name" cannot execute on a staff machine. Every export and every backup
  download is audited with the actor and the row count.
- **`tools/backup.php`** for a database too large to dump inside a web
  request, and **`tools/reset-password.php`** for the day nobody can sign in
  and no mail provider is configured. Both refuse to run over HTTP:
  filesystem access is the authorisation.

### Security

- Argon2id passwords (bcrypt fallback), AES-256-GCM for stored credentials,
  database-backed sessions with idle and absolute timeouts, CSRF by
  middleware, a strict Content-Security-Policy with no inline script, rate
  limits on sign-in, reset and unsubscribe.
- **Optional, settings-driven force-HTTPS.** Off at install on purpose: a new
  cPanel domain is plain HTTP until AutoSSL issues, and a redirect loop on
  first visit would lock an operator out of their own installer. The settings
  page warns when you are reading it over plain HTTP and will not let you make
  that mistake quietly. GET and HEAD are redirected; other verbs are refused
  rather than bounced, because redirecting a POST silently drops the body and
  looks like data loss.
- **Automatic schema upgrade** on the first request after new files are
  uploaded, serialised with a lock, with the version marker advanced only when
  every pending file applied — so a half-finished upgrade retries rather than
  claiming success.

### Accessibility

- **Contrast measured, not asserted.** The suite computes WCAG ratios from the
  palette's own token values for both themes. It found three real failures the
  first time it ran, all now fixed: `--faint` was 3.0:1 against white and is
  used for real text; form-field borders were 1.5:1 where 1.4.11 asks for 3:1;
  and white button labels on the dark theme's pastel accents were 3.3:1.
- Every form control has a programmatic name, every table header declares a
  scope, no inline event handlers, no positive tabindex, no destructive action
  behind a GET link. Checked across every view by the suite, with the detector
  itself tested against fixtures — a static gate that cannot fail reads as
  coverage without being any.
- Body text is sized in `rem`, so a reader who has raised their browser's
  default font size gets larger text here too.

### Tests and CI

- **412 tests, no test framework** — the same no-dependency constraint as the
  application, so CI needs nothing but PHP.
- Four layers: unit, static analysis over the source itself, integration
  against a real MySQL, and end-to-end over real HTTP against the real front
  controller. Plus a scripted walk through all eight installer steps.
- The static layer enforces properties rather than examples: no SQL
  interpolates a variable, every mutating route is CSRF-protected, every
  `can:` names a catalogued permission, every route handler exists, every view
  a controller renders exists, no schema column could hold card data, money
  columns are never floats.
- **CI fails a green run that tested nothing** — `--require-db`,
  `--require-http` and `--min-tests` — because the failure mode of a test job
  on a shared runner is a silently skipped suite, not a red cross.
- Matrix: PHP 8.1, 8.2, 8.3 × MySQL 8.0, MySQL 5.7 and MariaDB 10.11.

### Found by an adversarial review of this release

After the work was done, the diff was reviewed across eight dimensions with
every finding put to three independent skeptics (already-handled /
reachable / does-the-consequence-follow). 74 findings were raised, 37 went to
verification, **15 survived** — and most were in code written for this release.
Fixed here:

- **The CSV export promised formula-injection escaping it did not do.** The
  guard lived in `Response::csv()` (the audit export); `Backup::exportCsv()` —
  the one the Backups page offers, and the one that carries the whole contacts
  table — wrote raw values, under a UI hint stating the opposite. A guest who
  types `=HYPERLINK(...)` as their first name gets it executed on a staff
  machine. Three of the eight reviewers found this independently; the existing
  test asserted the guarantee against the function no export route uses.
- **Password-reset links were built from the unvalidated `Host` header.**
  `app_url` was written as null by the installer, config.sample.php *and* the
  test runner, so the fallback was the normal path: a crafted
  `POST /forgot-password` with `Host: attacker.test` mailed the owner a genuine
  reset token pointing at the attacker's domain. `absoluteUrl()` now prefers
  `app_url`, then the configured venue website, then the URL the installer was
  reached on, and logs a warning if it ever falls back to the request. The
  installer records that URL, so a fresh install never reaches the fallback.
- **A failed `CREATE TABLE` was recorded as applied, and the version marker
  advanced over it.** `Migration` tolerated MySQL 1005, which is both
  "duplicate constraint name" *and* "this foreign key is malformed". Measured:
  a release whose new table declared `INT UNSIGNED` against a `BIGINT
  UNSIGNED` id failed, the table was never created, and the upgrade reported
  success — the one failure the marker exists to prevent. 1005 is now accepted
  only with errno 121, and 1022 was dropped outright.
- **Foreign-key constraint names carried no table prefix**, so the second of
  two prefixed installs in one database — which the installer's prefix field
  explicitly invites — built a partial schema without raising anything. All 121
  names are now prefixed; verified by applying the schema twice into one
  database, giving 99 tables and 121 foreign keys on each side.
- **Subject lines were HTML-escaped.** `sendTemplate()` merged the subject with
  the body's escaper, so a venue called "Dave & Buster's" mailed
  "Reset your Dave &amp; Buster&#039;s password" and stored that in the message
  trail. Apostrophes in a child's name are the normal case here. Subjects now
  go through `mergeText()`.
- **Two concurrent backups shared one `.part` filename** and interleaved into a
  corrupt file that listed as a good backup — discoverable only during a
  restore. Now serialised with a lock, with a per-process temp name, and the
  published name chosen at rename time so two backups in the same second both
  survive.
- **`tools/backup.php --keep` with no value pruned to one backup**, destroying
  thirteen of fourteen nightlies and reporting success. Flags that need a value
  now say so.
- **A streamed export that failed midway** was sent as 200 with an HTML error
  page appended to a truncated CSV, and was never audited. The audit row is now
  written before the first byte, and a failure appends an unmistakable marker.
- **The demo seed could abort the whole installer.** Its guard keyed only on
  contacts, but bookings do not cascade from them, so re-seeding after the
  documented cleanup hit `booking_number`'s unique key inside the installer's
  transaction. It also left five fake urgent tasks on the owner's dashboard,
  because tasks were never tagged despite the seed's own contract promising
  every row was.
- **A rejected email-settings form silently restored cleared values**, because
  repopulation used `?:` rather than `old()`'s default — so clearing the Safe
  Mode address and mistyping the From address in one submit put the old address
  back, looking saved.

The suite grew from 372 to **412 tests** to cover them. Each fix was
mutation-checked: the password-reset suite, for instance, fails on three
separate assertions when the token lookup is replaced with `LIMIT 1` — the
mutation that previously left all 372 tests green.

Four of those new tests were written into `tests/unit`, which by contract runs
with no database at all — so they did not fail, they *errored*, and because the
CI matrix and the installer walk-through both `needs: lint`, they took the whole
pipeline down on the first push. They now live in `integration/mailer`, and a
static gate asserts that no test under `tests/unit` names `DB::`, `Settings::`
or any other service that opens a connection. That gate is one of the tests
counted above: the mistake was mine, and the layer contract was only written in
prose until it caught nobody.

Then the matrix — which had been skipped on both previous pushes, because it
`needs: lint` — ran for the first time and failed on all five configurations
while every local run stayed green. **Two of the new password-reset tests were
defeated by the application's own rate limiter.** `/forgot-password` allows five
POSTs per quarter of an hour, the suite makes ten, and it cleared the counter
between them with `Cache::flush()`. That call clears whichever backend the
*calling* process resolves — and the counter belongs to the *server* process.
The two agree only on the file backend; CI's PHP had APCu, which is per-process
shared memory, so the flush cleared a segment nobody else could see and reported
success. Calls six onwards were answered 429, no token was ever minted, and the
two tests that need a real minted token failed. A developer's machine without
APCu passed every time.

Clearing that counter from the test process was the obvious fix and it was the
wrong one — twice. `Cache::flush()` was replaced with a direct unlink of
`storage/cache/*.cache`, the server was pinned to the file backend, and CI came
back red with the identical failure: nothing running in the test process is
guaranteed to reach a counter the *server* process owns. The working answer
removes the dependency instead. **Each `TestBrowser` presents its own client
address**, via `X-Forwarded-For` and a `trust_proxy` that is true only in the
throwaway config the runner writes; a browser keeps one address for its life, so
a suite avoids the limiter by taking a fresh browser and a test trips it
deliberately by reusing one.

Four things came out of it, and the environment-dependent bug was the smallest:

- **The rate limiter had no test at all**, while two comments in the suite said
  it was "exercised by the test that deliberately trips it". `http/smoke` now
  covers it for real: that it refuses, that it sends `Retry-After`, and that it
  is per-address rather than global — the property every other HTTP suite
  depends on.
- The failure said only **"Expected a value, got null"** — one sentence for a
  path containing eight mail-refusal reasons, a rate limiter and a redirect. It
  now reports the HTTP status and whether a `password_resets` row exists, which
  separates "never minted" from "minted but not mailed". That diagnostic named
  the cause on its first run.
- CI's failure step tailed 120 log lines, which ended *before* the http suites
  had run, so the log explaining it was truncated away. It prints the whole
  application log and every server request line with its status code now — a
  429 was invisible in CI output, because the limiter logs at WARNING while the
  test server runs at `log_level=error`.
- The test server is started with `apc.enable_cli=0` and an explicit
  `storage_path`, so its cache backend cannot quietly differ between a
  developer's machine and CI.

The pattern worth keeping: **a test that reaches past the application to reset
its state is only as reliable as its assumption about where that state lives.**

### What an adversarial review of the tests then found

Once CI was green, the diff's own tests were reviewed across four dimensions —
isolation, cross-engine behaviour, gate soundness and mutation testing — with
every finding put to three independent skeptics. Nine survived, and the pattern
is uncomfortable: **the tests that lied were the ones written to prove the
security fixes.**

- **The only end-to-end test of the reset-link-poisoning fix asserted nothing.**
  It minted a CSRF token with one browser and sent the attack on a separate
  curl handle carrying `Host: attacker.test`. libcurl matches jar cookies
  against the Host header, so that request carried no session cookie at all,
  the CSRF middleware answered 419, and no mail was ever built — and the test
  treated the empty result as a pass ("may be refused before any mail is built,
  which is also a safe outcome"). Its two real assertions were dead code on
  every run of every configuration. MEASURED: replacing the link construction
  in `AuthController` with `'http://' . $request->host() . …` — exactly the bug
  this release fixed — left all 409 tests green. The session cookie is now
  forced through so the request reaches the controller, a trusted source is
  configured as a real install has one, and an empty result is a FAILURE.
- **The one line in `hostOf()` that makes a real stored URL usable had no
  coverage.** Every test fed a bare `scheme://host`, but the installer stores
  `system.install_url` with the subdirectory the app lives in, and operators
  type a trailing slash. Without the path strip both configured sources fail
  the host regex, are skipped, and `baseUrl()` falls back to the request Host —
  reopening the poisoning on a URL carrying a live token. Deleting that line
  left all 54 mailer tests green.
- **The new static gate had a false negative in its own regex.** Its lookbehind
  included a backslash, so `App\Core\DB::value()` — the style
  `tests/unit/RouterTest.php` already uses — passed. The gate reported 27/27 on
  a file whose every line was a database call while CI's lint job on the same
  tree exited 1. A gate that gives false assurance is worse than none. It now
  matches the qualified spellings and flags a bare `use` of a denied class.
- **A clock-dependent flake, reproduced.** The web-cron test asserted its
  output contained no `404`, but that output embeds the nightly backup's
  filename — a second-resolution timestamp. 502 of 86,400 seconds a day contain
  those digits, as does all of 2026-04-04. It now looks for a 404 *page*.
- **`glob('*.part')` could no longer match anything.** The temp name gained a
  pid and random suffix in this release; the deadline test's "leaves no file
  behind" assertion was unfalsifiable, and a left-behind partial dump was
  caught only by a later test under the wrong name.
- **The migration retry contract was never exercised.** `CREATE TABLE IF NOT
  EXISTS` raises no error, so the "already exists" tolerance was never entered
  by any test — while this release narrowed it. The statements that actually
  depend on it are the nine non-idempotent `ALTER TABLE … ADD CONSTRAINT`. A
  test now re-runs one, which also puts the open MySQL 5.7 question (1022,
  dropped here) in front of CI rather than leaving it to an assumption.
- **The upgrade suite left the version marker naming a release that does not
  exist**, so the http layer re-entered the production upgrade path mid-run.
- Plus a sharp edge that cost a real debugging round: the tools suite now says
  when `config/config.php` names a different database from the one under test,
  instead of failing six tests in a way that reads as broken authentication.

Every fix above is mutation-checked: the mutation that the old test tolerated
now fails it.

One confirmed finding was deliberately **not** fixed here: `users_manage`
allows a non-owner holding it to set the Owner's password, which defeats the
`backup_manage` separation this release's own PRIVACY.md describes. It is real,
but it lives in Phase 1's user-management code that this release does not
touch, and changing the authorisation model deserves its own review rather than
being folded into a release about email and backups.

### Fixed during development

Found by the tests, in the code they were written against:

- **Every constrained route was dead.** `preg_quote` escapes `:` and `-`, so
  a pattern like `/playbooks/{slug:[a-z0-9-]+}` compiled to a regex that
  matched nothing.
- **Every valid New York ZIP code was rejected.** `max:20` compared `"10918"`
  as a number rather than a length.
- **A filename in a route parameter lost its path.** PHP's built-in server —
  and some nginx configurations — report `SCRIPT_NAME` as the whole request
  path when a segment contains a dot, and `dirname()` of that ate the route,
  so a backup download arrived at the router as `/download`.
- **A `//` path dropped its first segment.** `parse_url('//config/config.php')`
  reports `config` as the *host*, so the front controller's
  protected-directory guard was inspecting a path from which the segment it
  looks for had already been removed.
- **Every plain-text email lost its links.** The HTML-to-text fallback
  rendered a link as `label <url>`, and `strip_tags` then deleted the URL as
  though it were a tag.
- **Header injection survived into the record.** A CR/LF in a subject was
  stripped by each driver but not before the message was recorded, so an
  attempt sat in the database looking as though it had worked.
- **An empty message body reported the wrong reason**, because the body check
  sat behind the Safe Mode branch and sent whoever was debugging it somewhere
  else entirely.
- **The installer replaced its own final page** with the lockout notice, so an
  operator never saw the cron line or the DNS records.
- **Web cron 404ed first and worked second**: it fell through to the router,
  dispatched `/cron.php` as a route, sent that response, and only then did the
  scheduled work.
- **The installer redirected itself** to `/install/install/`.
- Empty plaintext failed to round-trip through encryption (an off-by-one on
  the IV length check); `clean_html` left script *content* as visible text;
  `HealthController::json()` collided with the base controller's helper and
  fatalled; the test runner's rate-limit cache survived between runs, so two
  runs inside five minutes failed on 429s that looked like broken
  authentication.

---

## Unreleased — next

Phase 2 is contacts, households and children: the module every other one
reads from. See `docs/SPEC.md` §13 for the full plan, and §14 for the fifteen
questions that need the venue's answers before the work that depends on them
can be finished — the CenterEdge reporting credentials and the real party
prices being the two that block the most.
