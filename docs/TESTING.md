# Testing Castle CRM

Castle CRM ships with **zero Composer dependencies**, and the test suite keeps
that constraint: it needs nothing but PHP and a MySQL-compatible database. CI
therefore needs exactly what the production host needs, which is the point.

```bash
# Everything (needs a database)
php tests/run.php

# Unit and static checks only — no database, no server
php tests/run.php --unit

# One area
php tests/run.php --filter=router
php tests/run.php --filter=validator,security

# Stop at the first failure
php tests/run.php --stop

# Refuse to report success if a suite silently skipped (what CI uses)
php tests/run.php --require-db --require-http --min-tests=1400

# Prove the product's actual promise: that it installs on a bare host
php tests/install/walk-installer.php
```

## Configuration

| Variable | Default | Purpose |
|---|---|---|
| `CC_TEST_DB_HOST` | `127.0.0.1` | |
| `CC_TEST_DB_PORT` | `3306` | |
| `CC_TEST_DB_NAME` | `castle_crm_test` | **Wiped on every run** — never point this at real data |
| `CC_TEST_DB_USER` | `root` | Needs `CREATE`, `ALTER`, `DROP` |
| `CC_TEST_DB_PASS` | *(empty)* | |
| `CC_TEST_SKIP_DB` | — | `1` skips the database suites |
| `CC_TEST_SKIP_HTTP` | — | `1` skips the browser-level suite |

The runner starts and stops its own web server, so `php tests/run.php` is the
whole job. It writes a throwaway `config/config.php`, stashing any existing one
and restoring it afterwards.

### Guarding against a green that tested nothing

The most dangerous CI result is success reported by a run that skipped
everything — an unreachable database makes the integration suites vanish, and
the exit code stays 0. Three flags close that:

| Flag | Effect |
|---|---|
| `--require-db` | An unavailable database is a hard failure, not a skip |
| `--require-http` | A server that will not start is a hard failure |
| `--min-tests=N` | Fail unless at least N tests actually ran (ignored when `--filter` is used, which legitimately reduces the count) |

CI passes all three. If you add a suite and the count climbs, raise the
`--min-tests` value in `.github/workflows/ci.yml` — that is the point of it.

## The four layers

### `tests/unit` — no database, no server

Pure functions and classes. Fast enough to run on every save.

"No database" is a contract, not a description: the CI lint job runs this layer
with no MySQL service, and the other two jobs `needs: lint`. One stray
`Settings::set()` in here does not fail its own test — it *errors*, which reads
as a broken build rather than a misplaced test, and it takes the whole pipeline
down. A static gate (`no unit test touches the database`) now enforces it,
because that is exactly how four link-host tests were written into this layer
and had to be moved back out.

- **helpers** — escaping, money, phone normalisation, dates
- **validator** — every rule, and the type-vs-length semantics of min/max
- **router** — pattern compilation and matching, constrained placeholders, and
  request-path resolution: a subdirectory install, a filename inside a route
  parameter, a `//`-prefixed path, traversal, an absolute-form request target
- **security** — encryption round-trips, token entropy, password policy, the ACL catalogue, CSV formula injection
- **mailer** — merge-tag substitution and its escaping, the HTML-to-text
  fallback, the unsubscribe token (round trip, tampering, forgery, and that the
  HMAC actually depends on the install's key), and that a configured `app_url`
  beats the request's Host header. The rest of the link-host precedence falls
  back through stored settings, so it lives in `integration/mailer`.
- **api-resource** — what the REST API exposes, as an allowlist. The failure
  this prevents is nobody's deliberate bug: somebody adds a column — a hash, a
  token, an internal note — and an API built on `SELECT *` starts publishing
  it that afternoon, with no error and no line in any diff that looks like a
  decision. So a field whose NAME looks like a credential fails the build, the
  writable set is always a subset of the readable one, consent flags and
  derived figures are readable and never writable, a paginated listing carries
  no guest prose or network identity, money is emptied rather than dropped,
  timestamps carry their zone, and a cursor refuses anything that is not a
  plain integer.
- **reports** — the report catalogue and the window arithmetic, with today
  passed in rather than read from a clock. The prior window is the same number
  of days immediately before (any overlap and both sides of "+12% on last
  month" contain the same business); the end of a month is not the end of the
  one before it; a delta is NULL with no prior period rather than a 100% rise,
  and its percentage is null when the base is zero. Money scrubbing keeps its
  columns and empties them, CSV money is a plain decimal a spreadsheet can
  total, and a null exports as an empty cell rather than a zero. Plus the SQL
  `LocalDay` emits in each of its three modes, which is what decides whether a
  venue day is a venue day.

### `tests/static` — the quality gates, as executable rules

Spec §13.2 lists rules that are easy to state and easy to forget. A reviewer
cannot reliably hold all of them in their head across 99 tables and 50 routes,
so they are tested:

- No SQL statement interpolates a variable
- Every mutating route carries the `csrf` middleware
- Every `can:` middleware names a permission that exists in `Acl::CATALOG`
- Every non-public route requires `auth`
- Login and password reset are rate limited
- Every route handler class and method exists
- No controller action shadows a base `Controller` helper
- Every view a controller renders exists
- Every schema file uses `{prefix}`, `IF NOT EXISTS`, InnoDB and utf8mb4
- **No schema column could hold card data** (Castle CRM is out of PCI scope by construction)
- Money columns are integers, never floats
- Every seeded automation trigger has an emitter
- No debugging calls, no BOMs, `strict_types` everywhere
- No test in `tests/unit` touches the database (see that layer, above)
- `config/config.php` is gitignored and the sample ships no credentials

Two of the static suites measure rather than inspect:

**`static/contrast`** computes WCAG contrast ratios from the stylesheet's own
token values, for both themes, across every foreground/background pairing the
application actually renders — 4.5:1 for text, 3:1 for the visible boundary of
a control. The stylesheet's header claims it targets AA; this is what makes the
claim checkable. It found three real failures the first time it ran.

**`static/accessibility`** scans every view and installer step: every form
control has a programmatic name, every `<th>` declares a scope, every `<img>`
has alt text, no inline event handlers, no positive tabindex, no destructive
action behind a GET link, and font sizes are relative so a browser's own text
setting still works. The name detector is itself tested against fixtures —
a static gate that cannot fail reads as coverage without being any.

### Don't reset state the server owns — avoid needing to

Two layers run in two processes: the tests here, and the front controller inside
the test server. A test cannot reliably reset state the *server* owns. The
rate limiter proved it twice.

Several routes are rate limited per IP, and the password-reset suite makes ten
POSTs against a 5-per-15-minute limit. It first cleared the counter with
`Cache::flush()`, then by unlinking `storage/cache/*.cache`. Both passed locally
and both left CI answering **429 from the sixth request on**, failing two tests
on all five configurations: the counter lives in whichever cache backend the
server process resolved, and neither call is guaranteed to reach it.

The working answer removes the dependency rather than strengthening it: **each
`TestBrowser` presents its own client address** via `X-Forwarded-For`, which the
throwaway test config trusts (`trust_proxy` is true there and nowhere else).
A browser keeps one address for its life, so a suite that wants to avoid the
limiter takes a fresh browser, and a test that wants to *trip* it reuses one.

Two consequences worth knowing:

- `http/smoke` now covers the limiter properly — that it refuses, that it sends
  `Retry-After`, and that it is per-address rather than global. Nothing covered
  it before, while two comments in the suite claimed something did.
- The server is still started with `apc.enable_cli=0` and an explicit
  `storage_path`, so its cache backend cannot differ between a developer's
  machine and CI. That is about reproducibility, not about the limiter.

### `tests/integration` — against a real database

The schema and seeds run through **their own files**, the same code path the
installer uses, rather than a fixture that could drift from it.

- Schema applies cleanly, is idempotent, creates ~99 tables and 120+ foreign keys
- The dedup and uniqueness guarantees are enforced by the *database*, not by application discipline
- Seeds produce exactly the counts the spec promises, and are idempotent
- Safe Mode defaults to **on** even with no settings row
- Encrypted settings are unreadable in the database
- Authentication: lockout, enumeration resistance, inactive and deleted accounts, hash upgrades
- ACL: owner holds everything, front desk cannot see revenue, a party host is scoped to their own deals, a role-less user holds nothing, escalation is refused
- Transactions roll back, savepoints nest, bound parameters cannot inject

Four more integration suites cover the operator-facing machinery:

- **mailer** — the send path against real rows: Safe Mode redirects to the
  owner and refuses rather than falling through when there is no owner to
  redirect to; a suppressed address is refused even for transactional mail;
  marketing without consent is refused while the same guest's booking
  confirmation is not; `do_not_contact` overrides everything; bulk mail carries
  a working unsubscribe link and the postal address; a CRLF in a subject cannot
  inject a header; and the message row records who the mail was *for*, not
  where Safe Mode sent it
- **backup** — that a dump is importable SQL and not merely non-empty: an
  apostrophe and an injection payload in guest data both round-trip losslessly
  through a replayed INSERT, NULL stays NULL, utf8mb4 survives, a run that
  exceeds its deadline leaves no file at all, and `pathFor` refuses every
  traversal shape
- **seeds** — that no demo guest can ever be contacted (reserved domain,
  reserved phone range), that every demo row is tagged so it can be deleted,
  that seeded consent says in writing it is not real evidence, and that
  deleting the demo contacts leaves no orphans
- **reputation** — the feedback gate against real rows: a promoter is handed a
  tracked review link and an unhappy guest is handed none, with or without the
  venue's "ask everyone" override; the complaint raises a task with a deadline
  whether or not any automation is switched on; a second answer keeps the first
  score and takes the new comment; the invitation sweep never reaches past its
  lookback window, never asks about a party that did not happen, never asks the
  same party twice, and records WHY a guest could not be asked rather than
  reporting a low response rate
- **memberships** — the lifecycle against real rows: a pass sold at the
  counter works at the counter, a visit records ONE use however many times it
  is reported (a family checked in one child at a time is one visit), a family
  member using it counts and not only the holder, freezing gives the frozen
  time back on the end date, renewing early keeps the time already paid for,
  and the nightly sweep is idempotent — a second pass writes nothing and fires
  nothing, or every welcome series would go out once a minute
- **seeds** — the installed state as a contract. Beyond "it seeded": every
  seeded step is one the ENGINE will actually perform, every template a step
  names exists on the right channel, and — the two rules that make a playbook
  worth switching on — **every automation whose trigger can fire has steps**,
  while every automation whose trigger CANNOT fire has none. Both directions
  matter: an empty sequence behind a live trigger runs and performs nothing
  for ever, and an authored sequence behind a dead trigger reads as finished
  for ever. Plus the quietest failure in the application, measured and pinned:
  a `cron` automation with no segment enrols a run with NO CONTACT, and
  `send_email` on one of those records the step as DONE — so the announcement
  goes to nobody and every page says it worked. And the milestone guard: a
  "tenth visit" sequence emails a regular on EVERY visit from their tenth
  unless it guards on its own tag, because `run_once_per_contact` is bypassed
  whenever the trigger names an entity. Twelve visits, one send.
- **reports** — all twenty-three reports run against real rows and return the
  agreed shape, every column type is one the table knows how to draw, and the
  catalogue's `money` flag matches the columns each query really returns (a
  report marked otherwise hands guest spend to a technician). Then the three
  things only a database can catch: that a placeholder is never bound twice
  (`PDO::ATTR_EMULATE_PREPARES` is off, so the second binding is an HY093 and
  a bare 500); that the prior period is read from the prior period; and that
  **a venue day is cut in the venue's timezone** — a nine-in-the-evening
  enquiry is counted on the day it was taken and NOT also on the following
  one, which is what `DATE(created_at)` on a UTC column does to most of a
  Saturday night, every week, in every report. The nightly rollup is covered
  here too: it recomputes rather than accumulating (so a second run writes the
  same answer, and a correction made a week later lands), it reaches back over
  a gap longer than its trailing window rather than leaving a permanent hole,
  it never stores today, and its totals agree with the live report to the cent
- **upgrade** — a pending schema file is applied and recorded on the first
  request after an upload; a failing file leaves the version marker alone so
  the next request retries; and re-running a partially applied file completes
  it
- **tools** — the two CLI scripts, run as real subprocesses: the password
  reset lists accounts without printing hashes, generates a password that
  actually works, ends every live session for the account, applies the same
  password policy as the web form, and is audited; the backup tool writes a
  real dump, prunes, exports CSV to stdout with the count on stderr, and
  refuses an unrecognised flag rather than ignoring it

### `tests/http` — a real browser against a real server

This layer earns its keep: **all three bugs found during Phase 1 lived here**,
and all six found during Phase 2 — including two that made every search in the
application a 500 — and no unit test would have caught any of them.

- Anonymous visitors are redirected; unknown paths 404
- Security headers and a CSP that forbids inline script
- `config/`, `app/`, `storage/` and the schema files are not served
- A POST with a missing or forged CSRF token is refused with 419
- The full permission matrix: what Front Desk can and cannot reach, by route *and* by navigation
- Hiding a link is not security — a denied POST is refused too
- Leaving Safe Mode needs the typed venue name
- The only owner cannot be demoted, deactivated or deleted; the owner role cannot be edited
- Web cron refuses a bad token and does not render a page with a good one
- **No page emits a notice, warning or deprecation** at `E_ALL`

Phase 2 added five module suites — **contacts, deals, quotes, forms and
tasks** — and three habits came out of them that are worth applying to
anything written here next:

- **A list test that never sets a filter does not test the filter.** Both
  search bugs (one named parameter used two and three times, which the native
  driver answers `HY093`) shipped behind a full set of green list tests,
  because every one of them loaded the page with no `q` — so the clause was
  never built and never bound. If a page has a filter, one test has to use it.
- **Assert on a row LINK, not on the text you searched for.** A filter bar
  echoes the term back into `<input name="q" value="…">`, so
  `contains($surname)` passes against a page with no results at all. The
  honest marker is the record's own `/deals/123"` href — with the closing quote,
  or `/deals/5` matches `/deals/51`.
- **Every refusal test needs its positive control in the same file.** Scoping
  a lookup to `1 = 0` would satisfy "this role may not edit that record"
  perfectly; the test that says the same role MAY still edit its own is what
  separates a fix from a door welded shut. Two of these were caught writing
  this phase, one of them by its own positive control failing.

And two further HTTP suites:

- **public** — one-click unsubscribe from a client with no cookie jar at all,
  which is how a mail provider arrives: the RFC 8058 POST works without a CSRF
  token, a second click is harmless, an address with no contact record can
  still unsubscribe, a tampered token unsubscribes nobody, the response is
  identical for an address on file and one that is not, and the page is
  `noindex`
- **reputation** — the guest's side, from a phone with no account: the
  question renders from a tokenised link and is branded with the VENUE rather
  than with Castle CRM, a 10 is shown a review link and a 2 is shown none
  (checked in the page source, not only in the rendered text), the review
  redirect goes to the venue's own configured URL and records the click once,
  an unknown token is the same dead end whether or not it could have existed,
  and the review wall is the one public page here that is indexable, cacheable
  and frameable while the rest are none of those
- **memberships** — the two permissions, in both directions: selling a pass
  is counter work and pricing one is not, so a front desk that can enrol a
  family cannot invent a twelve-pound season pass. Plus every button a manager
  presses — freeze, unfreeze, renew, cancel — driven through the real forms,
  and the rule that a plan nobody has priced is not offered for sale
- **api** — the REST API over the wire, with real tokens and no cookie jar at
  all. Mostly about what it REFUSES, because the API is the easiest place in
  this application to leak what the UI hides: it has no pages anybody reviews,
  and an integration receiving too much will never complain. **A read-only
  token minted BY THE OWNER cannot write** — the one that matters, because the
  owner bypasses every other permission check in the codebase, so an
  implementation that applied abilities after that bypass would make every
  owner-minted token a full-access token whatever its abilities said. Plus: a
  browser SESSION does not authenticate the API (which is what makes the CSRF
  exemption safe); a token dies when it expires, when it is revoked, and when
  its owner is deactivated, without anybody remembering to revoke it; money
  follows `view_revenue` with the keys left in place; a record outside the
  caller's scope is a 404 rather than a 403, because a 403 confirms it exists;
  a refused field is named and NOTHING is written; a repeated visit is the
  same visit; and granting consent needs the disclosure word for word while
  withdrawing it needs nothing at all.
- **reports** — every report renders with no warning and no notice, on every
  preset including the empty ones; and the money gate in both places it has to
  hold. A role with `view_reports` and no `view_revenue` reads every count and
  no dollars, with the column still present and empty so the rows line up with
  what a manager sees — and **the CSV export is scrubbed the same way**, which
  is the hole the obvious implementation leaves, because the export is a
  separate endpoint and gating it on `reports_export` alone hands a technician
  the whole money column in a file. Plus: exporting is its own permission
  (money on screen is not money in a file), an export is audited, and a saved
  report keeps its period BY NAME so "Last 30 days" still means the last 30
  days next month
- **admin** — the operator pages: the stored SMTP password is never rendered
  back into the form and a blank field keeps it; the front desk cannot reach
  email settings, backups or export; a backup taken over HTTP downloads as a
  real gzip stream and the download is audited with the actor; every traversal
  shape on the download route fails to serve a file; lowering the retention
  limit prunes immediately; and force-HTTPS redirects a GET, refuses a POST
  rather than dropping its body, and can be turned off again

### `tests/install` — the product promise

`walk-installer.php` starts from nothing — no config, no tables — and drives
all eight installer steps over HTTP as a person with cPanel and a browser
would. It asserts the refusals as well as the successes: wrong credentials,
an invalid timezone, a short password, a mismatched confirmation. Then it
verifies the installed state (99 tables, 112 automations, **none active**, Safe
Mode on), that the installer locked itself, and that the application actually
works — including signing in as the account the installer created.

## CI

`.github/workflows/ci.yml` runs three jobs:

| Job | What it proves |
|---|---|
| **lint** | PHP and JS syntax, plus the unit and static suites. Fails in seconds. |
| **test** | The full suite on PHP 8.1 / 8.2 / 8.3 against MySQL 8.0, plus MariaDB 10.11 and MySQL 5.7 — the two things a cPanel host actually offers. |
| **install** | The installer walk-through, using an unprivileged database user created the way cPanel would. |

### One thing the runner does that is easy to miss

It flushes the file cache before the HTTP suite. The rate-limit counters live
there, and unlike the database and the throwaway config they survive between
runs — so two runs inside five minutes used to start failing on 429s that
looked exactly like broken authentication. The throttle itself is still
exercised, by the test that deliberately trips it.

The matrix uses `fail-fast: false` so one bad combination does not hide the
others, and installs **only the extensions the installer hard-requires**, so CI
proves the application runs on the minimum it asks for.

## Writing a test

```php
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/thing');

Harness::test('it does the thing', function (): void {
    Assert::same('expected', thing());
});
```

Two conventions worth keeping:

1. **Name the behaviour, not the method.** "the only owner cannot be demoted"
   tells you what broke; "testUpdateUser" does not.
2. **Say why when the reason is not obvious.** The third argument to every
   assertion is a `$because` string, and it is the first thing you read at
   3pm on a Saturday when the venue has a party in twenty minutes.

A test that exists because of a real bug should say so in a comment. Several
here do, and that note is what stops someone "simplifying" the fix away.

### Five habits that came out of real misses

1. **A list test that never sets a filter does not test the filter.** Two
   search bugs shipped behind tests that loaded the page with no query, so the
   clause was never built and never bound.
2. **Assert on a row LINK, not on the text you searched for.** A filter bar
   echoes the term back into its own input, so a title assertion passes
   against an empty list.
3. **Every refusal test needs its positive control beside it.** A controller
   that wrote nothing at all, or a page that answered 404 to everything, would
   satisfy half the refusal tests here perfectly.
4. **A test that changes a global setting must change it back.** Settings are
   shared and the suites run in one process against one database, so a leak
   surfaces as a failure in a DIFFERENT file: `CampaignsTest` turned Safe Mode
   off and left it off, which broke an assertion in `QuotesTest` two suites
   later about an email being redirected. Capture each key's prior value, set
   what the suite needs, and restore at the end of the file — using
   `Settings::forget()` where the prior value was null. `tests/http/
   CampaignsTest.php` and `tests/http/TextClubTest.php` both show the shape.
   Note also that `--filter` is applied per TEST, so every suite file's
   top-level code runs on every run: a restore that sits inside a test does
   not happen when that test is filtered out.
5. **Give each test its own fixture tag, not just each suite.** `CampaignsTest`
   tagged every contact `source = 'camphttp'` and built each segment on that
   tag, so the "nobody is reachable" campaign matched the reachable guests
   seeded two tests earlier and duly sent to them. Every audience figure in
   the file was really counting the whole file. A per-test tag fixed four
   failures at once.

### The development database is MariaDB; CI also tests MySQL

**"The suite is green" means green on the engine you ran it against.** This
repository's development database is MariaDB 10.11, which is one of the four
database jobs in CI — and CI stayed red for three consecutive runs on all
three MySQL jobs while every local run passed, because the failure was a
MySQL/MariaDB difference.

It was a test asserting that a segment's rules came back from the
`segments.rules` JSON column byte-for-byte as they went in. MariaDB's `JSON`
is an alias for `LONGTEXT` and stores the string verbatim; **MySQL 8's `JSON`
is a real type that normalises on storage**, sorting an object's keys by
length and then by name. So `{"field":…,"op":…}` reads back as
`{"op":…,"field":…}` and `Assert::same`, which compares array key order,
failed. Key order carries no meaning in JSON and the engine reads every rule
by key, so the test was asserting the storage engine's behaviour rather than
the application's.

Two things worth knowing from that:

- **Setting MySQL's `sql_mode` on MariaDB does not reproduce it.** That was
  the first thing tried, and the suite stayed green: this is a type
  difference, not a strictness one. MariaDB also does not implement MySQL's
  `DISTINCT` + `ORDER BY` restriction (error 3065) under any mode.
- **Run the real engine before claiming green.** Docker makes this cheap and
  it is the same image CI uses:

      docker run -d --name ccmysql8 -e MYSQL_ROOT_PASSWORD=ci-root-password \
        -e MYSQL_DATABASE=castle_crm_test -p 3307:3306 mysql:8.0

      CC_TEST_DB_HOST=127.0.0.1 CC_TEST_DB_PORT=3307 \
      CC_TEST_DB_NAME=castle_crm_test CC_TEST_DB_USER=root \
      CC_TEST_DB_PASS=ci-root-password \
        php tests/run.php --require-db --require-http

  Worth doing before any push that touches a JSON column, a `GROUP BY`, a
  `DISTINCT`, or anything near a date or a decimal.

### In a Claude Code cloud session, the database builds itself

`.claude/hooks/session-start.sh` is a `SessionStart` hook that installs MariaDB
and prepares `castle_crm_test` before the session starts, so `php tests/run.php`
works with no setup. It is registered in `.claude/settings.json`, does nothing
unless `CLAUDE_CODE_REMOTE=true`, stands down on a self-hosted runner (anything
other than the hosted `cloud_default` environment) unless
`CASTLE_PROVISION_TEST_DB=1` is set, and prints one line naming the engine, the
connection and the `LocalDay` mode it got. Measured: ~18s on a cold container,
~1s on a resumed one, then 1,765 tests passing with nothing skipped.

**The Docker recipe above cannot be used there**, so don't reach for it: the
Docker daemon will not start in a cloud container (`service docker start` fails
with `ulimit: error setting limit (Operation not permitted)`, and `docker info`
then reports no `/var/run/docker.sock`). The hook installs MariaDB from Ubuntu's
archive and starts it by calling `mysqld_safe` directly, because the packaged
service scripts are refused too — `invoke-rc.d` by `policy-rc.d`, and
`/etc/init.d/mysql` by the same ulimit wall as Docker.

Two consequences worth keeping in mind:

- **A cloud session is a MariaDB session.** It is the same engine as local
  development and one of CI's four database jobs, so a green run there means
  exactly what a green local run means and no more. The MySQL-only differences
  above are still invisible until CI runs.
- **The hook never fails the session.** If it cannot deliver a database it exits
  0 and says so loudly on both stdout and stderr, naming
  `storage/logs/session-start-hook.log`. A silent skip would read as a broken
  test suite rather than a missing database, which is why that message exists —
  and why `--require-db` is the honest way to run while it is broken.

One trap is recorded in the hook's own comments and is worth knowing if you ever
set this up by hand: creating `'root'@'127.0.0.1'` is **not** enough. The server
resolves a 127.0.0.1 connection back to `localhost` and matches
`'root'@'localhost'`, which authenticates via `unix_socket` — so the `mysql` CLI
works over its socket while PDO keeps failing with `SQLSTATE[HY000] [1698]
Access denied for user 'root'@'localhost'`. The fix is to switch that account's
plugin:

    ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('');

The hook therefore verifies itself through PDO over TCP rather than through the
CLI, since a passing CLI is precisely the false positive to avoid.

### A green test can sit on top of a 500

Every test in `tests/http/WaiversTest.php` posted to the publish route and
then read the DATABASE to check the outcome. None of them looked at the
response status. The route had been answering **500 on every publish** — after
doing the work, so the database assertions all passed — because
`Audit::diff()` cast an array to a string and the debug error handler turns
that warning into an exception. It fired 657 times in one local application
log while the suite reported 25 passed.

So: **when a POST is meant to succeed, assert on the response as well as on
the row it wrote.** A redirect is the shape of success here; a 500 that has
already committed its transaction looks exactly like success from the
database's side.

### Settings are read through a cache

`Settings` loads once per process. The writes a browser-level test makes
happen in the SERVER'S process, so a bare `Settings::get()` in the test reads
a copy taken before the request that changed it. That silently "proved" a
saved offer calendar had never been written when it had been written
perfectly well. Force a reload (`Settings::load(true)`) before asserting on
anything an HTTP request wrote — `tch_setting()` in
`tests/http/TextClubTest.php` wraps it with the reason attached.

### Say what you are not testing

`tests/http/PublicBookingTest.php` ends its header by naming the one guard it
cannot reach: the `FOR UPDATE` lock the booking write takes over its candidate
rooms. A sequential suite cannot interleave two writes inside one transaction,
so removing the lock leaves the suite green. Writing that down is worth more
than a comment claiming concurrency is covered.

### Mutation-check anything that matters

Every guard added in a phase is checked by breaking it and confirming the
suite goes red. The scripts are throwaway — a backup, a one-line
substitution, a filtered run, a restore — but the result is not: three of the
public booking page's guards had no test that could fail until the sweep said
so, and one of them (the daily allowance refusing a direct POST) was the whole
bound on what a stranger can do to the diary.

Phase 4's sweeps found four more, and the worst of them was guarding the most
important property in the phase. `tests/http/TextClubTest.php` claimed to
prove that an unconfigured inbound webhook refuses everything — the thing
standing between a stranger and a forged JOIN from somebody else's number.
It sent a request signed with *some other* secret, which the HMAC comparison
refuses whether or not the guard exists, so the test passed with the guard
deleted. The real attack is a request signed with the EMPTY secret, and only
that version of the test fails when the guard goes.

**Two kinds of survivor are expected and should be recorded rather than
chased:**

- **Equivalent mutants**, where a second guard downstream produces the same
  outcome. Removing the "a YES with no invite outstanding" early return still
  subscribes nobody, because the "no invite was ever sent" check below it
  refuses too. Keep the guard — it states the rule where a reader looks for
  it — and note in the sweep script why it survives.
- **One layer of a two-layer gate.** Every mutating route carries both a
  route-level `can:` middleware and an in-action `authorize()`, so removing
  either alone is invisible by design. The case that must be caught is
  removing BOTH, and each sweep includes it.

A survivor that is neither of those is a test that is not testing what it
says. Three Phase 4 survivors looked equivalent and were not: each still
refused correctly but changed what the venue or the guest was TOLD — a
never-invited row being informed that an invitation had expired, an empty
coupon-code field being told it should be "3 to 40 letters". Pin the wording
and they die.
