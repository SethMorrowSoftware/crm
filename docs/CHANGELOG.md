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

- **418 tests, no test framework** — the same no-dependency constraint as the
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

The suite grew from 372 to **418 tests** to cover them. Each fix was
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

### The test that was added for CI to answer, and the bug it found

One of those nine fixes deliberately put an open question to CI rather than
settling it by assumption: whether MySQL 5.7 reports a duplicate foreign-key
constraint name with a code this release still tolerates. It does not, and the
matrix said so within two minutes.

**MySQL 5.7.44 answers `1022 Can't write; duplicate key in table '#sql-…'`** —
the code this release had dropped. So on MySQL 5.7, a schema file that died
after any of the shipped schema's nine `ALTER TABLE … ADD CONSTRAINT`
statements had succeeded **could never be completed**: every retry threw,
`Upgrade::run()` reported failure, and the upgrade was wedged where before this
release it recovered. Fresh installs were unaffected, which is why nothing else
noticed. MySQL 5.7 is still common on shared hosting, so this mattered.

1022 is tolerated again, but ONLY for a statement that adds a constraint. On its
own it means a duplicate VALUE, so a data-migration `INSERT` hitting a real
conflict reports the same number — and swallowing that is exactly the failure
the narrowing existed to prevent. Both directions are pinned by test and
mutation-checked: tolerating 1022 broadly fails on the `INSERT` case, dropping
it fails on the `ADD CONSTRAINT` case.

The retry test now also asks the engine what it says and holds the classifier to
that answer, instead of to a hardcoded list — so an engine that reports
something new names the number itself rather than costing another round of
detective work. And the comment above `ALREADY_EXISTS` no longer claims
"1826 on MySQL 5.7+ and MariaDB", which was wrong about both: MEASURED, 5.7.44
gives 1022 and MariaDB 10.11 gives 1005 + errno 121.

**The one finding deferred from this release is now fixed.** A non-owner
holding `users_manage` could set the **Owner's** password and sign in as them.
The role system checked which role you may GRANT and never checked whose
account you may ACT ON, so leaving the Owner's role untouched bypassed it
entirely — and taking over the Owner inherits `backup_manage`, which is the
permission `docs/PRIVACY.md` describes as the line between looking up one guest
and downloading all of them.

`Acl::mayManageUser()` now decides it: you may edit, deactivate or delete only
an account whose role is a subset of your own permissions, and only an Owner
may act on the Owner — checked separately from the subset rule, because a
custom role carrying every permission in the catalogue would pass a subset test
and "holds every permission" is still not "is the Owner". The guard is applied
to the edit form, the update and the delete.

Proven rather than asserted: with the rule reverted, the end-to-end test
watches the Owner's password hash actually change under a general manager's
POST. Two details were caught while writing it, both of the kind this release
has been full of — the delete test initially passed with the fix reverted,
because the *last-owner* guard was refusing the delete rather than the new
rule, so it now seeds a second owner to get that guard out of the way; and the
suite checks that a general manager can **still** reset a front-desk password,
since a fix that broke ordinary support would be worse than the hole.

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

## [1.0.0-phase2] — unreleased

Phase 2: the guest record and the work that happens around it. Contacts,
households and children; a sales pipeline; quotes; web forms; and the task
list the first three generate.

### Contacts, households and children

- **Contacts** with search across name, email and phone — the phone matching
  on `phone_normalized`, so a number found in the POS as `8455550123` and one
  a parent typed as `(845) 555-0123` reach the same guest.
- **Households**, because this venue sells to families: a household gathers
  the guests who share an address, and the member list and member COUNT are
  scoped identically, so a page can never say "4 members" above a detail view
  showing one.
- **Children** with birth date, birth month (denormalised so the birthday
  campaign is an indexed lookup rather than a function over every row) and
  **allergies** — the most sensitive column in the application, and the one
  the scoping work below exists for.
- **Consent is not part of the contact form.** It has its own two endpoints,
  because recording it means stamping a time, naming a source and appending to
  an append-only log. Posting `email_consent=1` to the update endpoint does
  nothing, and a test holds that.
- Consent for a channel the guest cannot be reached on is refused outright:
  email consent needs an email address, SMS consent needs a number. A
  permission nobody can exercise still reads as a marketable guest in every
  later count.

### Pipelines, deals and quotes

- **A board and a table over the same deals**, both sharing one WHERE clause
  so a filter can never mean two things. Stage counts and value totals come
  from a grouped aggregate, never from the rows drawn on screen.
- **Stage history** per deal, with the time in each stage, plus response
  deadlines from the seeded pipelines.
- **Quotes in integer cents end to end** — line quantities to two decimal
  places, a per-line total, a subtotal, a discount and a tax figure, with no
  float anywhere near the arithmetic. Numbers are allocated `Q-YYYY-NNNN`
  under a unique key, and a collision retries with a fresh number rather than
  failing the create.
- **A quote freezes when it is sent.** The guest is holding a copy, so
  re-pricing one in place is refused; the way to revise is to put it back to
  draft, which is recorded. Sending is its own permission, separate from
  editing, and the only way out of `draft` — so a status form cannot step
  around the send gate.
- A printable copy, chrome removed by the stylesheet's own `@media print`
  block, so it is the same document on screen and on paper.

### Web forms

- **A form builder** whose output is a public page at `/f/{slug}` — the only
  view in the application rendered to somebody with no account, and the one
  place where the operator's own headings and hints are treated as untrusted
  input like anything else.
- **A submission is kept even when it cannot become a contact**, and a contact
  is NOT created from an enquiry with neither an email nor a phone: a row with
  no way to reach anybody is litter, not a lead, while the answers the guest
  typed are never lost.
- **An enquiry is not an opt-in.** Only a ticked consent box grants anything,
  an unticked box revokes nothing, and a tick never clears a `do_not_contact`
  block a member of staff set deliberately. The disclosure wording AS
  DISPLAYED goes into the evidence, because a tick with nothing behind it is
  not a defence.
- A honeypot no human is ever offered (hidden from assistive technology and
  off the tab order, rather than `type="hidden"`, which the naive form-fillers
  this catches skip), rate limits on the public route, and a spam submission
  stored unvalidated and told nothing — showing it the real errors teaches a
  bot how to pass.
- UTM parameters captured onto both the submission and the new contact, which
  is what makes source reporting possible later.

### Tasks

- Due dates in **venue-local time**, so "due today" means the venue's day and
  not the server's — the tab strip's own count included.
- **The person a task is assigned to can close it** without holding the write
  permission. Being handed a job is permission to finish it; it is not
  permission to close somebody else's, and a role that can see every task
  still cannot.

### Found by review of this phase

Six findings on the Phase 2 pull request, all confirmed by reproducing them
first and all fixed here. Three were the same mistake in three modules, which
is the part worth recording.

- **Scopes come apart, and the write paths were reading the wrong one.** A
  permission's scope lives per permission in `role_permissions`, and the `can:`
  middleware only proves a permission is held SOMEWHERE — the scope decides
  which rows. Every seeded role happens to hold the read and write permissions
  at the same scope, so nothing caught it: a venue building a supervisor who
  SEES the whole guest list and EDITS only their own guests had the edit form
  open, the update apply, notes append and consent become recordable on
  anybody's record. `ContactController::visible()` and
  `QuoteController::findQuote()` now take the permission whose scope should
  decide, and each caller passes the one that governs it — so a quote on
  somebody else's deal can no longer be edited, re-statused or **sent to the
  guest**.
- **A household page leaked another rep's child.** A household row carries no
  owner of its own — the scope lives on the contacts inside it, which is why
  the member list joins through `view_contacts` — and the children query was
  filtered by `household_id` alone. Any rep sharing a household with another
  rep's guest could read that child's name, birth date and allergy list from a
  page they are allowed to open. Children are now read through their guardian,
  and a child with no guardian at all is still shown (the column is nullable;
  an inner join would have hidden those from everyone, including the owner).
- **The same leak by another door**, found while fixing that one and not
  reported by the review: a contact's page listed every other member of its
  household with no scope at all, while the household page scoped the
  identical list.
- **Every search was a 500.** `DB::connect()` disables emulated prepares, and
  the native driver refuses a named parameter used more than once — it answers
  `HY093`. Both the deal search (three occurrences of `:q`) and the task search
  (two) did exactly that, so `?q=` was a server error with no message anywhere
  in the UI. No test covered it because **no test had ever searched with a
  term**; the list tests all loaded the page with no filter, so the clause was
  never built and never bound. Both are now one name per occurrence, and both
  searches are tested — by the deal's and task's own row LINK rather than its
  title, because the filter bar echoes the search term back into its own input
  and a title assertion passes against an empty list.
- **A web form could record consent for a channel with no address behind it.**
  A form may carry an "email me offers" box and no email field; the admin path
  refuses that with "Add an email address before recording email consent." and
  the public path did not. It now reads the contact's reachability inside the
  transaction, after the submission's own answers have been filled in — so a
  number given in the same submission still grants SMS consent, while a tick
  with nothing behind it records nothing and the enquiry is still kept.

The suite grew from 418 to **610 tests**. Each of the six fixes was
mutation-checked by restoring the pre-fix code verbatim and confirming the new
test fails: the two search bugs come back as HTTP 500, the two leaks come back
as a child's and a sibling's name on a page that should not carry them, and the
consent bug comes back as `email_consent = 1` on a contact with no email
address.

---

## [1.0.0-phase3] — unreleased

The diary. A booking record with a derived money ladder and a printable run
sheet; a pure availability engine with its own I/O half; a calendar in month,
week and day views with resource lanes and a read-only iCal feed; versioned
waivers a parent signs on their own phone; a check-in desk the front desk can
work without being able to re-price a party; and a public enquiry page the
venue switches on when it is ready.

### What the modules are

- **`AvailabilityEngine`** is pure — no database, no clock, no timezone. It
  answers one day or one time and returns either slots with the resources that
  could take them, or a refusal naming one of nine reasons. `BookingRepository`
  is the half that reads rows and converts timestamps, and **the conversion is
  where this gets quietly wrong**: `cc_bookings.event_date` and its times are
  the venue's wall clock, every `cc_booking_resources` DATETIME is UTC like
  everything else in the schema, and both DST offsets appear in the tests on
  purpose — a suite written only in July passes against code that hard-codes
  the summer offset.
- **`QuoteCalculator`** prices a package. Integer cents throughout, no float
  anywhere in a money path, and division is half-away-from-zero: `intdiv()`
  truncates, which under-charges the venue by a cent per rounded line.
- **The run sheet** is what staff carry: times, room, headcount, the allergy
  note, the add-ons, the tasks. It reaches a role with no `view_revenue`,
  because the person setting the room up needs the allergy and not the price.
- **Waivers are versioned and a published version is immutable.** A signature
  names its version, so publishing new wording tomorrow does not retroactively
  change what anybody agreed to, and exactly one version is ever live.
- **`/book`** turns the engine's refusals into alternative dates or a phone
  number, which is the whole design. It is **off until the venue turns it on**,
  an enquiry is taken at `inquiry` so the room is really held, and the exposure
  that creates is bounded by a per-date allowance of unanswered public
  enquiries plus a route throttle. Nothing is charged and no card details are
  collected anywhere in this application.

### Four defects found by driving it, not by testing it

Each of these was found in a real browser or against the seeded venue, after
the tests for that module were already green.

- **A bowling party was unbookable, on every date, and nothing said why.** The
  engine places a party in ONE resource with room for it, and `resourceTypeFor()`
  resolved the pool from the package's main attraction — so the seeded Bowling
  Birthday package, whose attraction owns twenty-four lanes of six, refused its
  own default of eight guests with "No room of that type holds 8 guests. The
  largest holds 6", with no alternative to offer. A ten-guest bowling party is
  really held in a party room and plays on however many lanes it needs, so the
  method now falls back to the party rooms when no single resource of the
  attraction's type could physically take the party. **Capacity only, never
  availability**: falling back because the arena is booked would quietly put a
  second laser party in a party room at the same three o'clock. It was
  duplicated in both booking controllers; it now lives once in
  `BookingRepository`, which fixed the office form at the same time.
- **Reading a public page spent its POST allowance.** The throttle keyed its
  counter on the IP and the PATH, and four paths here answer both verbs — three
  of them pairing a generous read limit with a tight write one. So browsing
  `/book` eleven times made the enquiry itself answer 429 for the next ten
  minutes. Every request was individually well inside its own limit, which is
  why no suite that posts once per test could see it. The key now includes the
  method.
- **A blank catalogued add-on quantity meant "one".** Found while clicking
  through the office form: it put all fourteen catalogued add-ons on a booking
  — $5,569 of extras on a $511 party, with the arithmetic perfectly correct.
- **A booking could be saved with no room, and a roomless booking blocked
  nothing.** The form's room tick-boxes are an override, not a requirement, and
  somebody taking a party over the phone will not always touch them. Six
  roomless parties fitted at two o'clock while the calendar showed every room
  free. The write now takes the first room the engine has already said is
  available.

Two smaller ones, both wording: the public page's please-call fallback
resolved a missing phone number to the words "the venue", producing "please
give us a ring on the venue" in every refusal on an install that had not filled
the number in; and the page would offer times for a date beyond the year it
takes bookings for, then refuse the enquiry once the form was filled in — the
same "show them the form, then say no" the `?start=` validation exists to
prevent. Alternatives are clamped to the same limit for the same reason.

### Testing

The suite grew from 610 to **831 tests**. Every guard added in this phase was
mutation-checked: the public booking page's twenty-three mutations are all
caught, as are five against the capacity fallback and one restoring the old
throttle key. Three habits came out of it and are now in `docs/TESTING.md`:

1. A list test that never sets a filter does not test the filter.
2. Assert on a row LINK, not on the text you searched for — a filter bar echoes
   the term back into its own input, so a title assertion passes against an
   empty list.
3. Every refusal test needs its positive control beside it. A page that
   answered 404 to everything would satisfy half of them perfectly.

One thing is deliberately **not** tested, and the test file says so rather than
implying otherwise: the `FOR UPDATE` lock the booking write takes over its
candidate rooms. A sequential suite cannot interleave two writes inside one
transaction, so removing the lock leaves every test green — the re-check still
refuses the second enquiry because the first has already committed. What the
tests pin is that the re-check happens AT THE WRITE rather than only when the
page was drawn; the lock is reviewed, not measured.

---

## [1.0.0-phase4] — unreleased

Phase 4 is communications: one inbox for every channel, email and SMS sending
with the compliance rules in the send path rather than beside it, segments,
campaigns, and the Text Club.

### The shared inbox

- **Every conversation in one queue** (`#/inbox`), email and text together,
  with snoozing, canned replies and a first-response clock. A reply to a
  CLOSED thread reopens it: a closed conversation with an unanswered question
  in it is the one failure a queue exists to prevent.
- **Inbound texts arrive through a signed webhook** (`POST /webhooks/sms`),
  which is also what makes the Text Club possible — see below.

### Email and SMS

- **The rules live in the send path.** `Mailer::send()` and `Sms::send()`
  refuse, in order, for an invalid address, an empty or over-long body, paused
  sending, a missing opt-out line on a bulk text, a suppression, a missing
  contact, do-not-contact, missing consent, a number the guest did not opt in
  on, the weekly frequency cap, and an unregistered 10DLC sender — then defer
  for quiet hours, then redirect under Safe Mode. Every caller gets the same
  answer because there is only one implementation.
- **Segment counting is where the money is.** A text is 160 characters in
  GSM-7 and 70 in UCS-2, so one curly apostrophe pasted from a word processor
  can take a 300-character offer from two segments to five. The cost estimate
  is computed from the real body, and the page says so before anybody sends.

### Campaigns

- **The page tells the truth before the send**: how many will be reached, how
  many will not and why, what it will cost, and what is currently blocking it.
  "400 in the segment, 180 will get this, 220 have not opted in" is the most
  useful sentence on it — a venue that only ever saw 400 would read 180
  arrivals as broken sending.
- **A spend over $20 needs the word SEND typed**, not a second click. A click
  is what somebody does by reflex.
- **The audience is frozen when sending starts**, one row per person with a
  reason for every skip, so the venue can answer "why didn't Dana get it"
  months later. Cron walks it 50 at a time inside the campaign's own send
  window; nothing is sent in the web request, because forty thousand
  recipients is not a web request.

### The Text Club

- **Double opt-in, properly.** A guest texts JOIN (or uses the sign-up page)
  and is subscribed to nothing until they reply YES. Three cases subscribe
  NOBODY and each is tested: a YES with no invite outstanding, a YES to an
  invite older than a week, and a YES after a STOP. The first matters most —
  "yes" is the most ordinary word in a text message, and a guest answering
  "yes" to *are you still coming Saturday?* has not joined a marketing list.
- **A JOIN clears the do-not-text instruction; only a YES grants consent.**
  These come apart on the rejoin: somebody who texted STOP last summer and
  texts JOIN today has asked to be texted, so the suppression that would
  otherwise block even the confirmation has to go — while consent still waits
  for the YES. Conflating them either blocks every rejoin for ever or treats
  one word as full consent.
- **Consent is stored with its evidence**, including the exact disclosure
  wording as it stood that day. What a venue has to be able to produce is the
  wording from the day somebody agreed, not whatever the setting says now.
- **A twelve-month offer calendar**, because an arcade's quiet months are not
  its busy ones. All twelve shipped offers fit a single GSM-7 text, measured.
- **The validator blocks the save.** An offer with no opt-out line, or an
  invite missing its rates disclosure, is refused when it is written — not
  discovered on the first of the month after it has gone to the whole list.

### Two defects found by driving rather than by testing

Both were invisible to a passing test suite and both broke the feature
outright:

- **No inbound webhook existed.** `InboxController::receive()` composes the
  reply — the STOP confirmation, the Text Club invite — and returns it for a
  caller to deliver, and there was no caller. A guest could text JOIN, be
  recorded as pending, and never receive the confirmation they were meant to
  answer. The double opt-in dead-ended there, silently. `POST /webhooks/sms`
  is that caller: it verifies the provider's own signature (Twilio's
  HMAC-SHA1, Telnyx's Ed25519, or a shared secret) and REFUSES what it cannot
  verify, including when nothing is configured to verify against — an
  endpoint that is open until somebody sets a secret works perfectly in
  testing and is wide open in production.
- **A STOP confirmation could never be delivered.** Opting out suppresses the
  number, and the suppression then blocks every message to it — starting with
  the one that tells the guest their opt-out worked. Carriers expect that
  confirmation, and its absence is the most likely moment for somebody to
  conclude they are being ignored. `Sms::send()` now takes an
  `ignore_suppression` option that is honoured ONLY for a transactional
  message, so it can never become a way to put an offer in front of somebody
  who said stop.

Also fixed: the mailer recorded a message's cost only on the usage ledger and
not on the message row, so every email campaign reported costing $0.00 however
the venue had priced email — which reads either as broken sending or as free
postage.

### Testing

The suite grew from 831 to **1,129 tests**. Everything in this phase was
mutation-checked, and the sweeps are the reason several tests exist at all:

- The Text Club's rules: 30 mutations, 29 caught. The survivor is an
  equivalent mutant kept as the record of it.
- Its pages and the webhook: 28 mutations, 25 caught, 2 of them the two gate
  layers removed singly — which is unobservable by design, so the case that
  has to be caught is both at once, and it is.
- Campaigns: 18 mutations, 16 caught, the same two gate layers aside.

Four Text Club tests were measurably not testing what they claimed until the
sweep said so. The worst was the one guarding the webhook's most important
property: it checked that an unconfigured endpoint refuses a request signed
with *some other* secret, which the arithmetic refuses anyway. The real attack
is a request signed with the EMPTY secret, and only that version of the test
fails when the guard is removed.

One habit to add to the three from Phase 3: **a test that changes a global
setting must change it back.** Settings are shared and the suites run against
one database, so a leak surfaces as a failure in a different file — this one
turned Safe Mode off in the campaigns suite and broke a quotes assertion two
suites later.

---

## [1.0.0-phase7] — unreleased

Phases 5, 6 and 7: the automation engine, the reporting suite, and the two
machine interfaces — a REST API to read and write from outside, and outbound
webhooks to be told when something happens inside.

### Automations

- **The automation engine and 111 seeded automations**, across eight
  playbooks. A trigger is any event the application fires; a step waits,
  branches on a condition, messages a guest, tags them, moves a deal or calls
  a URL. Every seeded sequence a venue can switch on is authored, not a stub.
- **A trigger that can never fire is a build failure.** The test suite walks
  every seeded automation and refuses one whose trigger nothing fires, one
  with no steps behind a live trigger, and one scheduled to message a guest
  with nobody to message — the three shapes of an automation that looks
  finished and does nothing.

### Reporting

- **Twenty-three reports in six families**, each listed by the question it
  answers rather than its name, with CSV export and saved reports. A saved
  report keeps its period by NAME, so "last 30 days" still means the last 30
  days next month.
- **Venue-local days.** Every timestamp is stored UTC and every report groups
  by the venue's own calendar day, so a 9pm Saturday booking is Saturday's.
- **Money is scrubbed, not hidden**, for a role without `view_revenue` — in
  the page and in the export, which is a separate endpoint and the easy one to
  forget.

### Machine interfaces

- **A REST API at `/api/v1`**, bearer-token authenticated, 13 resources.
  A token never grants more than the person who owns it and its abilities only
  narrow; a record outside your scope is a 404, not a 403, because a 403
  confirms the record exists.
- **Outbound webhooks.** Subscribe to any event or to a namespace wildcard;
  each call is signed with an HMAC-SHA256 of the raw body, timestamped against
  replay, and carries a stable delivery id to deduplicate on. Six attempts
  over about nine hours, a delivery log with the reason in words, manual
  replay, and a subscription that switches itself off after twenty consecutive
  failures rather than retrying a dead endpoint for ever.
- **A webhook carries ids, not records** — no name, no email, no amount. A
  receiver that needs the record reads it back through the API with a token
  that can be scoped and revoked. A URL typed into a form should not become a
  standing export of guest records to somewhere nobody audits.
- **A URL pointing inside the network is refused when you save it**, including
  a hostname that resolves inwards, re-checked at delivery time with the
  approved address pinned for the connection.

### Bringing a venue's history across

- **CSV import for guests and visit history** (Settings → Import a file).
  Upload, map the columns — guessed from your own headings — check the whole
  file without writing anything, then import. A big file carries on in the
  background a batch a minute, so fifty thousand rows works on shared hosting
  where a web request would time out.
- **Nothing is written until the check has run**, and the server refuses a
  commit without one, not just the button. The check reads the real file
  against the real database, so what it reports is what will happen.
- **Every row is accounted for, by row number.** A count of refusals tells a
  venue that guests are missing and nothing about which ones. The report names
  each one with the reason in words, groups them so it is obvious whether it is
  one problem or a hundred, and downloads as a CSV to fix and re-upload.
- **Imported guests are consented to nothing**, whatever the file says — the
  column that looks like consent is usually a default somebody set once for
  everybody. A consent column is honoured only if you tick a box affirming you
  hold the records, which is audited with your name on it. A do-not-contact
  column is always honoured, and an import can never change a consent that
  already exists.
- **A date that could be read two ways is refused, not guessed.** `03/04/2024`
  is two different birthdays, and one wrong by a month sends the offer to the
  wrong week every year. The mapping screen asks which order your dates are in.
- **Re-uploading the same file is safe.** People match on email then phone;
  visits go through the same recorder the front desk uses, so one guest on one
  day is one visit however many transactions the file holds, and lifetime value
  is derived rather than added up.
- A visit for somebody not in the database is **refused, never used to create
  them** — otherwise a till export becomes tens of thousands of nameless
  records whose only field is a card number.

### Fixed

- **An upgrade no longer depends on somebody remembering to edit `VERSION`.**
  It did, and `VERSION` had not moved in three phases — so three schema files
  were never applied by an upgrade, and the reporting, API-token and webhook
  pages would each have met a venue with a bare 500. The upgrade now also
  triggers on the schema directory's own contents.
- **A contact added by staff now fires `contact.created`.** It only fired for
  guests who arrived by themselves — a form, the booking page, a text, an
  email, the API — so a "welcome a new guest" sequence ran for everybody
  except the people the front desk typed in. Nothing failed; the sequence
  simply never started.
- **No page scrolls sideways on a phone.** An invisible screen-reader label
  inside a wide table was escaping its scroll container and dragging the whole
  layout with it, and a one-line ellipsised tagline was setting a floor under
  the dashboard's width. Measured on all 30 pages at 390px.
- **A token and a signing secret are shown in a monospace font.** The rule
  that said so had never applied, so 64 hex characters somebody has to check
  by eye were rendered in a font where `l`, `1` and `I` are the same picture.
- **Pressing Test on a webhook no longer queues five more attempts** at an
  endpoint it has just reported broken.

### Hardening (phase 8)

The full method and every figure are in `docs/AUDIT.md`. What changed:

- **`tests/` and `tools/` were executable over HTTP.** The rewrite rules let a
  real file through, as they must for the stylesheet and the installer, and
  those two directories were not in the deny list — so `GET /tests/run.php`
  returned 500, meaning it ran. That file boots against the configured
  database and can drop every table in it. Denied by path, guarded in PHP for
  hosts that never read `.htaccess`, and gated so a new script cannot appear
  without one.
- **`/.git/config` was readable**, because the dotfile rule tests a file's own
  name and `.git/config` is a file called `config`. An install deployed by
  cloning was publishing its repository history. Now denied by path, with
  `.well-known` exempt so AutoSSL can still renew a certificate.
- **26 tables had no caption**, so a screen reader announced each as a grid of
  cells and started reading. All captioned, and gated.
- Checked and held: the CSP forbids inline script, a guest whose name is an
  XSS payload is escaped on every page that draws a name, the CSV export
  escapes a cell that could be read as a formula, `/api/v1` refuses a session
  cookie, uploaded import files are unreachable, and no error page leaks a
  path or a driver message.
- **Measured at 50,000 contacts and 106,123 visits**: every page under 200ms,
  every report under 170ms, and the 50,000-row CSV export peaks at 6MB of a
  128M limit — flat across 100,000 rows, which is what proves it streams.
- **A real restore drill**: backup, `DROP DATABASE`, restore, and the row
  counts and money sums match to the penny — then the application was signed
  into and used, because a restore the application cannot use is not one.
- **Accessibility measured in a browser**: 124 page loads, two themes, two
  widths, contrast taken from real pixels wherever a gradient made it
  uncomputable. Zero findings after the captions.

### Fixed in review (the API)

Five of these are the same mistake in five places: **the API checked that a
permission was HELD and not what it covered**, so a role an operator can
build in Settings in one afternoon reached further through `/api/v1` than
through its own pages. None of them failed visibly, and none of them was
reachable by accident — which is exactly why they needed finding.

- **Deleting through the API checked the EDIT permission.** This application
  separates the two everywhere a page does — `contacts_manage` edits a guest
  and `contacts_delete` removes one, `bookings_manage` edits a party and
  `bookings_cancel` takes it off the calendar — because the front-desk role
  holds the first and not the second. A front-desk token could delete exactly
  what the same person's own pages refuse them. Each resource now names its
  own delete permission, the discovery endpoint reports `deletable` beside
  `writable`, and a test states the four that are deletable as data so a new
  one cannot open an endpoint by existing.
- **Every write loaded its record under the READ scope.** Scopes are per
  permission, so "see everyone, edit your own" — ordinary, and buildable in
  Settings — was honoured on the read and ignored on the write: a token could
  PATCH, delete, record consent for, or add a visit to somebody else's guest.
  The permission that governs the call now decides the lookup, and a record
  outside it is a 404 rather than a 403, so an id space cannot be walked.
- **A visit took any `contact_id` at all** — no scope, and a soft-deleted
  guest too, which would have quietly revived them in every report that
  counts visits.
- **A deal's stage was a writable column.** Setting it skipped every
  consequence of a move: the stage clock the velocity report measures, the
  won/lost date, the SLA deadline, the stage-history row, and the events that
  start the deposit chase and the thank-you. The board would show a deal in
  "Won" with no won date and no automation ever running. Moving a deal is now
  `POST /api/v1/deals/{id}/stage`, by stage name or id, through the same code
  the board posts to — idempotent, and a stage on another pipeline is refused
  with a reason. Deals created through the API now also open with the stage
  clock running and an opening history row, as ones created on the board do.
- **An empty ability list on a token read as "no restrictions".** Reachable,
  not theoretical: a token asked for with a renamed or misspelled permission
  had every entry dropped by the cleaner — correctly — and was then stored as
  an empty list and read back as full access. The narrowest request anybody
  could make produced the widest token, with nothing beside it on the page to
  say so. An empty list now grants nothing, such a token is refused at
  creation with the names that were not recognised, and a row already holding
  one says so on the page instead of showing a blank cell.
- **A delete permission a token could not be given.** The fix above made
  DELETE check `contacts_delete` / `deals_delete` / `bookings_cancel` — and the
  token page's ability list is built from each resource's read and write
  permission only, so there was no box to tick for any of them. A token could
  be scoped to read and edit guests and never to delete one; an operator who
  needed that had to leave every box unticked, which mints a token carrying
  everything its account can do. A narrowing fix whose practical effect is to
  push people onto the widest available credential has made things worse. The
  delete permission is now offered beside read and write, and a test asserts
  that **every permission the API consults can be put on a token** — and that
  nothing is offered which the API never asks for — so the next permission
  that splits off cannot become un-mintable the same way.
- **A deal could be left on a board it had no stage on.** `pipeline_id` is
  writable and `stage_id` deliberately is not, and those two facts together
  meant a PATCH naming only the pipeline moved the deal to a new board while
  its stage still belonged to the old one. Measured: `pipeline_id 2, stage_id
  1` with stage 1 belonging to pipeline 1 — a state no page renders sensibly
  and every stage-keyed report mis-attributes. The web's edit form cannot
  produce it, because there the pipeline follows the chosen stage. Here the
  caller names the board, so the stage follows it: the deal enters the first
  stage of the board it is moving to, through the same transition as any other
  move, with the history row and the events. A pipeline that does not exist is
  refused rather than applied.
- **A PATCH can no longer open or close a deal.** Two ways it could. Moving a
  deal to another board applies the new board's first stage, and that stage's
  columns follow the stage — so a deal won two minutes earlier came back
  `status: open` with `won_at` **erased**, from a request that named nothing
  but a pipeline, with a 200 and no event to say a won deal had stopped being
  won. That is the date every revenue report keys on. And the mirror image: a
  board whose first stage an operator has marked won or lost (the stage editor
  allows it) would have *closed* a deal from the same PATCH, firing `deal.won`
  or `deal.lost` — and `deal.lost` starts a guest-facing email. Both are
  refused now, pointing at the stage endpoint, where reopening or closing a
  deal is the thing being asked for rather than a side effect. Winning and
  losing are transitions with their own endpoint; that was always the rule,
  and a field update had found a way around it.
- **The one permission that records a visit could not be ticked on a token.**
  The Visits group offered Read and nothing else, because `visits` has an
  empty writable list on purpose — a visit goes through the recorder, not a
  plain insert — so the loop that builds the checkboxes skipped its write
  permission. An operator ticking the only Visits box got a token that cannot
  do the single thing that endpoint exists for. The box is there now, labelled
  as the same permission as writing a contact, because it is one. The test
  that was supposed to catch this asked only whether the slug appeared
  *somewhere* on the page; it now asks per resource.
- **A field that names another record now has to be its id — one rule, in one
  place, instead of a fourth patch.** Three of the fixes above were the same
  bug wearing different clothes, and it took a third review round to see it:
  PHP turns `true` into `1` and `"abc"` into `0`, and the API filtered *which*
  fields a caller could write without ever checking *what shape* they were. So
  **every** `*_id` field in every resource quietly resolved to "record 1" or
  "no record" and answered 200 — a deal attached to whichever company happens
  to be id 1, a guest's visit filed against a stranger's party, a deal
  relocated to the first board in the list. `ApiResource::writable()` now
  requires a positive integer, a digit string, or `null` to clear a link, and
  refuses anything else with a 422 naming the field and the reason. A unit
  test asserts no writable foreign key escapes the rule, so a field added
  later is covered the day it is added rather than the day somebody notices.
- **A stage named by something that is not a name or an id.** `(string) true`
  is `"1"`, which the resolver reads as stage id 1 — the top of the first
  board. So a client whose stage field carried a flag rather than a name moved
  the deal backwards and got a 200 saying it had. An array could not be cast at
  all, which made this the one write in the API that could answer 500. Only a
  string or an integer is accepted now, anything else is a 422 that says so,
  and the move is wrapped like every other write so no input can produce a
  fatal.
- **A body that is not an object is now refused for being one.** Every write
  read the body through a getter typed `: array`, which hands a list, a bare
  value and unparseable text over as `[]` — so the guard each of the five
  writes carried for exactly this case could never run, and the request fell
  through to the next field check. Measured: a POST of `[1,2,3]` to the stage
  endpoint was answered "Name the stage to move to", describing a field a
  list cannot carry, and a form-encoded POST was told it had sent no fields
  when it had sent three. The body is read for its shape now and a 400 names
  it — a JSON list, a single value, or not JSON at all. A body that *is* an
  object, including an empty one, and a request with no body at all are
  unchanged: "Nothing to create — send at least one field" was already the
  right answer to those, so it is still the one given.
- **Imported money is parsed by integer arithmetic**, not through a float.
  The reported half-cent loss did not reproduce — PHP's `round()` compensates,
  measured over 3,000,001 values — but the correctness of a venue's imported
  history rested on a rounding helper's fuzz compensation, which no reader can
  verify by looking. Two real defects fell out of writing it exactly: a
  figure in scientific notation had its letters stripped like a currency code
  (`2.5E+3` became $2.53 — $2,500 was meant), and above about $90 trillion a
  float loses the last cent. Both are now refused and reported against the
  line they came from, rather than imported as a number nobody typed.

### The calendar the tests were written in

- **Three tests broke the moment a run crossed midnight UTC**, and the fix
  for those three — `test_local_day()`, and the diagnosis it carries — landed
  with the validator change above. What was left was the question that fix
  could not answer by itself: whether the rest of the suite had the same
  thing wrong, and nothing to stop it happening again.
- **Every date the suite builds from the clock has now been read and
  classified one at a time** — 71 lines, which is what the new gate below
  flags on the tree before this change. The ones that reach a venue day were
  converted to `test_local_day()`; the 29 that remain are UTC on purpose, and
  each carries a line saying so, because the alternative is every future
  reader deriving it again. They are not all the same case: `SegmentEngine`
  windows really are `UTC_TIMESTAMP()`, the importer refuses a future date
  against its own `gmdate('Y-m-d')`, and a `visit_date` posted to the API and
  compared only with itself has no calendar in it at all. Three spellings the
  first pass did not cover are included: bare `gmdate('Y-m-d')`,
  `strtotime('-3 days')`, and a whole-day offset on a timestamp — which is
  the shape the two membership tests were actually written in.
- **A build failure rather than a comment.** `static/quality-gates` refuses a
  test that builds a day from UTC unless a comment within six lines says why
  UTC is right there. A marker rather than an allowlist of file:line: an
  allowlist goes stale on the next insertion above it and then either fails
  honest tests or silently stops covering the line it named, and the reason
  belongs where the date is written. An offset in hours or minutes is left
  alone — those are instants, and instants here really are UTC.
- **`CC_TEST_VENUE_TZ` runs the whole suite in another venue's calendar**, so
  this class of bug can be reproduced on demand instead of waiting for the
  evening. The suite is green in four positions: the Castle inside its bad
  window, the Castle at midday, a venue 11 hours behind UTC, and a venue 14
  hours ahead. The last of those found two tests that failed on their own
  preconditions rather than standing down, which read as a timezone bug
  rather than as a case that does not apply.
- **The override now survives the run, and says so if it does not.** Review
  caught that `http/smoke` saves the venue settings form with a hard-coded
  `America/New_York`, which SET the zone: from that suite onwards every HTTP
  request was served in New York while the test process still held the
  override, and the run reported a clean pass over an instrument that had
  quietly stopped working. The form now echoes the zone already configured —
  nothing in that test is about timezones, it is a required field — and
  `tests/run.php` fails any run that does not end in the zone it was asked
  for, because a tool that silently stops testing is worse than one that is
  not there.

---

## Unreleased — next

### Added: installing on a plain Ubuntu server

`git clone`, then `sudo ./install.sh`. On Ubuntu 22.04 or 24.04 LTS the
script installs Apache, PHP and MariaDB, places the checkout in
`/var/www/castle-crm` owned by root with only `config/`, `install/` and
`storage/` writable by the web server, creates the database and a user with a
random password, writes the Apache site and the cron job, and with a domain
gets a Let's Encrypt certificate. It then stops at the web installer, so the
venue, the owner and the seeds are still chosen in one place for both routes.

Because a git checkout cannot usefully delete `install/` (the schema lives
there and `git pull` restores it), the Apache site closes `/install/` to the
web as soon as the lock file appears, letting only the finish page through.
CI runs the script on clean 22.04 and 24.04 containers, re-runs it to prove
it is repeatable, then walks the whole web installer, signs in, checks that
`/config/config.php`, `/.git/config`, `/install/` and the rest stay private,
and runs cron as `www-data` (`tests/install/ubuntu-smoke.sh`). See
[INSTALL-UBUNTU.md](INSTALL-UBUNTU.md).

### Added: Settings → Attractions

Every attraction's season, age and height rules, capacity and party role is
read by the booking page — an outdoor ride out of season is not offered, a
party is built around a "main" attraction — and only the seed could set
them. Opening the go-karts a fortnight early was a database edit. Now each is
editable, a new one can be added, and one can be switched off (never deleted:
packages, add-ons and past bookings point at it). A season is a month and a
day, the same every year, and may wrap the new year; the tests save one and
then ask the availability engine whether a date is in it.

### Added: Settings → Offers

The seed made three offer codes and nothing could make a fourth, change what
one is worth or when it ends, though `coupons_manage` had been in the
permission catalogue since phase 1. Now a manager can add an offer (percent
off, dollars off, a free item, buy-one-get-one), set a minimum spend, limits
per guest and in total, and the days it runs; and see how many each has handed
out and how many were redeemed.

A code never changes once it exists — a guest may be holding it in a text —
and an offer a switched-on automation hands out cannot be switched off. "Good
until December 31" means until the end of the 31st in Chester: stored as UTC
from the venue's own day, where a bare date would have ended it at 7pm on
the 30th.

### Added: Settings → Hours

When the venue is open is what the online booking page offers, and nothing
but the installer's seed could set it — so every holiday was a normal day to
the booking page, and summer hours were whatever the website said the day the
seed was written. `hours_manage` had been in the permission catalogue since
phase 1, used by nothing.

One page now edits all three things the availability engine reads: the
standard week (a day ticked closed is closed), dated seasons with their own
week, closures and special hours for single dates, and an attraction down for
the day. It explains the engine's precedence in words — a season beats the
standard week, a single date beats both — and a season offers no "closed"
box, because in the engine a day missing from a season falls back to the
standard week: closing one date is what a closure is for. Tests drive the
page and then ask the availability engine itself what it would offer.

### Added: Settings → Message templates

The words guests receive — 38 emails and 11 texts — could not be changed
without editing the database, though the permission catalogue had described
`templates_manage` since phase 1 and nothing used it. Now a manager can edit
any of them with the email rendered beside the form, filled in with a sample
family, and preview a draft before saving it. A text shows as a bubble with
its segment count, and says so when a marketing text has no way to opt out.

Three rules keep an edit from breaking a send:

- **A template a switched-on automation sends cannot be switched off**, and
  the password reset cannot be switched off at all. The step would fail on its
  next run and the guest would be half-told.
- **A tag the template is not sent with is named on save.** `{{frist_name}}`
  comes out blank in every message.
- **A slug never changes**, because automation steps name templates by it.

New templates start as a copy of one that works. "Email me a test" sends
through the real path, so Safe Mode still applies. The preview is served with
a sandbox Content-Security-Policy, so whatever HTML a template holds runs
nothing.

### Added: a nightly sync from CenterEdge

`tools/centeredge/ce_sync.py` is the second half of the bridge: after the
one-off CSV load, it pushes what changed in CenterEdge since its last run —
new and edited guests, and their visits — to the REST API from the venue
machine, nightly from the user's own crontab. Read-only against CenterEdge,
with the export's rules about minors and placeholder phones.

On a guest already in the CRM it fills BLANK fields only, so a correction
made in the CRM is never undone by the till's older copy. Visits go through
the API's day-idempotent recorder, so overlapping runs change nothing.
Consent is not carried unless the venue turns it on and names the wording
guests agreed to, which becomes the consent log's evidence — the same
decision the importer asks a person to affirm.

Measured against a local install over two real days of CenterEdge changes:
116 guests created and 82 visits recorded in 104 seconds with no errors and
no rate-limit refusals; a second run over the same window created and
recorded nothing. It paces reads as well as writes — the first version paced
only writes, spent a minute's reads in seconds, and sat out every window
after that behind a 429.

### Added: exact-match API filters, and the POS number on contacts

`?external_pos_id=4021`, `?email=…` on contacts and `?external_ref=…`,
`?contact_id=…`, `?visit_date=…` on visits. A bridge finding a guest by card
number had to page the whole guest list. `external_pos_id`, `address_1` and
`address_2` are now readable and writable on contacts. A test holds every
exact filter to a field the resource already returns, so a filter cannot be
used to ask about a hidden column.

### Fixed: a compiled Python file was committed

`tools/centeredge/__pycache__/` came in with the export tool. It is removed
and ignored.

### Fixed: every birthday and party date showed a day early

`local_date()` is for instants stored in UTC. Given a DATE — a child's birth
date, a party's `event_date` — it read the day as midnight UTC and moved it to
New York, which is the evening before. So Lucia, born 21 October, was shown as
born on the 20th; the date on a family's own booking confirmation page was a
day out; and a visit, which is stored as a midnight DATETIME because it has no
clock time, showed as the day before it happened. The demo data hid the last
one by giving visits afternoon times; an imported visit history would not
have.

`calendar_date()` formats a day as the day it is, and every DATE now goes
through it. `local_time()` also leaves a bare `YYYY-MM-DD` alone, so a stray
one cannot go wrong again.

### Added: when each child next turns a year older

A guardian's page lists their children with the age they are turning and when
— "turns 8 in 2 days" — in the brand colour inside a month, because that is
when the birthday playbook is working on them. The household page says the
same. 29 February falls on the 28th in a year without one.

### Polished

- **The calendar's toolbar is one row**: view, period, and a jump to a date,
  wrapping as groups on a phone. It borrowed the filter bar's grid, which put
  the date picker in a column of its own with its button stacked beneath; the
  day-name headings lose the dotted underline that made them look like links.
- **Form controls are one height.** A browser draws a select a few pixels
  taller than a text input, and in a row aligned on its bottom edge that put
  the contacts filter's "Search" label higher than "Stage" and "Owner". Filter
  bar buttons now match the controls beside them.
- **Recent activity shows changes, not sign-ins.** Eight logins filled the
  dashboard card; the audit log keeps them.
- **Bookings and Check-in have icons of their own** (a cake and a ticket)
  instead of sharing Calendar's and Tasks'.
- The System card's long status wraps on the right instead of squeezing its
  label onto two lines; "1 accounts" is "1 account"; the household page's
  children table no longer tells a screen reader it is the household's visits.
- **Text Club's header said "Castle CRM"**: its controller passed `title`
  where the layout reads `pageTitle`. The smoke test that walks the sidebar now
  fails any page whose header is the fallback.
- **Five tables were unstyled** — Segments, a segment's members, Campaigns,
  the Inbox and Text Club's list were bare `<table>`s with the browser's
  centred headings. And a row header (`<th scope="row">`) now reads as the
  first cell of its row instead of a column heading in small capitals.
- **Every filter bar lays out the same way.** The Inbox wrapped its form in
  the grid, so its fields stacked; Memberships, Automations and Reputation
  feedback put a bare button in the grid, so "Filter" stretched to a whole
  column.
- **Check-in's day is between its arrows**, not at one edge of the page with
  the arrows at the other.
- **Choosing what kind of file to import is a set of cards**, the chosen one
  highlighted. The `check` class those labels wore was named in three views
  and defined nowhere, so the hint under each choice ran on in bold inside the
  browser's own fieldset frame.
- **Add-ons have an edit page** like packages, instead of an "Edit" row under
  every line of the table, and a card after a form no longer sits on the
  form's buttons.

### Added: a release zip that holds only what the server runs

`tools/build-release.sh` builds the zip an upgrade uploads, from the committed
tree: 1.2 MB, against GitHub's 9 MB download, without the test suite, the CI
workflow, the screenshots, the CenterEdge export or the development tools. It
checks the zip itself for what must never be in it (a config, the tests) and
for what must (the front controller, cron, the installer, `.htaccess`).

### Added: a demo install in one command

`php tools/dev-install.php` builds what the web installer builds, with the
example guests, into a `*_dev` database and writes the config to serve it — so
`tools/screenshots.js` has something to photograph without a browser walk.
It refuses to overwrite a config or touch a database not named `*_dev`.
### Added: The Castle's guests, children and visits, from CenterEdge

The question the deployment runbook said nobody had ever answered — does
CenterEdge hold usable guest email at all? — is answered, read-only, against
the live database on 19 October 2026: 74,607 of 137,879 accounts carry an
email and 45,163 of those ticked CenterEdge's bulk-email box. So the bridge is
worth building, and `tools/centeredge/ce_export.py` is its first half.

It runs on a machine at the venue (the CRM is on shared hosting and the POS is
on the LAN) and writes three files the importer takes as they are: about
85,000 adult guests, 32,000 children, and 134,000 guest-days of visit
history, in five seconds. Every statement is a SELECT, read uncommitted so the
till never waits behind it, and it refuses to write inside the repository.
`tools/centeredge/README.md` is the procedure.

**A minor's own account is never a contact.** CenterEdge holds 35,000 accounts
for under-18s, many with a parent's email. `docs/PRIVACY.md` allows a child
only as a first name and a birth date under a guardian, so a minor linked to a
parent becomes a child record under that parent, and one with no parent is
left out and counted.

**Phone numbers that are nobody's own are dropped.** 123-456-xxxx was on 150
accounts and the venue's own main line on 112. The importer treats a phone as
identity, so a number on more than ten accounts is blanked; the guest is kept
if they have an email.

### Added: importing children

A third kind of import file, beside guests and visit history: a child's first
name and date of birth, with their guardian's email, phone or card number.
It is how a venue's party history reaches the birthday campaign.

The guardian must already be here — a row whose guardian is not is refused,
never used to invent one. Only the first word of a name is kept, because a
child's surname is one of the things the record does not hold. One child per
guardian, name and birthday: a duplicate in the file or a re-import is
skipped, so a family is never written to twice about one child. Somebody 18 or
over is refused as "import them as a guest instead".
### Fixed: two test runs in one checkout broke each other, and a crash orphaned the server

The runner's clean-up restored "whatever config was there before" without
asking whether it had put anything there. So a quick `--unit` pass, which
never writes a config, deleted or swapped out the one a concurrent full run's
server was reading, and that run failed from then on with "No CSRF token
found on /login" — at a test file's top level, which ended the runner before
its clean-up, so its web server lived on as an orphan holding the port. The
runner now records the exact config it wrote and removes or restores only
that, and stops its server from a shutdown handler too, so a crash cannot
leave one running.

### Added: every party The Castle sells, at CenterEdge's prices

The first seed carried four birthday packages copied off the website. The
venue's own system sells eleven, and the one it sells most — **InflataPark,
792 of the year's parties, about half** — was not among them. The list now
comes from CenterEdge's `GroupEventTypes` (read 19 October 2026): InflataPark,
Roller Skating, Laser Tag, Bowling, All Access and its winter price,
Ballocity, Mini Golf, the InflataPark Toddler Party, Glow Room and Bounce.

Three of the original four were a cent out: CenterEdge lists Laser Tag, Mini
Golf and Ballocity at $349.99, where the website rounds to $349. Older price
points CenterEdge kept as separate event types ("Laser Tag Party $299") are
one product, so one package; a historical booking keeps its own total.

`install/schema/013_castle_party_packages.sql` brings an existing install to
the same list without trampling it: a package is added only if its slug is
free, and a price or position is corrected only where it still holds the
original seed's value, so a price the venue has set is theirs. On a fresh
install it inserts nothing — the schema runs before the venue seed, and the
seed writes the same rows with their attractions linked. A test builds the
list both ways and holds them identical.

### Added: what a family can book online is the venue's choice

`party_packages.is_online`. CenterEdge sells six of the eleven on the web; the
All Access, Toddler, Glow Room and Bounce parties are booked by phone or at
the desk. `is_active` could not say that — switching a package off took it
out of the staff booking form too. The public booking page now offers only
online packages.

### Added: Settings → Party packages

There was no screen for prices at all: the permission catalogue had described
`attractions_manage` as "Edit attractions, packages and resources" since phase
1, and nothing used it. Now a manager can edit every package — price, guests
included, each additional guest, deposit, length, notice, what is included,
online or desk-only — add one, and add, edit or retire add-ons.

A package or add-on that is on a booking cannot be deleted, only taken off
sale: the foreign key would allow it, but `ON DELETE SET NULL` would quietly
turn every one of those bookings into "no package". A slug never changes after
creation, because the upgrade files and the CenterEdge import find a package
by it. Prices are stored with `round()`, not a cast, because 409.99 × 100 is
40998.999… in floating point.

### Fixed: a migration run against another prefix recorded itself in the wrong table

`Migration` applied a file's statements under its own prefix and then recorded
the file with `DB::insert('migrations')`, which expands the GLOBAL prefix. In
production the two are always the same, so it never showed; under any other
prefix a run recorded files as applied in a table that had not received them.
It now records into its own prefix's table.

### Fixed: the installer walk-through left its web server running

`tests/install/walk-installer.php` started its server through a shell
command, so the shutdown handler's `proc_terminate()` killed the shell and the
PHP server it had launched lived on — one orphan per run, each still holding
the caller's output open, so a piped run hung after printing PASS. It now
starts the server the way `tests/run.php` always has, as an argument array
with no shell in between.

### Fixed: nobody could sign in on a PHP built without Argon2

`Auth::attempt()` checked whether a stored hash needed upgrading by naming
`PASSWORD_ARGON2ID` directly. `Auth::hash()` had always fallen back to bcrypt
when that constant is missing, but the rehash check did not — so on a PHP
built without libargon2 the constant was undefined, the check threw AFTER the
password had verified, and every correct sign-in ended in a 500. Wrong
passwords still failed politely, which is what made it look like a working
login screen. Found by running the suite on PHP 8.1, where it took down 497
tests at once. `Auth::needsRehash()` now makes the same choice `hash()` does.

The timing decoy for an unknown address had the same blind spot: it verified
against an Argon2 hash, which on such a PHP returns at once — so a response
that came back fast meant "no account with that address". It now verifies
against a hash of whichever algorithm the server actually uses.

### Fixed: every import, export and backup failed on PHP 8.4

PHP 8.4 deprecates calling `fputcsv()`, `fgetcsv()` and `str_getcsv()`
without `$escape`, and the error handler turns a deprecation into an
exception. On a host that upgraded to 8.4 the guest import, every CSV export
and the backup download all failed. Every call now passes `escape: ''` — RFC
4180, what Excel writes, and PHP 9's default — and a static gate fails the
build if a call ever relies on the default again.

### Added: CI that runs locally

`tools/ci-local.sh` runs what the workflow runs — lint, the full suite on
every PHP version installed, and the installer walk-through — without GitHub
Actions. See `docs/TESTING.md`.

### Fixed: the app was telling venues to break their own email

**`Settings → Email` printed `v=spf1 +a +mx ~all` for the domain whatever the
driver said.** That is the right SPF record for a venue whose mail leaves the
web server, and a catastrophe for one whose mail lives at Microsoft 365 or
Google Workspace: a domain publishes only ONE SPF record, so pasting it
REPLACES the provider's, and every message the company sends starts failing
authentication — staff mail included, not just this app's. The Castle's own
install is exactly that case, so the instruction would have taken a venue's
email down on the day they deployed.

The advice now follows **who actually sends**: `Mailer::dnsAdvice()` reads the
configured driver and SMTP host, names the sender on the page ("your mail is
sent by Microsoft 365, so that is what these records have to authorise"), and
prints that provider's own SPF term. Six providers are recognised by name; an
unrecognised SMTP host is SAID to be unrecognised, with where to look, rather
than guessed at — and in no case does it authorise this server when this
server is not what sends. The one-record-per-domain rule is stated on every
record that has it, because the failure is silent and total.

`INSTALL.md` §6 was rewritten to match, and two further traps are now named:
**cPanel's Email Deliverability → Repair cannot write a zone held at
Cloudflare or a registrar** (it was offered as "the shortcut" unconditionally,
which leaves an operator believing mail is configured when it is not), and
**every mail record at Cloudflare must be DNS-only** — a proxied DKIM record
silently fails with nothing to say so.

### Added: a deployment runbook for the venue

`docs/DEPLOY-CASTLE.md` — the install with the Castle's own pieces named, in
the order they have to happen: the Microsoft 365 mailbox and SMTP AUTH (off by
default, and the usual cause of a correct-looking setup refusing to
authenticate), the Cloudflare records and the grey-cloud rule, the certificate
before the HTTPS requirement, importing the CSV lists while Safe Mode still
guarantees nothing reaches a guest, and why the POS feed has to be a push from
the CEplay box rather than a pull from shared hosting. It also carries the
five-minute Database Explorer query that settles whether CenterEdge holds
usable guest email at all — the question `SPEC.md` §14 calls its most
important, which nobody has ever run.

### Added: removing the example data, which the runbook had been promising

The installer offers "Example guests and bookings" so the reports can be
judged on something that looks like a Saturday, and the runbook tells a venue
to delete the lot before importing a real guest list. **There was no way to
do that** — the doc described a button nobody had written, found while
preparing the runbook rather than by any test.

**Settings → Backups → Remove the example data** is now that button: it names
the count first, asks for the word DELETE, and leaves an audit row. It deletes
the seed's rows EXPLICITLY, children before parents, rather than deleting the
demo contacts and trusting the database — because every foreign key here is
`SET NULL` or `CASCADE` and not one is `RESTRICT`, so the obvious version does
not fail, it SUCCEEDS and leaves fake bookings behind with no guest. That is
worse than failing: it looks finished. The seed's own header records hitting
exactly that.

Two things it deliberately will not do: delete a booking or deal the venue
made itself against an example guest (those are theirs, so they survive with
no guest attached — and the count is on the page BEFORE the decision, not in
the message afterwards), and delete the `demo-data` tag while anything else
still wears it.

### Fixed: a "coming soon" page for four features that had shipped

The sidebar carried a **Marketing** item pointing at an "Arrives in Phase 4"
placeholder listing campaigns, segments, Text Club and web forms — all four
of which were live, and three of which sat in the nav directly beneath it.
The placeholder, its route, its controller and its styles are gone, and
**Forms** takes the slot, which had no nav entry of its own at all.

A smoke test now walks the sidebar itself and holds every item in it to
rendering for an owner and to not being a placeholder, so neither a nav link
that goes nowhere nor one still advertising a shipped feature can come back
unnoticed.

### Added: screenshots of every section

`docs/screenshots/` — 39 images, one per section plus dark mode and three at
phone width, with an index naming what to look at in each. `tools/screenshots.js`
takes them and asserts as it goes: no page logs an error, no page answers
anything but 200 — checked on the status AND on this application's own error
titles, which are "Not permitted", "Too many requests" and "Method not allowed"
rather than the three a first draft guessed at — and no page scrolls sideways
at 390px, measured on every section rather than on the three captured at phone
width.

`tools/screenshot-guard.php` runs first and refuses the capture unless every
contact carries POSITIVE evidence of being fictional in every detail: a
reserved email domain and a phone in 555-0100 to 555-0199. Where the row came
from is not evidence, since an example guest can be edited into a real one,
and a missing email is not evidence either — that is the ordinary shape of a guest who arrived by text, and
reading it as proof of fiction cleared a whole venue's real list. It also
refuses a user account on a real email domain (the user list is one of the
pictures, and the signed-in name is in the sidebar of all of them) and a real
SMTP host or from-address, which the email settings page shows in full. The
check is on the DATA, not on a flag somebody set: these images go into git, and
a venue's guest list in git history is in every clone for ever.


Phase 8 is done and written up in `docs/AUDIT.md`. **Phases 1–6 and 8 are
complete. Phase 7 is partly done**, and the part that is missing is the part
that needs the venue's own systems rather than more code:

- **Built:** the REST API, outbound webhooks, iCal feeds, and CSV import for
  guests and visit history.
- **Not built:** the live CenterEdge MSSQL feed and card API, posting a review
  reply through Google Business Profile (the reputation module records the
  reply instead — §8.9), and the weather hook. There is no dedicated Slack
  integration either, though the automation engine's generic `webhook` step
  takes a Slack incoming-webhook URL today.

**None of that is blocked on code.** `docs/SPEC.md` §14 holds the questions
it waits on, and two of them have real lead times: whether CenterEdge holds
usable guest email and phone at all (§14 #3, which the spec calls its most
important question — near-zero coverage means list-building beats importing),
whether an MSSQL driver is even reachable from the host (§14 #6 — on cPanel
shared hosting it probably is not, which makes a venue-side export into the
CSV importer the permanent answer rather than a stopgap), and a 10DLC brand
registration for SMS (§14 #8, 2–4 weeks of carrier lead time, and it gates
the Text Club playbook).
