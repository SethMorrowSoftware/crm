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
- **Imported money is parsed by integer arithmetic**, not through a float.
  The reported half-cent loss did not reproduce — PHP's `round()` compensates,
  measured over 3,000,001 values — but the correctness of a venue's imported
  history rested on a rounding helper's fuzz compensation, which no reader can
  verify by looking. Two real defects fell out of writing it exactly: a
  figure in scientific notation had its letters stripped like a currency code
  (`2.5E+3` became $2.53 — $2,500 was meant), and above about $90 trillion a
  float loses the last cent. Both are now refused and reported against the
  line they came from, rather than imported as a number nobody typed.

---

## Unreleased — next

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
