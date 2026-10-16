# Castle CRM — machine interfaces

What is callable from outside a browser today, and what is planned.

This document distinguishes the two honestly, because a reference that
describes endpoints which do not exist is worse than no reference: somebody
builds against it, and finds out at integration time.

- **§1–§5 exist now** and are covered by the test suite.
- **§5's "Webhooks" is the one part still planned.** The schema for it
  (`webhooks`, `webhook_deliveries`) ships and nothing is routed yet; treat
  that shape as a proposal, not a contract.

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

## 5. The REST API

`/api/v1/*`. Implemented, routed and covered by the test suite.

### Authentication

A bearer token, created per user in **Settings → API tokens**, hashed at rest
(`api_tokens.token_hash` holds a SHA-256; the plaintext is shown once and
never stored):

```
Authorization: Bearer cc_live_8f3a...
```

`X-Api-Key: cc_live_...` is accepted as well, for hosts that strip
`Authorization`. The token never appears in a query string: a credential in a
URL ends up in access logs, in the `Referer` of every outbound link, and in
browser history.

**A token never grants more than the user who owns it, and its abilities only
narrow.** The permission system is the one the web interface uses, so a token
held by a front-desk account looks up one guest and cannot export the list.
Ticking abilities when you create a token narrows it further — the
intersection, never the union. An owner minting a read-only token really does
get a read-only token, which is asserted by a test precisely because the owner
bypasses every other permission check in the codebase.

A token stops working the moment it is revoked, the moment it expires, and the
moment its owner is deactivated or deleted. Nobody has to remember to revoke
the tokens of somebody who leaves.

**No session cookie authenticates this API.** A route that accepted both would
be forgeable from any logged-in tab, because the API has no form to carry a
CSRF token in. A static gate asserts the `api` and `auth` middleware never
appear on the same route.

**On cPanel**, Apache does not pass the `Authorization` header to PHP unless
told to. The shipped `.htaccess` tells it to, two ways. If every token comes
back "not valid" while you can see you sent one, a host overriding that file
is the usual cause.

### Discovery

```bash
curl https://crm.example.com/api/v1 \
  -H "Authorization: Bearer cc_live_..."
```

```json
{
  "version": "v1",
  "resources": {
    "contacts": { "path": "/api/v1/contacts", "readable": true, "writable": true,
                  "fields": ["id", "first_name", "..."] }
  },
  "you": { "user_id": 1, "name": "Owner", "role": "owner", "revenue": true }
}
```

It lists only what THIS token can reach, resolved against the owner's current
role — so an integration can check itself rather than guessing, and a token
that lost access because its user was demoted says so.

### Resources

| Resource | Methods | Notes |
|---|---|---|
| `/api/v1/contacts` | GET, POST, PATCH, DELETE | DELETE is a soft delete |
| `/api/v1/contacts/{id}/consent` | POST | consent with its evidence — see below |
| `/api/v1/households` | GET, POST, PATCH | |
| `/api/v1/companies` | GET, POST, PATCH, DELETE | |
| `/api/v1/deals` | GET, POST, PATCH, DELETE | won/lost are transitions, not a column |
| `/api/v1/bookings` | GET, POST, PATCH, DELETE | money is derived from the payment ledger |
| `/api/v1/visits` | GET, POST | POST goes through the visit recorder |
| `/api/v1/memberships` | GET | read-only: every change is a lifecycle transition |
| `/api/v1/tasks` | GET, POST, PATCH | |
| `/api/v1/conversations` | GET | |
| `/api/v1/messages` | GET | read-only; bodies on the single-record read only |
| `/api/v1/form-submissions` | GET | |
| `/api/v1/reviews` | GET | |

A resource that refuses a write answers **405** with the reason, not 500.

### Examples

List, newest first:

```bash
curl "https://crm.example.com/api/v1/contacts?per_page=25" \
  -H "Authorization: Bearer cc_live_..."
```

Search, and sync incrementally:

```bash
curl "https://crm.example.com/api/v1/contacts?q=byrne" -H "Authorization: Bearer ..."
curl "https://crm.example.com/api/v1/contacts?updated_since=2026-10-16T00:00:00Z" \
  -H "Authorization: Bearer ..."
```

`updated_since` is what an incremental sync needs; without it every poll is a
full table read. An unparseable value is **422**, never silently ignored — an
ignored filter returns the whole table and the integration re-imports
everything on every poll without knowing why.

Create and update:

```bash
curl -X POST https://crm.example.com/api/v1/contacts \
  -H "Authorization: Bearer ..." -H "Content-Type: application/json" \
  -d '{"first_name":"Dana","last_name":"Byrne","email":"dana@example.test","source":"Website"}'

curl -X PATCH https://crm.example.com/api/v1/contacts/4182 \
  -H "Authorization: Bearer ..." -H "Content-Type: application/json" \
  -d '{"city":"Chester","state":"NY"}'
```

A field that cannot be written is **refused with a 422 naming it**, and
nothing is written — not quietly dropped. Dropping silently is how an
integration spends a morning wondering why its PATCH had no effect.

Record a visit:

```bash
curl -X POST https://crm.example.com/api/v1/visits \
  -H "Authorization: Bearer ..." -H "Content-Type: application/json" \
  -d '{"contact_id":4182,"visit_date":"2026-10-15","source":"check_in","spend_cents":4200}'
```

**Idempotent by the day.** A guest already recorded on that date comes back
`201`→`200` with `"recorded": false`, the same visit id, and a note. A family
checked in one child at a time is one visit, and a retried batch does not give
anybody eleven.

### Consent

The one write that is not a PATCH, because a consent flag with no record of
what the guest agreed to is a liability rather than a feature:

```bash
curl -X POST https://crm.example.com/api/v1/contacts/4182/consent \
  -H "Authorization: Bearer ..." -H "Content-Type: application/json" \
  -d '{"channel":"email","granted":true,"source":"Party enquiry form",
       "disclosure":"I agree to receive email from The Castle Fun Center."}'
```

**Granting needs the disclosure, word for word. Withdrawing needs nothing** —
a guest saying stop is honoured unconditionally, and refusing to record that
because a field was missing would be the worst possible reading of a
compliance rule. Both write `consent_log` with the evidence, the IP and the
token that did it.

### Shapes

Money is always **integer cents**, never a float and never a formatted string:

```json
{
  "id": 4182,
  "total_cents": 54900,
  "event_date": "2026-11-14",
  "created_at": "2026-10-15T14:03:11Z"
}
```

Timestamps are UTC ISO 8601 **with the Z**: without a zone, most client
libraries read them as local time, which puts every timestamp four or five
hours out at this venue — consistently enough that nobody notices for a while.
Dates with no time are `YYYY-MM-DD`. Ids are integers, not strings.

**Money follows `view_revenue`**, as on every page. A token whose user cannot
see revenue gets the money fields PRESENT and `null`, with
`meta.money_hidden: true` — the key stays, because a key that vanishes reads
as "this record has no total" and a null reads as "you were not shown one".

Lists are cursor-paginated:

```json
{
  "data": [ ... ],
  "meta": { "has_more": true, "next_cursor": "NDE4Mg", "per_page": 25,
            "money_hidden": false }
}
```

Cursors, not page numbers: a venue's contact list changes while you page
through it, and an offset silently skips rows when one is inserted above the
window. Treat the cursor as opaque. `per_page` is capped at 100 — asking for
100000 gets you 100, not an outage.

### What is deliberately NOT exposed

| Absent | Why |
|---|---|
| Message bodies in the **listing** | A paginated listing is a bulk export of a venue's correspondence wearing a different hat. The single-message read returns the body. |
| Form answers, IPs, user agents | The guest's own words and their network identity. |
| `payment_reference` | An opaque processor reference with no use outside the application. |
| Password hashes, PINs, tokens | A unit test fails the build if a field whose name looks like a credential ever appears in a resource definition. |
| Derived figures as WRITABLE | `visit_count`, lifetime value, a booking's totals. Writing one puts a number on the page that no record supports, and the next recalculation contradicts the caller. |

Every resource declares an **allowlist** of fields. A column added by a future
migration is not published until somebody adds it to that list — which is the
whole reason the list exists.

### Errors and limits

`401` no or bad token (always JSON, never a redirect to a login page), `403`
the token lacks a permission (and says which), `404` unknown resource or a
record outside the caller's scope, `405` write to a read-only resource, `422`
validation or a refused field, `429` rate limited.

**A record outside your scope is a 404, not a 403.** A 403 confirms the record
exists, which is how an id space gets walked.

Reads are throttled at 600/minute per IP, writes at 300, deletes at 120.

### Webhooks — planned

Outbound, one row per event subscription (`webhooks`), with deliveries and
their responses recorded (`webhook_deliveries`). Signed with an HMAC-SHA256 of
the raw body in `X-Castle-Signature`, timestamped against replay, retried with
exponential backoff, with a delivery log and manual replay.

**Not built yet.** The tables ship; no routes do.

---

## 6. Alongside the API: the existing exports

Two routes already move data out in bulk, and for an integration that only
needs a periodic copy they are simpler than paging the API.

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
