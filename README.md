# Castle CRM

A self-hosted revenue-operations platform for **The Castle Fun Center**, Chester NY.

Castle CRM runs the work *around* a guest visit — the party inquiry, the group
quote, the confirmation, the waiver, the review request, the win-back text —
while CenterEdge keeps running the transaction, the Castle Card, and the
confirmed party reservation.

Built in vanilla PHP, MySQL and hand-written CSS/JS. **No Composer, no npm, no
build step.** It installs on ordinary cPanel shared hosting through a web
installer, and the only server configuration it needs is one cron line.

## Why

An FEC does not lose revenue from one missed email. Revenue leaks at the
handoffs:

| Handoff | Typical failure | Castle CRM's answer |
|---|---|---|
| Inquiry → quote | A birthday email sits in a shared inbox over a weekend | The lead lands in a pipeline with an owner, a response deadline, and an automated first touch |
| Quote → booking | No follow-up after the price is sent | A timed nurture sequence; stale-deal alerts to the manager |
| Booking → visit | The family arrives without waivers, staff without a run sheet | Confirmation, waiver and reminder cadence; a printable run sheet |
| Visit → reputation | A great party, and no review | A feedback gate: 4–5★ routed to Google, 1–3★ routed to a manager |
| Visit → next visit | One generic blast to everybody | Segments driven by attraction interest, spend, and each child's birth month |
| Any of the above | Nobody knows it broke | Dashboards, manager digests, accountability reporting |

## Status

| Phase | Scope | State |
|---|---|---|
| 0 | [Specification](docs/SPEC.md) | ✅ |
| 1 | Foundation: installer, 99-table schema, auth, RBAC, settings, cron, UI shell | ✅ |
| 2 | Contacts, households, pipelines, deals, quotes, forms, tasks | ✅ |
| 3 | Bookings: availability, packages, run sheets, waivers, check-in | ✅ |
| 4 | Email and SMS, inbox, segments, campaigns, Text Club | ✅ |
| 5 | The automation engine and the 112 seeded automations | done |
| 6 | Reputation, memberships, the reporting suite | |
| 7 | CenterEdge import, Google Business Profile, webhooks, REST API | |
| 8 | Hardening: security review, accessibility audit, load test, backup/restore | |

Phases 1 to 4 are verified end to end against real MySQL 8.0, MariaDB 10.11 and MySQL
5.7: **1,129 tests** plus a full installer walk-through. See
[docs/TESTING.md](docs/TESTING.md).

## Install

1. Upload the folder to `public_html/` (or a subdomain root).
2. Create a database and user in **cPanel → MySQL Databases**.
3. Visit `/install/` and follow eight steps.
4. Paste the cron line it gives you into **cPanel → Cron Jobs**.
5. Add the SPF/DKIM/DMARC records it gives you — or just click **Repair** in
   **cPanel → Email Deliverability**.
6. Delete the `install/` folder.

The long version, written for somebody who has only ever used cPanel to upload
files, is [docs/INSTALL.md](docs/INSTALL.md) — including backups, restoring,
and what to do when nobody can sign in.

Requirements: PHP 8.1+, MySQL 5.7.8+/MariaDB 10.3+, and the extensions
`pdo_mysql`, `mbstring`, `json`, `openssl`, `curl`, `fileinfo` — all standard.
The installer checks each one and names the cPanel screen that fixes it.

### Nothing will message a guest by accident

A finished install is in **Safe Mode**: every email and text is redirected to
the owner's own address, every seeded playbook is off, and all 112 automations
are inactive. Leaving Safe Mode requires a distinct permission and typing the
venue name to confirm. There is also a global sending pause reachable in two
clicks from any page.

## The eight revenue playbooks

A playbook is a ready-built bundle — pipeline, forms, templates, automations,
reports — activated as one unit.

| # | Playbook | The revenue moment |
|---|---|---|
| 01 | Birthday & Celebration Sales | A parent asks about a party |
| 02 | Group & Corporate Events | A school, camp, corporate planner or Event Center enquiry |
| 03 | Visit & Ticketing Automation | A guest buys tickets or loads a Castle Card |
| 04 | Reputation Protection | A visit just ended |
| 05 | Membership & Season Pass Lifecycle | A guest holds, under-uses, or lapses a pass |
| 06 | Text Club & Repeat Visits | A guest opts in to SMS |
| 07 | Sales Accountability | A lead has gone quiet |
| 08 | Fundraising, Donations & Referrals | A nonprofit, school or guest asks |

## How it relates to CEplay

The Castle already runs [CEplay](https://ceplay.thecastlefuncenter.com) for
arcade automation and POS analytics. The two are siblings, not layers:

> **CEplay** answers *"how is the venue performing?"*
> **Castle CRM** answers *"who is the guest, and what happens next?"*

CenterEdge Advantage remains the system of record for the card, the money and
the confirmed reservation. Castle CRM owns the inquiry, the quote, the
follow-up, consent, and the guest relationship — none of which exist anywhere
today. [Spec §3.8](docs/SPEC.md) draws the line precisely.

## Documentation

- [docs/INSTALL.md](docs/INSTALL.md) — installing, upgrading, backups, recovery
- [docs/SPEC.md](docs/SPEC.md) — product and technical specification
- [docs/PRIVACY.md](docs/PRIVACY.md) — what data is held, consent, retention,
  and what the software refuses to do
- [docs/API.md](docs/API.md) — the machine interfaces that exist today, and
  the planned REST API
- [docs/TESTING.md](docs/TESTING.md) — the test suite and CI
- [docs/CHANGELOG.md](docs/CHANGELOG.md) — what changed, and the bugs the
  tests found

## Development

```bash
php -S 127.0.0.1:8000 -t . tools/dev-server.php   # serve locally
php tests/run.php                                  # full suite
php tests/run.php --unit                           # no database needed
php cron.php                                       # run scheduled work once
```
