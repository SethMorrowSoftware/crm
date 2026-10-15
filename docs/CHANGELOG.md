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

- **364 tests, no test framework** — the same no-dependency constraint as the
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
