# Castle CRM — machine interfaces

What is callable from outside a browser today, and what is planned.

This document distinguishes the two honestly, because a reference that
describes endpoints which do not exist is worse than no reference: somebody
builds against it, and finds out at integration time.

- **§1–§4 exist now** and are covered by the test suite.
- **§5 is the planned REST API.** The schema for it (`api_tokens`,
  `webhooks`) ships, and nothing is routed yet. Treat the shapes as a
  proposal, not a contract.

---

## 1. Conventions

**Base URL.** Whatever the application is served from, including a
subdirectory. Examples use `https://crm.example.com`.

**Tidy URLs.** Normally rewritten by `.htaccess`. On a host without
`mod_rewrite`, every path also works as a query parameter:
`https://crm.example.com/?_route=/health.json`.

**Content negotiation.** A request that sends `Accept: application/json`,
carries `X-Requested-With: XMLHttpRequest`, or whose path begins `/api/` gets
a JSON response — including for errors. Everything else gets HTML. So an
existing page can be driven from JavaScript without a separate endpoint.

**Error shape.** JSON errors are uniform:

```json
{ "error": "Something went wrong on our side." }
```

A debug install adds `file`. A production install never does.

**Status codes.** `200` success, `302` redirect after a successful form post,
`400` malformed, `401` not signed in, `403` signed in without the permission,
`404` not found, `419` missing or stale CSRF token, `422` validation failed,
`429` rate limited (with `Retry-After`), `503` degraded.

**CSRF.** Every mutating request needs a token, from the `_token` form field
or the `X-CSRF-Token` header. The token is in the page's
`<meta name="csrf-token">`. There is exactly one exception, in §3.

**Rate limits.** Per IP, per path. The limit is part of the route definition
in `app/routes.php`, which is the authoritative list.

---

## 2. Health probe

```
GET /health.json
```

Unauthenticated, rate limited to 30 requests per minute. For an uptime
monitor.

```json
{
  "status": "ok",
  "database": true,
  "cron_fresh": true,
  "time": "2026-10-15T14:03:11+00:00"
}
```

`200` when healthy; **`503` when degraded**, so a monitor that only watches
the status code still works. Degraded means the database is unreachable or
cron has not run recently.

Deliberately sparse. It reveals liveness and nothing else: no version, no
paths, no record counts. An unauthenticated endpoint that reports how many
contacts a venue has is a business-intelligence leak, and one that reports a
component version is a vulnerability-scanning aid.

Point your monitor here, not at `/login` — a login page returning 200 proves
only that PHP is running, not that the database is answering.

---

## 3. Unsubscribe (RFC 8058)

```
GET  /u/{token}
POST /u/{token}
```

Unauthenticated, rate limited to 60 requests per 5 minutes. The token is an
HMAC of the recipient's address and contact id, derived from the install's
`app_key`, so links cannot be enumerated or forged and need no stored row.

`GET` is a human clicking the footer link, and returns a confirmation page.
`POST` is **RFC 8058 one-click**: a recipient's mail provider submits it on
their behalf, which is why it is the one mutating route in the application
exempt from CSRF — there is no session for a token to live in, and the signed
token *is* the authorisation. Gmail and Yahoo both require this of bulk
senders.

Every marketing email carries the headers:

```
List-Unsubscribe: <https://crm.example.com/u/bWFyaXNvbEBleGFtcGxlLmNvbXww...>
List-Unsubscribe-Post: List-Unsubscribe=One-Click
```

Both verbs are idempotent, so a mail client that prefetches links does no
harm, and both respond `200` whether or not the address was already
unsubscribed. The response is identical for an address that is on file and one
that is not: otherwise the endpoint becomes an unauthenticated oracle for "is
this person one of your customers?".

Responses carry `X-Robots-Tag: noindex, nofollow, noarchive` and no-cache
headers. An indexed unsubscribe URL is an unsubscribe anybody can trigger.

---

## 4. Cron trigger

```
GET /cron.php?token={cron_token}
```

For hosts with no CLI cron. The token is generated at install and printed on
the installer's last page; it is in `config/config.php` as `cron_token`.

With no token configured, web cron is **disabled entirely** rather than open
to anyone who guesses the URL. Compared with `hash_equals`, so a wrong token
does not leak its correctness through timing.

Returns plain text describing what the run did, and exits non-zero on error:

```
Castle CRM cron starting (budget 50s).
  Daily maintenance complete.
  Backup: castle-crm-20261015-030000.sql.gz (48213 rows).
Done in 4.21s. Jobs: 3, automations due: 0, maintenance tasks: 6, errors: 0.
```

Safe to call every minute. A lock file prevents overlapping runs, each run
works to a wall-clock budget and stops cleanly, and a run with nothing to do
costs one query. Call it more often than once a minute and the extra calls
exit immediately on the lock.

Prefer real cron where it exists: a web trigger depends on an external service
staying subscribed, and silent cron failure is the most damaging failure this
application has.

---

## 5. Planned: the REST API

**Not implemented.** The tables exist; no routes do. Written down here so the
shape is agreed before anything depends on it.

### Authentication

A bearer token, created per user, hashed at rest (`api_tokens.token_hash`),
with optional abilities and an expiry:

```
Authorization: Bearer cc_live_8f3a...
```

A token never grants more than the user who owns it. The permission system is
the same one the web interface uses, so an API token held by a front-desk
account can look up one guest and cannot export the list — the API surface
cannot become a way around the roles.

### Resources

Read and write, mirroring the modules:

| Resource | Methods | Notes |
|---|---|---|
| `/api/v1/contacts` | GET, POST, PATCH | consent fields are writable only with evidence |
| `/api/v1/contacts/{id}` | GET, PATCH, DELETE | DELETE is a soft delete |
| `/api/v1/deals` | GET, POST, PATCH | |
| `/api/v1/bookings` | GET, POST, PATCH | |
| `/api/v1/bookings/{id}/payments` | GET, POST | an opaque reference only; never card data |
| `/api/v1/availability` | GET | the query a website booking widget needs |
| `/api/v1/messages` | GET, POST | POST goes through the same consent and Safe Mode checks |
| `/api/v1/tasks` | GET, POST, PATCH | |
| `/api/v1/reports/{slug}` | GET | |

Two rules that will not be relaxed:

1. **Writing a consent flag requires the evidence with it** — the source and
   the disclosure wording. An API that lets a caller set `email_consent = 1`
   with no record of what the guest agreed to is a liability, not a feature.
2. **`POST /messages` is not a bypass.** It goes through `Mailer::send()`, so
   suppression, consent, the frequency cap, quiet hours and Safe Mode all
   still apply. An integration cannot send what the application would refuse
   to send.

### Shapes

Money is always **integer cents**, never a float and never a formatted string:

```json
{
  "id": 4182,
  "total_cents": 54900,
  "currency": "USD",
  "event_date": "2026-11-14",
  "created_at": "2026-10-15T14:03:11+00:00"
}
```

Timestamps are UTC ISO 8601. Dates with no time are `YYYY-MM-DD`. Lists are
cursor-paginated:

```json
{
  "data": [ ... ],
  "meta": { "next_cursor": "eyJpZCI6NDE4Mn0", "has_more": true }
}
```

Cursors, not page numbers: a venue's contact list changes while you are
paging through it, and an offset silently skips rows when one is inserted
above the window.

### Webhooks

Outbound, one row per event subscription (`webhooks`), with deliveries and
their responses recorded (`webhook_deliveries`). Signed with an HMAC of the
body in a header, so a receiver can verify the call came from the venue's
install:

```
X-Castle-Signature: sha256=...
X-Castle-Event: booking.deposit_paid
X-Castle-Delivery: 01JAV...
```

Retried with backoff; the delivery log is what makes "did that fire?"
answerable.

### What will not be in the API

- No endpoint that returns a password hash, an API token, or a provider
  credential. Not even to an owner.
- No endpoint that returns card data, because none is stored.
- No endpoint that disables Safe Mode. That is a decision a person makes in
  the interface, with the confirmation and the audit entry.

---

## 6. Alongside the API: the existing exports

While §5 is unbuilt, two routes already move data out, and for an integration
that only needs a periodic copy they may be enough.

```
GET /settings/backups/export?table=contacts
```

Streams a table as CSV. Needs a signed-in session with `backup_manage`. The
table must be on the allowlist in `App\Core\Backup::exportableTables()` —
an allowlist rather than a denylist, so the next sensitive table somebody adds
is not exposed by default. Every export is audited with the actor, the table
and the row count.

```
php tools/backup.php --export=contacts > contacts.csv
```

The same thing from a shell, for a scheduled sync. CSV on stdout, the row
count on stderr, so piping to a file keeps the data clean.

Both write a UTF-8 BOM, because Excel reads a bare UTF-8 CSV as Windows-1252
and mangles every accented name, and both prefix any cell starting `=`, `+`,
`-` or `@` with an apostrophe — a guest's "name" should not execute as a
formula on a staff machine.

---

## 7. Reading the authoritative list

`app/routes.php` is the route table, and it is written to be read: every URL
the application answers, with the permission that guards it and the rate limit
applied to it, in one file. When this document and that file disagree, the file
is right.

The test suite enforces three properties of it, so a new route cannot quietly
be unsafe:

- every mutating route is CSRF-protected, bar a documented exemption list
  containing exactly one entry (§3);
- every `can:` middleware names a permission that exists in the catalogue, so
  a typo throws rather than silently granting access;
- every authenticated route actually requires authentication.
