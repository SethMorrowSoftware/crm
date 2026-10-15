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
php tests/run.php --require-db --require-http --min-tests=200

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

- **helpers** — escaping, money, phone normalisation, dates
- **validator** — every rule, and the type-vs-length semantics of min/max
- **router** — pattern compilation and matching, including constrained placeholders
- **security** — encryption round-trips, token entropy, password policy, the ACL catalogue, CSV formula injection

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
- `config/config.php` is gitignored and the sample ships no credentials

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

### `tests/http` — a real browser against a real server

This layer earns its keep: **all three bugs found during Phase 1 lived here**,
and no unit test would have caught any of them.

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

### `tests/install` — the product promise

`walk-installer.php` starts from nothing — no config, no tables — and drives
all eight installer steps over HTTP as a person with cPanel and a browser
would. It asserts the refusals as well as the successes: wrong credentials,
an invalid timezone, a short password, a mismatched confirmation. Then it
verifies the installed state (99 tables, 111 automations, **none active**, Safe
Mode on), that the installer locked itself, and that the application actually
works — including signing in as the account the installer created.

## CI

`.github/workflows/ci.yml` runs three jobs:

| Job | What it proves |
|---|---|
| **lint** | PHP and JS syntax, plus the unit and static suites. Fails in seconds. |
| **test** | The full suite on PHP 8.1 / 8.2 / 8.3 against MySQL 8.0, plus MariaDB 10.11 and MySQL 5.7 — the two things a cPanel host actually offers. |
| **install** | The installer walk-through, using an unprivileged database user created the way cPanel would. |

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
