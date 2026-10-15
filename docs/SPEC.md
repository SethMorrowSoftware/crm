# Castle CRM — Product & Technical Specification

**Version:** 1.1 (draft — revised after reviewing the CEplay codebase)
**Date:** 2026-10-15
**Owner:** The Castle Fun Center — Chester, NY
**Status:** Approved for Phase 1 implementation

---

## 1. Executive summary

Castle CRM is a self-hosted, **revenue-operations platform** for The Castle Fun Center: a
single system that runs the *work around* a guest visit — the party inquiry, the group
quote, the confirmation, the waiver, the review request, the win-back text — while the
existing point-of-sale keeps running the transaction.

It is modelled on [FEC Playbook](https://fecplaybook.com/), a commercial SaaS product for
family entertainment centers, but re-architected as a **single-tenant application that
installs on an ordinary cPanel shared host** via a web-based installer, using only PHP,
MySQL, and vanilla HTML/CSS/JS.

Castle CRM is not the Castle's first in-house system. The venue already runs **CEplay**
(`ceplay.thecastlefuncenter.com`), a PHP/SQLite application that automates the CenterEdge
arcade card system and reports on the POS database. CEplay establishes the venue's real
technical facts — CenterEdge Advantage as the POS, a read-only MSSQL reporting path, a
deferred-value Castle Card, ~2 decades of card-ledger history — and a house style Castle
CRM deliberately matches. §3.7 and §3.8 record those facts and draw the boundary between
the two applications; getting that boundary right is the single most important design
decision in this document, because almost everything Castle CRM wants to know about a
guest already exists somewhere in CenterEdge.

### 1.1 Why this exists

An FEC does not lose revenue from one missed email. Revenue leaks at the **handoffs**:

| Handoff | Typical failure | Castle CRM's answer |
|---|---|---|
| Inquiry → quote | Birthday email sits in a shared inbox over a weekend | Lead lands in a pipeline with an owner, an SLA timer, and automated first touch |
| Quote → booking | No follow-up after the price is sent | Timed nurture sequence; stale-deal alerts to the manager |
| Booking → visit | Family arrives without waivers, staff without a run sheet | Confirmation + waiver + reminder cadence; printable run sheet |
| Visit → reputation | Great party, no review | Post-visit feedback gate; 4–5★ routed to Google, 1–3★ routed to a manager |
| Visit → next visit | One generic blast to everyone | Segmented campaigns driven by attraction interest, spend, and child birth month |
| Any of the above | Nobody knows it broke | Dashboards, manager digests, and accountability reporting |

### 1.2 Design principles

1. **POS-adjacent, not POS-replacing.** Castle CRM never tries to own the cash drawer,
   the Castle Card balance, or the arcade reader. It ingests from them and acts around them.
2. **Pre-built, not blank.** A fresh install ships with the Castle's attractions, party
   packages, pipelines, templates, and automations already seeded. Day-one value, not a
   blank canvas.
3. **Shared-host native.** No Composer, no Node build step, no daemons, no shell access
   required. One cron line is the only server-side configuration.
4. **Vanilla and legible.** No framework. A junior developer should be able to read any
   file top-to-bottom and understand it.
5. **Compliance is a feature.** TCPA consent, CAN-SPAM, 10DLC, and minors' waivers are
   modelled in the schema, not bolted on.

### 1.3 Non-goals

- Multi-tenant SaaS (single venue per install; multi-location is a Phase 5 consideration)
- Payment processing (Castle CRM stores deposit *status*, not card data — see §12.4)
- Replacing the POS, the arcade card system, or the online ticketing checkout
- A native mobile app (the UI is responsive; that is sufficient)
- Accounting / payroll / inventory

---

## 2. Reference analysis: FEC Playbook

The reference site could not be fetched directly (the build environment's network policy
blocks outbound HTTP). The analysis below is drawn from the product's **own public source
repository**, `github.com/BookMoreParties/fec-playbook-website`, which contains the live
marketing site plus its internal strategy, audit, and copy documents — a more precise
source than the rendered page.

> Treated as competitive reference material only. No code, copy, or asset from that
> repository is reproduced in Castle CRM.

### 2.1 Their canonical offer architecture

The site's own SEO audit defines a three-layer taxonomy, which is a genuinely good model
and the one Castle CRM adopts:

| Layer | Role | Definition |
|---|---|---|
| **8 Revenue Playbooks** | Outcome systems | Ready-built systems for a specific revenue moment |
| **13 Core Modules** | Capability layer | The tools that make playbooks run: inbox, forms, pipelines, automations, email, SMS, reporting |
| **100+ Pre-Built Automations** | Activation layer | Triggers, sequences, handoffs, reminders inside the playbooks |
| **Activation support** | Service layer | Adapting the system to the venue's brand, team, and data |

The insight worth copying: **playbooks are the unit of value; modules are the unit of
engineering.** A buyer thinks "I need my birthday follow-up fixed," not "I need a
workflow engine." Castle CRM keeps both vocabularies and maps one onto the other (§4).

### 2.2 Their eight systems (verbatim, from their usage-pricing page)

1. Birthday & Celebration Sales System
2. Group & Corporate Event Engine
3. Ticketing & Visit Automation
4. Reputation Protection System
5. Season Pass & Membership Lifecycle
6. Text Club & Repeat Visit Engine
7. Internal Sales Accountability
8. Fundraising & Referral Systems

### 2.3 Their module capabilities (observed)

- Unified conversation inbox — email, SMS, web chat, social DM, Google Business Profile
- Sales pipelines with assigned ownership and visible next step
- Web forms installed on the venue's live site
- Email + SMS campaigns, segmentation, scheduling
- "Text Club" — SMS list growth with recurring monthly coupon offers
- Review request timing, AI-assisted reply drafting, GBP visibility
- Social post scheduling
- Task ownership and shared event detail views
- Performance dashboard: sales, marketing, customer insights
- Manager digests and stale-opportunity alerts
- AI content generation and AI conversation replies

### 2.4 Their commercial model (what we deliberately do *not* copy)

FEC Playbook meters usage: per-SMS in/out, per-email, per-call-minute, per-AI-reply, per
workflow execution, plus A2P 10DLC registration and carrier compliance fees, on top of a
platform fee.

Castle CRM is **owned software**. The Castle pays only its own provider costs (Twilio,
SMTP) at cost. Castle CRM still *meters* usage — because the Castle should be able to see
what a campaign costs before sending it — but there is no markup and no platform fee. The
usage ledger (§8.7) is a budgeting tool, not a billing engine.

### 2.5 Gaps in the reference we intend to close

Their own internal audit flags several problems. Castle CRM avoids each:

| Their issue | Castle CRM's position |
|---|---|
| Inconsistent counts across pages (8 vs 10 playbooks, 13 vs 13+ modules) | One canonical registry in the database; the UI counts rows |
| "No third-party tools needed" overclaim | Explicit integration surface; honest about what is adjacent |
| Marketing site only — the product is a configured GoHighLevel-style stack | Castle CRM is the actual application, owned outright |
| Usage fees on every message and workflow run | At-cost provider pass-through, no metering markup |
| Memberships appear in pricing but were pulled from the nav | Memberships are a first-class module |

---

## 3. The Castle Fun Center: venue context

Castle CRM ships seeded with the Castle's real operating shape, so the install is useful
immediately rather than after a week of data entry.

### 3.1 Attractions (seed data)

**Indoor, year-round:** Bowling, Roller Skating, Laser Tag (multi-level), Ballocity
(two-level foam ball play), InflataPark, Arcade, Rock Climbing Wall, Axe Throwing,
Free Fall, Event Center.

**Outdoor, seasonal:** Go Karts, Zip Line, Mini Golf (two 18-hole courses), Swing Ride,
Dragon Coaster, Bumper Boats.

Each attraction row carries: name, indoor/outdoor, seasonality window, age/height
restriction, capacity per slot, slot duration, whether it is party-eligible, and whether
it is a valid party *main activity*.

### 3.2 Admission & payment model

Admission is **free**; each activity is priced separately and paid from a **Castle Card**
loaded at a POS register or a self-service kiosk.

Two consequences shape the whole design:

1. **There is no gate scan.** Nothing in the venue emits "a guest arrived", so there is no
   native visit event to hang automation on. Castle CRM has to *derive* a visit — see §8.3.
2. **The Castle defers card value.** Loading a card is stored value, not a sale, so a load
   never appears in the POS `Sales` table; it exists only in the card ledger
   (`PlayerCardTrans`). Any revenue figure that ignores this undercounts the kiosks badly,
   because card value is most of what a kiosk sells. CEplay learned this the hard way and
   Castle CRM inherits the lesson: **loads and goods are counted separately and never
   added together without saying so.**

### 3.3 Party packages (seed data)

| Package | Base | Includes | Additional child |
|---|---|---|---|
| Roller Skating Birthday | $349 | up to 8 kids | $26.99 |
| Laser Tag Birthday | $349 | up to 8 kids | $26.99 |
| Mini Golf Birthday (seasonal) | $349 | up to 8 kids | $26.99 |
| Bowling Birthday | $399 | up to 8 kids | $29.99 |

Parties run ~2 hours, start with a **main activity**, and allow add-on attractions.
Online booking requires **7+ days notice**; inside 7 days the family must call
(845) 469-2116. Castle CRM encodes this as a booking-policy rule that drives both form
validation and the "call us" fallback automation.

### 3.4 Group & event business

Group Events, School Trips (end-of-year class celebrations; recurring schools from NYC,
New Jersey, and the Hudson Valley), Corporate Events, Camps, Field Trips, Class Trip
Packages, plus the **Event Center** for meetings, company picnics, weddings, and social
gatherings.

Implication: the group pipeline has a **long planning cycle** (a school books in March
for June) and **annual recurrence**. Castle CRM models `recurring_group` relationships so
last June's teacher gets a February re-book nudge.

### 3.5 Operating calendar

Hours are seasonal and attraction-dependent (e.g. a shoulder-season Wednesday may run
3–8pm with only bowling, axe throwing, Free Fall, laser tag, rock wall, and arcade;
weekends open 11am). Castle CRM stores an **operating-hours calendar with
attraction-level availability overrides**, because a party quote must not offer go-karts
in February.

### 3.6 Geography

Chester, NY (Orange County). Draw area: Hudson Valley, NYC metro, northern New Jersey.
Nearest rail is Harriman Station (Metro-North Port Jervis line), ~11.5 miles.
Relevance: ZIP-based segmentation, drive-time radius reporting, and school-district
targeting for field trips.

> **Verification note.** §3.3's prices and §3.5's hours were gathered from the venue's
> public website via search and may be stale. They are seeded as *editable defaults*, and
> the installer's final step prompts an operator to confirm them. No business logic
> depends on a hard-coded price.

### 3.7 Existing systems (confirmed from the CEplay codebase)

| System | What it is | Castle CRM's relationship |
|---|---|---|
| **CenterEdge Advantage** | The POS, card system, and — importantly — the **party/event booking and deposit system of record**. Evidence: a dedicated "Birthday Parties" register (station 60), party-desk stations (SueDesktop, 260), `Receipt.EventType ≠ 0` for pre-event party and group payments, and tender type 9 = *Party Deposit*. | Read from it. Never the authority on a confirmed booking or a payment. |
| **CenterEdge card REST API** | Kiosoft-derived card API: SHA-1 auth with token caching, paginated. Cards, balances, transactions, games, kiosks, privileges, time plays, system transactions. | Optional read for card lookup; a possible write path for season-pass card issuance (§3.8). |
| **CenterEdge MSSQL / SQL Server DB** | The reporting surface, accessed **read-only**. Needs `pdo_sqlsrv`, `pdo_dblib`, or ODBC on the host. | The primary source for visits, spend, and party payments. |
| **CEplay** | PHP 7.4+/SQLite/vanilla-JS app: arcade pause-group automation, Tag Board, kiosk control, and ~15 POS analytics pages. Custom RBAC (27 permission keys), AES-256-CBC encrypt-then-MAC credentials, CSRF, action log, cron + per-minute watchdog. | Sibling, not parent. §3.8 draws the line. |
| **Slack** | Already in use — CEplay posts staff birthday and work-anniversary bots to it. | Castle CRM adds Slack as an **internal** notification channel (manager alerts, detractor escalation), reusing a channel staff already watch. |

**Known POS tables** (measured by CEplay against this venue, not guessed):

| Table | Holds | Caveat |
|---|---|---|
| `PlayerCardTrans` | The card ledger: loads, game plays, ticket credits, adjustments, wipes, combines. ~20 years deep. | The *only* place card loads exist. Card number is the join key. |
| `Sales` | Goods sold, by item (`InvNo`) and category. | `ShiftDate` is midnight-stamped → **day grain only**. `CostSold` is empty at this venue, so no margin. Party revenue books on the **event date**, not the payment date. |
| `Receipt` | Money taken per station, with `EventType`, `VoidReceipt`, `FailedReceipt`. | `EventType ≠ 0` is party/group event payments taken **before** the event. Codes 28/74/90/92/96 are **unverified**. |
| `ReaderSales` | Reader/attraction sales, used as a tie-out reference. | |
| `Inventory` / `Item` | Item and deal definitions (`InvNo`). | |
| `Division` | Revenue divisions. `DivNo 808` = Go Kart Readers. | |
| `Employee` + time-clock punches | Staff identity and wages. | Contains `FingerprintTemplate` — **never select it**. |

### 3.8 System-of-record map

The failure mode to avoid is two systems both believing they own a booking. This table is
binding: where a column says "CenterEdge", Castle CRM's copy is a cache that may be
refreshed or discarded, and Castle CRM must never present it as authoritative or write it back.

| Thing | System of record | Castle CRM's role |
|---|---|---|
| Card balance, plays, tickets | CenterEdge | Read-only display |
| Card loads / stored value | CenterEdge (`PlayerCardTrans`) | Read for visit + spend derivation |
| Goods and attraction sales | CenterEdge (`Sales`) | Read for revenue reporting |
| **Confirmed party booking, its deposit and balance** | **CenterEdge** | Mirror + enrich. Castle CRM holds the *inquiry* and the *quote*; once it is a real reservation, CenterEdge owns it |
| **Party / group inquiry, quote, follow-up, loss reason** | **Castle CRM** | Sole owner — this does not exist in the POS today |
| Guest contact details, consent, household, children | **Castle CRM** | Sole owner (see the identity note below) |
| Conversations, campaigns, automations, reviews, NPS | **Castle CRM** | Sole owner |
| Memberships / season passes | **Shared** — CenterEdge holds the card and its privileges; Castle CRM holds the lifecycle, renewal state, and comms | Pending question 4, §14 |
| Arcade game and kiosk state, pause schedules | **CEplay** | Out of scope. Castle CRM never pauses a game |
| Per-game, per-reader, per-station analytics | **CEplay** | Out of scope. Castle CRM links out rather than rebuilding |
| Staff birthdays and work anniversaries | **CEplay** | Out of scope — note the collision: CEplay's "birthdays" are *staff*, Castle CRM's are *guests'* |

**Division of labour in one line.** CEplay answers *"how is the venue performing?"*.
Castle CRM answers *"who is the guest, and what happens next?"*.

**Guest identity is the hard part.** The card API's `CustomerInfo`
(`{ id, firstName?, lastName, emailAddress?, phoneNumber?, birthDate? }`) is a
**write-side** schema — it is supplied when creating a card or a transaction and is *not*
returned by `GET /cards/{cardNumber}`. So the card system can hold a guest's email and
phone, but Castle CRM cannot assume it can read them back, and coverage is likely thin
(most guests buy a card without giving a name). Therefore:

- **The card number is the join key**, not email. `contacts.external_pos_id` stores it, and
  `visits` links through it.
- A POS-derived contact starts as a near-empty record: a card number, a visit history, and
  a spend total. It becomes a *marketable* contact only when a guest supplies an email or
  phone through a form, a party booking, a waiver, or a Text Club opt-in — each of which
  writes a `consent_log` row. This is the right outcome anyway: a card number is not consent.
- Castle CRM's growth metric is therefore **identified-and-consented guests**, not raw
  contact count, and the dashboard reports it that way.

### 3.9 Why MySQL when CEplay uses SQLite

CEplay is read-mostly with one writer; SQLite in WAL mode suits it exactly. Castle CRM is
write-heavy and concurrent — queued campaign sends, automation runs advancing every minute,
staff editing deals simultaneously — and SQLite serialises writers. MySQL is also what the
Castle's cPanel host provides natively, and the user specified it. The two databases stay
separate; the integration seam is the shared **read-only MSSQL** connection and, optionally,
CEplay's HTTP API (§12.1), never a cross-write.

---

## 4. Product architecture: Playbooks → Modules → Automations

### 4.1 The 8 Castle Revenue Playbooks

Each playbook is a database-registered bundle of: pipeline(s), form(s), templates,
automations, dashboard widgets, and reports. Playbooks can be activated and deactivated
independently so the Castle can roll out in stages.

| # | Playbook | Revenue moment | Ships with |
|---|---|---|---|
| 01 | **Birthday & Celebration Sales** | A parent inquires about a birthday party | Birthday pipeline (7 stages), party inquiry form, quote template, 9-touch nurture, deposit chase, pre-party checklist, child birth-month annual rebook |
| 02 | **Group & Corporate Events** | A school, camp, corporate planner, or Event Center inquiry | Group pipeline (8 stages), RFP/quote builder, headcount firming sequence, invoice/PO tracking, annual recurrence rebook |
| 03 | **Visit & Ticketing Automation** | A guest buys tickets or loads a Castle Card | Pre-visit prep message, waiver chase, weather/seasonal advisory, first-time-visitor onboarding, abandoned-cart recovery |
| 04 | **Reputation Protection** | A visit just ended | Feedback gate (NPS/CSAT), 4–5★ → Google/Yelp routing, 1–3★ → manager alert + recovery offer, review-response drafting, review wall |
| 05 | **Membership & Season Pass Lifecycle** | A guest holds or lapses a pass | Welcome series, usage nudges ("you haven't used your pass in 5 weeks"), renewal ladder at T-30/-14/-3, win-back, upgrade offers, family-plan cross-sell |
| 06 | **Text Club & Repeat Visits** | A guest opts in to SMS | Double opt-in, welcome coupon, monthly offer calendar, birthday-month coupon, rainy-day / slow-day flash blast, lapsed-guest win-back ladder |
| 07 | **Sales Accountability** | A lead has gone quiet | SLA timers, stale-deal escalation, round-robin assignment, daily rep task digest, weekly manager scorecard, leaderboard |
| 08 | **Fundraising, Donations & Referrals** | A nonprofit, school, or guest asks | Donation-request intake form, approval workflow with annual budget cap, certificate issuance + redemption tracking, referral/advocacy program |

### 4.2 The 13 Core Modules

Grouped into three action lanes, following the reference's (good) IA instinct.

**Lane A — Capture & Convert**
1. **Contacts & Households** — guests, children, companies, custom fields, tags, merge, GDPR/CCPA export & erase
2. **Pipelines & Deals** — multi-pipeline Kanban + table, weighted forecast, stage SLAs, loss reasons
3. **Forms & Landing Pages** — drag-free form builder, embeddable `<script>` or iframe, spam honeypot + rate limit, UTM capture
4. **Bookings & Calendar** — party/group/event booking, resource allocation (rooms, lanes, karts), availability engine, run sheets

**Lane B — Communicate & Return**
5. **Unified Inbox** — threaded email + SMS + web chat + internal notes, assignment, snooze, canned replies, @mentions
6. **Email** — campaign composer, template library with merge tags, scheduling, A/B subject test, bounce/complaint handling, unsubscribe
7. **SMS & Text Club** — 10DLC-aware sending, keyword opt-in (JOIN/STOP/HELP), quiet hours, segment blasts, MMS
8. **Campaigns & Segments** — rule-based dynamic segments, one-off + recurring campaigns, suppression lists, send-time windows
9. **Automations** — visual trigger→condition→action builder, delays, branching, goals, exit criteria (engine spec §9)

**Lane C — Manage & Improve**
10. **Reputation & Feedback** — surveys, NPS, review requests, review inbox, response templates, public review wall widget
11. **Memberships & Passes** — plans, enrolment, renewal states, usage events, dunning status, family plans
12. **Tasks & Team** — tasks, assignments, due dates, round-robin, notifications, activity feed, digests
13. **Reports & Dashboards** — widget dashboards by role, revenue/funnel/source/cohort/attribution reports, saved views, CSV/PDF export, scheduled email delivery

**Cross-cutting (platform, not counted as modules):** Auth & RBAC, Settings, Audit Log,
Integrations & API, Import/Export, Job Queue & Cron, Usage Ledger, Installer, Backup.

### 4.3 Pre-built automation library (100+)

Shipped as seed data, each disabled-by-default with a one-click enable. Distribution:

| Playbook | Automations |
|---|---|
| Birthday & Celebration | 22 |
| Group & Corporate | 16 |
| Visit & Ticketing | 13 |
| Reputation Protection | 11 |
| Membership & Season Pass | 14 |
| Text Club & Repeat Visits | 15 |
| Sales Accountability | 12 |
| Fundraising & Referrals | 8 |
| **Total** | **111** |

Full enumeration lives in `install/seeds/automations.php`; §9.6 gives representative
examples.

---

## 5. Technical architecture

### 5.1 Stack & constraints

| Concern | Decision | Rationale |
|---|---|---|
| Language | PHP **8.1+** (tested 8.1–8.3) | cPanel default range |
| Database | MySQL 5.7.8+ / MariaDB 10.3+ | `utf8mb4`, JSON column support |
| Front end | Vanilla ES2020 JS, hand-written CSS | No build step; uploadable by FTP |
| Dependencies | **Zero runtime Composer packages** | Shared hosts often lack Composer |
| Server | Apache + `mod_rewrite` + `.htaccess` | cPanel standard; nginx guide provided |
| Mail | PHP `mail()`, SMTP (own socket client), or API (Mailgun/SendGrid/SES) | Works everywhere, upgradeable |
| SMS | Twilio / Telnyx / Vonage via cURL | Pluggable driver interface |
| Background work | Single cron entry → `cron.php` | Only server config required |
| Sessions | Database-backed | Survives shared-host session GC |
| PHP extensions | `pdo_mysql`, `mbstring`, `json`, `openssl`, `curl`, `fileinfo` (all standard) | Installer hard-checks these |
| POS feed | `pdo_sqlsrv`, `pdo_dblib`, or ODBC — **optional** | Read-only CenterEdge MSSQL access. Absent, the app runs fully and POS-dependent features show a "not connected" state (§8.3) |
| Optional | `zip` (backup export), `gd`/`imagick` (image resize), `intl` | Degrade gracefully |

### 5.2 Directory layout

```
castle-crm/
├── index.php                  # single front controller
├── cron.php                   # cron entry point (token-guarded if web-triggered)
├── webhook.php                # inbound provider webhooks (SMS, email events)
├── .htaccess                  # rewrite + security headers + deny rules
├── VERSION
├── install/                   # web installer; self-locks after completion
│   ├── index.php
│   ├── steps/                 # 01-requirements … 08-finish
│   ├── schema/                # 001_core.sql … 0NN_*.sql
│   └── seeds/                 # attractions, packages, pipelines, templates, automations
├── config/
│   ├── config.php             # WRITTEN BY INSTALLER (gitignored)
│   ├── config.sample.php
│   └── .htaccess              # Deny from all
├── app/
│   ├── Core/                  # Router Request Response DB Model View Auth Acl
│   │                          # Csrf Session Validator Mailer Sms Queue Cron Log
│   │                          # Settings Migration Encryption Paginator Event
│   ├── Controllers/           # one per module + Api/ + Admin/
│   ├── Models/
│   ├── Services/              # AutomationEngine SegmentEngine AvailabilityEngine
│   │                          # QuoteCalculator ReviewRouter UsageLedger Importer
│   ├── Views/
│   │   ├── layouts/ partials/ <module>/ emails/ public/
│   └── helpers.php
├── assets/
│   ├── css/  (app.css, print.css, tokens.css)
│   ├── js/   (app.js, kanban.js, inbox.js, builder.js, charts.js, calendar.js)
│   └── img/
├── storage/                   # Deny from all
│   ├── logs/ cache/ uploads/ exports/ backups/ sessions/
└── docs/
    ├── SPEC.md  INSTALL.md  USER_GUIDE.md  API.md  CHANGELOG.md
```

**cPanel deployment:** upload the folder to `public_html/` (or a subdomain root), visit
`/install/`, follow eight steps. The installer writes `config/config.php`, runs schema +
seeds, creates the owner account, and drops `install/.lock`. `storage/` and `config/`
are protected by `.htaccess` *and* by a path check in the front controller, so a host
that ignores `.htaccess` still refuses to serve them.

### 5.3 Request lifecycle

```
Apache (.htaccess)
  └─ all non-file requests → index.php
       1. Load config, set error handler, open PDO
       2. Boot Settings cache (single query, APCu/file-cached)
       3. Start DB session; resolve current user
       4. Router: match METHOD + path → [Controller, action, middleware[]]
       5. Middleware: auth → acl → csrf (mutating verbs) → ratelimit
       6. Controller → Model/Service → View
       7. Response: HTML (layout) | JSON | CSV | PDF | 302
       8. Flush deferred work to job queue; write audit rows
```

Routing is an explicit route table in `app/routes.php` (no annotation scanning, no
reflection) — fast on a shared host and greppable.

### 5.4 Core conventions

- **Models** are thin: a table name, a fillable list, casts, relations declared as arrays.
  A small `QueryBuilder` gives `where/orderBy/limit/paginate` with bound parameters only.
- **Every** SQL statement uses prepared statements. String interpolation into SQL is a
  review-blocking defect.
- **Views** are plain PHP templates. `e()` (htmlspecialchars, UTF-8, quotes) is applied to
  every echoed variable; the only exception is explicitly sanitised rich text passed
  through `clean_html()`.
- **Services** hold business logic and are unit-testable without HTTP.
- **Events** (`Event::fire('deal.stage_changed', $payload)`) are the integration seam
  between modules and the automation engine — modules never call the engine directly.

### 5.5 Security model

| Control | Implementation |
|---|---|
| Passwords | `password_hash()` Argon2id (bcrypt fallback), rehash on login when params change |
| Sessions | DB-backed, `HttpOnly` + `Secure` + `SameSite=Lax`, rotate ID on privilege change, idle + absolute timeout |
| CSRF | Per-session token, required on all POST/PUT/PATCH/DELETE, double-submit for AJAX |
| XSS | Output escaped by default; CSP header with nonce for inline scripts; no `innerHTML` with untrusted data |
| SQLi | PDO prepared statements, `ATTR_EMULATE_PREPARES => false` |
| Authorization | RBAC with granular permissions (§6), checked in middleware *and* re-checked in the controller before any write |
| Brute force | Per-IP + per-account throttle, exponential backoff, CAPTCHA after N failures, lockout notification |
| File uploads | Extension allowlist + MIME sniff + size cap, stored outside docroot, served via a streaming controller with auth, randomised filenames |
| Secrets | API keys/tokens encrypted at rest (`openssl` AES-256-GCM) with a key in `config.php`, never logged |
| Headers | `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Strict-Transport-Security`, `Content-Security-Policy`, `Permissions-Policy` |
| Audit | Every create/update/delete on a business table writes actor, IP, UA, before/after diff |
| Public endpoints | Forms, webhooks, unsubscribe, waiver signing are rate-limited, honeypotted, and HMAC-verified where a provider supports it |
| Installer | Refuses to run once `install/.lock` exists; requires a filesystem-proof token for re-runs; the operator is told to delete `install/` after setup |

### 5.6 Performance on shared hosting

- Settings and the route table are cached (APCu when present, file cache otherwise)
- Every foreign key and every column used in a `WHERE`/`ORDER BY` of a list view is indexed;
  composite indexes on `(entity_type, entity_id)` polymorphic lookups
- List views paginate server-side (default 50) and never `SELECT *` on wide tables
- Campaign sends and automation runs are **chunked** by the job queue with a wall-clock
  budget per cron tick (default 50s) so a 20,000-recipient blast never hits `max_execution_time`
- Dashboard aggregates are materialised nightly into `report_daily_rollup`
- Zero external CDN dependencies; CSS+JS are hand-minifiable and cache-busted by `VERSION`

---

## 6. Roles & permissions

Permissions are granular strings (`contacts.view`, `deals.delete`, `campaigns.send`,
`settings.manage`, …) grouped into roles. Roles are editable; the seeded set:

| Role | Scope |
|---|---|
| **Owner** | Everything, including settings, users, billing config, data erasure |
| **General Manager** | Everything operational; no destructive settings or user deletion |
| **Sales Manager** | All pipelines/deals/contacts, team reassignment, scorecards, campaign send |
| **Party Host / Sales Rep** | Own + team-visible deals, contacts, bookings, inbox; no campaign send, no settings |
| **Marketing** | Campaigns, segments, templates, forms, automations, reputation; read-only deals |
| **Front Desk** | Bookings, check-in, waivers, contact lookup, inbox reply; no pipeline edit |
| **Read Only** | View dashboards and reports; export nothing |
| **API / Integration** | Token-scoped programmatic access; no UI login |

Record-level scoping: `all` / `team` / `own` per permission, so a Party Host sees only
their assigned deals while a Sales Manager sees the team's.

**Convention borrowed from CEplay.** The Castle's staff already navigate one permission
model, so Castle CRM uses the same shape: roles are **data** (editable in Settings, not a
hard-coded enum), permission keys split into `view_*` keys that control page visibility —
unticking one hides the nav item, bounces the route, *and* closes the matching read API —
and feature keys that gate actions. Castle CRM adds the scope dimension CEplay lacks,
because a CRM has per-record ownership that an arcade-automation tool does not.

The same safety rails apply: only an Owner manages roles; the last active Owner cannot be
demoted, deactivated, or deleted; a user may only assign roles whose permissions are a
**subset of their own**, so no one can escalate themselves; a role in use cannot be deleted;
and an unknown or corrupt role slug resolves to **zero** permissions rather than defaulting
open. Sessions re-validate permissions periodically so a role change takes effect without
forcing a re-login.

Money is a permission, not a given: `view_revenue` gates every dollar figure, mirroring
CEplay, so a floor-staff role can work the inbox and the Tag-Board-equivalent views without
seeing guest spend or lifetime value.

---

## 7. Data model

~72 tables. Conventions: `InnoDB`, `utf8mb4_unicode_ci`, `id BIGINT UNSIGNED AUTO_INCREMENT`,
`created_at`/`updated_at` on every table, `deleted_at` soft deletes on business entities,
FKs with explicit `ON DELETE` behaviour, table prefix configurable (default `cc_`).

### 7.1 Platform

```
users(id, name, email UNIQUE, password_hash, phone, avatar_path, role_id,
      is_active, last_login_at, failed_attempts, locked_until, timezone,
      notification_prefs JSON, mfa_secret, created_at, updated_at, deleted_at)
roles(id, slug UNIQUE, name, description, is_system)
permissions(id, slug UNIQUE, group, label)
role_permissions(role_id, permission_id, scope ENUM('all','team','own'))
teams(id, name, manager_id)  team_members(team_id, user_id)
sessions(id CHAR(64) PK, user_id, ip, user_agent, payload, last_activity)
password_resets(email, token_hash, expires_at)
settings(id, group, key UNIQUE, value LONGTEXT, type, is_encrypted)
audit_log(id, user_id, action, entity_type, entity_id, changes JSON, ip, user_agent, created_at)
jobs(id, queue, type, payload JSON, available_at, attempts, reserved_at, status, last_error)
job_failures(id, job_id, type, payload JSON, error LONGTEXT, failed_at)
notifications(id, user_id, type, title, body, link, read_at, created_at)
api_tokens(id, user_id, name, token_hash, abilities JSON, last_used_at, expires_at)
webhooks(id, event, target_url, secret, is_active)
webhook_deliveries(id, webhook_id, payload JSON, response_code, attempts, delivered_at)
migrations(id, filename UNIQUE, batch, ran_at)
usage_ledger(id, channel, direction, units, unit_cost_cents, entity_type, entity_id,
             provider, provider_ref, occurred_at)
```

### 7.2 Contacts & households

```
contacts(id, first_name, last_name, email, phone, phone_normalized, alt_phone,
         address_1, address_2, city, state, postal_code, country,
         lifecycle_stage ENUM('lead','prospect','customer','member','lapsed','advocate'),
         source, source_detail, utm JSON, owner_id, household_id, company_id,
         email_consent, email_consent_at, sms_consent, sms_consent_at, sms_consent_source,
         marketing_opt_out_at, do_not_contact, birth_date,
         first_visit_at, last_visit_at, visit_count,
         lifetime_value_cents, avg_spend_cents, last_contacted_at,
         external_pos_id, external_ids JSON, notes_summary,
         created_at, updated_at, deleted_at)
         INDEX(email), INDEX(phone_normalized), INDEX(lifecycle_stage),
         INDEX(owner_id), INDEX(last_visit_at)

households(id, name, primary_contact_id, address_1, city, state, postal_code)
children(id, household_id, contact_id, first_name, birth_date, birth_month TINYINT,
         gender, notes, allergies, favourite_attraction_id)
         INDEX(birth_month)   -- drives birthday-month campaigns
companies(id, name, type ENUM('school','corporate','camp','nonprofit','sports','other'),
          district, website, phone, address_*, owner_id, annual_potential_cents,
          is_recurring_group, last_event_at, notes)
contact_companies(contact_id, company_id, job_title, is_primary)
tags(id, name UNIQUE, color, group)
taggables(tag_id, entity_type, entity_id)
custom_fields(id, entity_type, key, label, type, options JSON, is_required, sort_order)
custom_field_values(id, field_id, entity_type, entity_id, value LONGTEXT)
contact_notes(id, contact_id, user_id, body, is_pinned, created_at)
contact_merges(id, kept_id, merged_id, payload JSON, user_id, created_at)
consent_log(id, contact_id, channel, action ENUM('granted','revoked'), source,
            ip, user_agent, evidence JSON, created_at)   -- TCPA audit trail
```

### 7.3 Pipelines & deals

```
pipelines(id, name, slug, type ENUM('birthday','group','corporate','membership',
          'fundraising','custom'), is_active, sort_order, default_owner_strategy)
pipeline_stages(id, pipeline_id, name, sort_order, probability TINYINT,
                sla_hours, is_won, is_lost, color)
deals(id, pipeline_id, stage_id, title, contact_id, company_id, owner_id,
      value_cents, probability, expected_close_date, event_date,
      guest_count, source, source_detail, utm JSON,
      status ENUM('open','won','lost','abandoned'), lost_reason_id,
      stage_entered_at, sla_due_at, last_activity_at, next_action, next_action_at,
      won_at, lost_at, created_at, updated_at, deleted_at)
      INDEX(pipeline_id, stage_id), INDEX(owner_id, status), INDEX(sla_due_at),
      INDEX(event_date)
deal_stage_history(id, deal_id, from_stage_id, to_stage_id, user_id, duration_seconds, created_at)
lost_reasons(id, label, sort_order)
activities(id, entity_type, entity_id, type ENUM('call','email','sms','meeting',
           'note','stage_change','task','booking','payment','system'),
           user_id, contact_id, subject, body, outcome, duration_seconds,
           occurred_at, created_at)
           INDEX(entity_type, entity_id, occurred_at)
quotes(id, deal_id, number UNIQUE, status, subtotal_cents, discount_cents, tax_cents,
       total_cents, deposit_cents, valid_until, sent_at, viewed_at, accepted_at,
       public_token, terms, notes)
quote_lines(id, quote_id, item_type, item_id, description, qty, unit_cents, total_cents, sort_order)
```

### 7.4 Attractions, packages, bookings

```
attractions(id, name, slug, category, is_indoor, is_seasonal, season_start_md,
            season_end_md, min_age, min_height_in, max_height_in, capacity_per_slot,
            slot_minutes, price_cents, is_party_eligible, is_party_main,
            requires_waiver, description, image_path, is_active, sort_order)
operating_hours(id, day_of_week, opens_at, closes_at, season_label,
                effective_from, effective_to)
operating_exceptions(id, date, is_closed, opens_at, closes_at, note)
attraction_availability(id, attraction_id, date, is_available, note)
resources(id, name, type ENUM('party_room','bowling_lane','kart','table',
          'event_space','skate_rink','laser_arena'), capacity, attraction_id, is_active)
party_packages(id, name, slug, main_attraction_id, base_price_cents,
               included_guests, additional_guest_cents, duration_minutes,
               min_notice_days, is_seasonal, deposit_cents, deposit_percent,
               includes JSON, description, is_active, sort_order)
package_addons(id, name, price_cents, unit ENUM('flat','per_guest','per_hour'),
               attraction_id, is_active, sort_order)
bookings(id, booking_number UNIQUE, deal_id, contact_id, company_id,
         type ENUM('birthday','group','corporate','field_trip','camp','event_center','other'),
         package_id, status ENUM('inquiry','held','confirmed','deposit_paid','paid',
         'completed','cancelled','no_show'),
         event_date, start_time, end_time, guest_count, adult_count, child_count,
         guest_of_honor_name, guest_of_honor_age,
         subtotal_cents, discount_cents, tax_cents, total_cents,
         deposit_cents, deposit_paid_at, balance_due_cents, paid_in_full_at,
         payment_reference, host_user_id,
         special_requests, allergies, dietary_notes, cake_notes,
         internal_notes, source, cancelled_at, cancellation_reason,
         confirmed_at, reminder_sent_at, run_sheet_printed_at,
         external_pos_id, created_at, updated_at, deleted_at)
         INDEX(event_date, status), INDEX(contact_id), INDEX(booking_number)
booking_addons(id, booking_id, addon_id, qty, unit_cents, total_cents)
booking_attractions(id, booking_id, attraction_id, start_time, end_time, sort_order)
booking_resources(id, booking_id, resource_id, start_time, end_time)
booking_guests(id, booking_id, name, age, parent_name, parent_phone,
               waiver_id, checked_in_at, rsvp_status)
booking_tasks(id, booking_id, template_task_id, title, assigned_to, due_at, completed_at)
booking_payments(id, booking_id, amount_cents, type ENUM('deposit','balance','refund'),
                 method, reference, recorded_by, occurred_at, note)
```

### 7.5 Visits, tickets, memberships

```
visits(id, contact_id, household_id, visit_date, source ENUM('pos_import','booking',
       'online_ticket','manual','check_in'), spend_cents, attractions JSON,
       party_size, booking_id, external_ref, created_at)
       INDEX(contact_id, visit_date)
tickets(id, contact_id, order_ref, visit_date, qty, total_cents, status,
        channel, utm JSON, purchased_at, redeemed_at)
membership_plans(id, name, slug, type ENUM('season_pass','membership','multi_visit'),
                 price_cents, billing_period ENUM('one_time','monthly','annual'),
                 duration_days, visit_allowance, benefits JSON, attraction_access JSON,
                 is_family_plan, max_members, is_active, sort_order)
memberships(id, plan_id, contact_id, household_id, member_number UNIQUE,
            status ENUM('pending','active','expiring','expired','cancelled',
            'lapsed','frozen'),
            started_at, expires_at, cancelled_at, auto_renew,
            price_paid_cents, visits_used, last_used_at,
            renewal_notice_30_at, renewal_notice_14_at, renewal_notice_3_at,
            winback_sent_at, external_ref, created_at, updated_at)
            INDEX(status, expires_at)
membership_members(id, membership_id, contact_id, child_id, relationship, is_primary)
membership_usage(id, membership_id, contact_id, visit_id, used_at, attraction_id)
membership_payments(id, membership_id, amount_cents, status, method, reference, occurred_at)
waivers(id, version, title, body LONGTEXT, requires_guardian, is_active, published_at)
waiver_signatures(id, waiver_id, contact_id, booking_id, signer_name, signer_email,
                  signer_phone, is_guardian, minors JSON, signature_data LONGTEXT,
                  ip, user_agent, signed_at, expires_at, public_token)
                  INDEX(contact_id), INDEX(booking_id)
```

### 7.6 Communications

```
conversations(id, contact_id, channel ENUM('email','sms','webchat','phone','social','internal'),
              subject, status ENUM('open','pending','snoozed','closed'),
              assigned_to, last_message_at, snoozed_until, is_unread,
              first_response_at, closed_at, created_at)
              INDEX(status, assigned_to), INDEX(last_message_at)
messages(id, conversation_id, contact_id, direction ENUM('inbound','outbound'),
         channel, from_address, to_address, subject, body LONGTEXT, body_text LONGTEXT,
         user_id, template_id, campaign_id, automation_run_id,
         status ENUM('queued','sent','delivered','read','failed','bounced',
         'complained','undelivered'),
         provider, provider_message_id, error_code, error_message,
         cost_cents, segments TINYINT, sent_at, delivered_at, opened_at, clicked_at,
         created_at)
         INDEX(conversation_id, created_at), INDEX(provider_message_id), INDEX(status)
message_attachments(id, message_id, filename, path, mime, size_bytes)
templates(id, name, slug, channel ENUM('email','sms','letter'), category,
          subject, body LONGTEXT, preheader, merge_tags JSON, playbook_id,
          is_system, is_active, created_by)
canned_replies(id, title, body, channel, shortcut, created_by)
segments(id, name, slug, description, rules JSON, is_dynamic,
         cached_count, cached_at, created_by)
campaigns(id, name, channel ENUM('email','sms','both'), template_id, segment_id,
          status ENUM('draft','scheduled','sending','paused','sent','cancelled'),
          subject, body LONGTEXT, from_name, from_email, reply_to,
          scheduled_at, send_window_start, send_window_end, timezone,
          ab_variant_b JSON, ab_split_percent,
          recipients_total, sent_count, delivered_count, opened_count, clicked_count,
          bounced_count, unsubscribed_count, complained_count, failed_count,
          revenue_attributed_cents, estimated_cost_cents, actual_cost_cents,
          created_by, started_at, completed_at, created_at)
campaign_recipients(id, campaign_id, contact_id, variant CHAR(1), status,
                    message_id, sent_at, opened_at, clicked_at, unsubscribed_at,
                    bounced_at, error)
                    UNIQUE(campaign_id, contact_id)
link_clicks(id, message_id, campaign_id, contact_id, url, clicked_at, ip, user_agent)
suppressions(id, channel, address, reason ENUM('unsubscribe','bounce','complaint',
             'manual','invalid'), source, created_at)  UNIQUE(channel, address)
sms_keywords(id, keyword UNIQUE, action ENUM('subscribe','unsubscribe','help','custom'),
             reply_body, list_id, is_active)
text_club(id, contact_id, phone_normalized UNIQUE, status ENUM('pending','subscribed',
          'unsubscribed'), joined_via, double_optin_sent_at, confirmed_at,
          unsubscribed_at, last_offer_sent_at, offers_redeemed)
sms_numbers(id, phone, provider, type ENUM('local','toll_free','short_code'),
            tendlc_campaign_id, tendlc_status, is_default, is_active)
```

### 7.7 Forms, reputation, fundraising, tasks, reporting

```
forms(id, name, slug UNIQUE, type, title, description, submit_label, success_message,
      redirect_url, pipeline_id, stage_id, owner_strategy, assign_to,
      notify_user_ids JSON, autoresponder_template_id, automation_id,
      honeypot_field, requires_captcha, is_active, submission_count,
      embed_theme JSON, created_by)
form_fields(id, form_id, key, label, type, placeholder, help_text, options JSON,
            is_required, validation JSON, maps_to, sort_order, width)
form_submissions(id, form_id, contact_id, deal_id, data JSON, ip, user_agent,
                 referrer, utm JSON, spam_score, is_spam, created_at)

surveys(id, name, type ENUM('nps','csat','custom'), questions JSON, trigger_event,
        delay_hours, is_active)
survey_responses(id, survey_id, contact_id, booking_id, visit_id, score TINYINT,
                 sentiment ENUM('promoter','passive','detractor'), answers JSON,
                 comment TEXT, routed_to, resolved_at, resolution_note, created_at)
                 INDEX(sentiment, created_at)
review_requests(id, contact_id, booking_id, survey_response_id, platform
                ENUM('google','yelp','facebook','tripadvisor'), status, sent_at,
                clicked_at, reviewed_at, token)
reviews(id, platform, external_id, contact_id, author_name, rating TINYINT,
        body TEXT, review_url, replied_at, reply_body, reply_by, sentiment,
        is_featured, posted_at, imported_at)
        INDEX(platform, posted_at), INDEX(rating)

donation_requests(id, organization_name, company_id, contact_name, contact_email,
                  contact_phone, organization_type, event_name, event_date,
                  requested_items, estimated_value_cents, beneficiary, tax_id,
                  status ENUM('new','reviewing','approved','declined','fulfilled','expired'),
                  decision_by, decision_at, decision_note, certificate_number,
                  certificate_value_cents, certificate_expires_at, redeemed_at,
                  fiscal_year, created_at)
donation_budget(id, fiscal_year UNIQUE, budget_cents, committed_cents, redeemed_cents)
referrals(id, referrer_contact_id, referred_contact_id, code UNIQUE, status,
          reward_type, reward_value_cents, converted_booking_id, rewarded_at, created_at)
coupons(id, code UNIQUE, name, type ENUM('percent','fixed','bogo','free_item'),
        value, applies_to JSON, min_spend_cents, max_redemptions,
        max_per_contact, redemption_count, starts_at, expires_at,
        campaign_id, is_active)
coupon_redemptions(id, coupon_id, contact_id, booking_id, visit_id,
                   discount_cents, redeemed_at, redeemed_by)

tasks(id, title, description, entity_type, entity_id, assigned_to, created_by,
      priority ENUM('low','normal','high','urgent'), status ENUM('open','in_progress',
      'done','cancelled'), due_at, completed_at, completed_by,
      automation_run_id, reminder_sent_at, created_at)
      INDEX(assigned_to, status, due_at)
task_comments(id, task_id, user_id, body, created_at)

dashboards(id, name, role_id, user_id, layout JSON, is_default)
dashboard_widgets(id, dashboard_id, type, title, config JSON, position JSON)
saved_reports(id, name, type, filters JSON, columns JSON, group_by, chart_type,
              schedule_cron, recipients JSON, created_by, last_run_at)
report_daily_rollup(id, date, metric, dimension, dimension_value,
                    value_numeric, value_cents)
                    UNIQUE(date, metric, dimension, dimension_value)
goals(id, metric, period, target_value, target_cents, starts_on, ends_on,
      owner_id, team_id)
```

### 7.8 Playbooks & automations

```
playbooks(id, number, name, slug, tagline, description, revenue_moment,
          arrives_ready TEXT, you_tailor TEXT, icon, is_active, activated_at,
          sort_order)
playbook_assets(id, playbook_id, asset_type ENUM('pipeline','form','template',
                'automation','segment','report','widget','survey'), asset_id)

automations(id, playbook_id, name, slug, description, trigger_type, trigger_config JSON,
            conditions JSON, is_active, run_once_per_contact, allow_reentry,
            reentry_cooldown_hours, quiet_hours_respect, goal_event, goal_window_hours,
            exit_conditions JSON, priority, run_count, goal_count, last_run_at,
            created_by, created_at, updated_at)
automation_steps(id, automation_id, parent_step_id, branch ENUM('main','yes','no'),
                 sort_order, type, config JSON, delay_minutes, delay_until_type,
                 delay_until_config JSON)
automation_runs(id, automation_id, contact_id, entity_type, entity_id,
                status ENUM('running','waiting','completed','exited','failed','cancelled'),
                current_step_id, next_run_at, context JSON, goal_reached_at,
                started_at, finished_at, error)
                INDEX(status, next_run_at), INDEX(automation_id, contact_id)
automation_step_log(id, run_id, step_id, status, output JSON, error, executed_at)
```

---

## 8. Module specifications

### 8.1 Dashboard

Role-aware widget grid, drag-ordered, persisted per user.

**Widgets:** Today's bookings (with run-sheet links) · Revenue MTD vs goal vs last year ·
Pipeline value by stage · Leads needing first response (SLA breach count, red) ·
Stale deals (no activity ≥ N days) · My tasks due today/overdue · Unread inbox count by
channel · This week's parties by day · Review rating trend + new reviews needing reply ·
NPS gauge (rolling 90 days) · Text Club subscriber growth · Membership renewals due in 30
days · Campaign performance (last 5 sends) · Top lead sources (90 days) ·
Attraction mix on bookings · Weather-risk flag for outdoor-dependent bookings ·
Usage spend MTD.

Each widget: configurable date range, click-through to its filtered list view, and a CSV
export. Widgets render from `report_daily_rollup` where possible to keep the dashboard
under ~200ms on shared hosting.

### 8.2 Contacts & households

- **List:** server-side search (name/email/phone/company), filter by any field, tag,
  segment, lifecycle, owner, last-visit range; saved views; bulk tag/assign/export/add-to-campaign
- **Record:** header KPIs (LTV, visits, last visit, membership state, consent flags) ·
  timeline (merged activities, messages, bookings, visits, payments, survey responses,
  automation runs) · household panel with children and birth months · deals · bookings ·
  memberships · waivers · tasks · files · custom fields · consent audit
- **Dedup:** fuzzy match on email / normalised phone / name+ZIP at create time; a merge UI
  that previews field-by-field resolution and keeps a reversible `contact_merges` record
- **Phone normalisation:** all phones stored E.164 in `phone_normalized` so SMS matching
  and Text Club dedup are reliable
- **Privacy:** per-contact "export everything" (JSON+CSV) and "erase" (anonymise, retain
  financial aggregates) for CCPA/GDPR-style requests, both audit-logged

### 8.3 Visits — how a "visit" comes to exist

Because admission is free (§3.2) there is no turnstile event. Four sources, in priority order:

1. **CenterEdge card ledger** — the primary source. A nightly job reads `PlayerCardTrans`
   over a trailing window and collapses each card's activity into one visit per card per
   business day: `spend_cents` from paid loads plus reader spend, `attractions` from the
   games played, and `external_ref` = the card number. Matched to a contact by
   `external_pos_id` (card number), then email, then normalised phone. An unmatched card
   creates a minimal contact flagged `source=pos_import` with **no consent** — it is a
   visit history, not a marketing target (§3.8).
2. **Party / group event payment** — a `Receipt` row with `EventType ≠ 0` identifies an
   event payment, and the matching `Sales` rows on the event date identify the event itself.
   This is how Castle CRM learns that a party it quoted actually happened, which closes the
   loop on quote→booking attribution without Castle CRM owning the reservation.
3. **Booking completion** — a Castle CRM booking moving to `completed` writes a visit for the
   host contact and, where `booking_guests` are known, for each guardian. Used when the POS
   path is unavailable, and deduplicated against source 2 on `(contact, date)`.
4. **Manual / front-desk check-in** — staff look up a contact and record a visit.

**Deduplication.** The same real visit can arrive from several sources, so visits are keyed
on `(contact_id, visit_date, source_priority)` and a lower-priority source never overwrites
a higher one. A visit already attested by the card ledger is not re-created by a booking.

**Day-grain honesty.** `Sales.ShiftDate` is midnight-stamped, so anything derived from
`Sales` is day-grain only; hour-of-day analysis is possible from `PlayerCardTrans` and
`Receipt` but not from `Sales`. Reports state which grain they are on rather than implying
an hourly precision the source cannot support.

**Driver dependency.** All of the above needs an MSSQL PDO driver on the Castle CRM host.
Castle CRM therefore treats POS import as an **optional capability**: without a driver the
application installs and runs fully, the POS-dependent widgets show a "not connected"
state with a link to the setup page, and visits fall back to sources 3 and 4. The installer
detects the driver and says so plainly rather than failing.

`visits` is the spine of retention automation: `last_visit_at`, `visit_count`, and
`lifetime_value_cents` are denormalised onto `contacts` by a nightly job plus an
on-write trigger in the service layer.

### 8.4 Pipelines & deals

- **Kanban** with drag-to-stage (vanilla pointer events, no library), WIP counts, stage
  value totals, and colour-coded SLA state (green / amber / red)
- **Table view** with inline edit, bulk reassign, and column chooser
- **Stage SLAs:** `pipeline_stages.sla_hours` sets `deals.sla_due_at` on entry; breach
  fires `deal.sla_breached` → escalation automation
- **Weighted forecast** by stage probability and expected close date; forecast vs goal chart
- **Assignment strategies:** manual, round-robin, load-balanced, by source, by event date
- **Quote builder:** pick a package, auto-price from `party_packages` + `package_addons` +
  guest count (§8.5), apply discount, generate a tokenised public quote URL the family can
  view and accept; acceptance fires `quote.accepted`
- **Loss reasons** required on `lost`, feeding a win/loss report

Seeded **Birthday pipeline:** New Inquiry → Contacted → Quote Sent → Follow-Up →
Date Held → Deposit Paid → Confirmed (won) | Lost.

Seeded **Group/Corporate pipeline:** New Inquiry → Qualified → Site Visit / Info Sent →
Proposal Sent → Negotiating → Verbal Commit → Contract/PO Received → Confirmed (won) | Lost.

### 8.5 Bookings, availability & quoting

**Availability engine** (`Services/AvailabilityEngine.php`) answers *"can we host N guests
doing activity A at time T on date D?"* by intersecting:

1. `operating_hours` + `operating_exceptions` for D
2. `attractions.is_seasonal` + `season_start_md`/`season_end_md` + `attraction_availability`
3. `resources` of the required type, minus `booking_resources` already allocated in the window
4. `party_packages.duration_minutes` and buffer settings
5. `party_packages.min_notice_days` (the Castle's 7-day online rule)

Returns available slots, or a structured reason for refusal — which the public booking form
turns into either alternative slots or the "please call (845) 469-2116" fallback.

**Quote calculator** (`Services/QuoteCalculator.php`), pure and unit-tested:

```
base            = package.base_price_cents
extra_guests    = max(0, guest_count - package.included_guests)
guests_total    = extra_guests * package.additional_guest_cents
addons_total    = Σ addon.price × (flat | per_guest × guest_count | per_hour × hours)
subtotal        = base + guests_total + addons_total
discount        = coupon or manual (validated against coupon rules)
tax             = (subtotal - discount) × tax_rate      [configurable; NY/Orange County]
total           = subtotal - discount + tax
deposit         = package.deposit_cents OR total × deposit_percent
```

**Booking record:** guest of honour and age, allergies and dietary notes, cake notes,
add-ons, attraction schedule within the party, allocated resources, assigned host,
waiver completion progress, payment ledger, internal notes, and a derived task checklist.

**Run sheet:** a print stylesheet view for the party host — timeline, headcount, room,
allergies in bold, add-ons, cake timing, balance due.

**Calendar:** month / week / day views across bookings, with resource-lane view for a day,
drag-to-reschedule (permission-gated, fires `booking.rescheduled`), and an iCal feed per
user or per resource.

### 8.6 Unified inbox

- Single threaded view across email, SMS, web chat, and internal notes, keyed to a contact
- Filters: unread / assigned to me / unassigned / by channel / snoozed / closed
- Assignment, snooze-until, close, reopen, @mention (notifies), canned replies with merge tags
- Inbound email via provider webhook or IMAP poll in `cron.php`; inbound SMS via webhook
- **Automatic contact resolution** by email or normalised phone; unknown senders create a
  contact and an open conversation
- **First-response-time tracking** → `conversations.first_response_at`, reported per rep
- Reply composer honours channel limits (SMS segment counter with cost preview), quiet
  hours, and suppression/consent state — an agent physically cannot text a contact who
  replied STOP
- Keyboard-driven (`j`/`k` navigate, `r` reply, `a` assign, `e` close)

### 8.7 Email, SMS & Text Club

**Drivers.** `Core/Mailer` and `Core/Sms` define interfaces with swappable drivers:
Mail — `php_mail`, `smtp` (native socket client, TLS, auth), `mailgun`, `sendgrid`, `ses`.
SMS — `twilio`, `telnyx`, `vonage`, plus a `log` driver for dry-run testing.
Credentials are encrypted at rest; a **Test Send** button in settings validates each.

**Deliverability.** Installer emits the exact SPF/DKIM/DMARC records to add in cPanel ›
Zone Editor, with a one-click DNS checker. List-Unsubscribe headers (RFC 8058 one-click)
on every bulk email. Bounce and complaint webhooks write `suppressions` automatically.

**Compliance guardrails, enforced in the send path — not the UI:**

| Rule | Enforcement |
|---|---|
| No SMS without `sms_consent` + a `consent_log` row | `Sms::send()` refuses, logs, and surfaces the reason |
| STOP / UNSTOP / HELP keywords | `sms_keywords` handled on inbound before anything else |
| Quiet hours (default 9am–8pm local) | Queue defers to the next permitted window |
| Every bulk SMS carries opt-out text | Template validator blocks a save without it |
| CAN-SPAM: physical address + unsubscribe in every bulk email | Template validator blocks the save |
| 10DLC brand/campaign registration | Recorded on `sms_numbers`; settings warns when unregistered |

**Text Club.** Double opt-in (`JOIN` to the number, or a web form → confirmation SMS →
`YES`), welcome coupon, monthly offer calendar, birthday-month coupon, and
slow-day/rainy-day flash blasts. Subscriber growth and offer-redemption rate are reported;
redemptions link coupons back to `visits` for revenue attribution.

**Usage ledger.** Every send writes `usage_ledger` with units and cost. A campaign
composer shows **estimated cost before send** (recipients × segments × unit cost) and
requires confirmation above a configurable threshold.

### 8.8 Campaigns & segments

**Segment builder:** nested AND/OR rule groups over contact fields, tags, custom fields,
visit behaviour (`last_visit_at`, `visit_count`, `lifetime_value_cents`), membership state,
children's birth months, booking history, campaign engagement, and consent. Rules persist
as JSON; `Services/SegmentEngine.php` compiles them to parameterised SQL. Dynamic segments
re-evaluate at send time; counts are cached with a manual refresh.

**Campaign flow:** build → preview (desktop/mobile/plain-text) → seed-test send →
estimate cost → schedule (with send window + timezone) → send in queued chunks →
live stats → attribution report.

**Attribution:** a click or open within the attribution window (default 30 days) followed by
a booking or visit credits `campaigns.revenue_attributed_cents`, with both
first-touch and last-touch views in reporting.

### 8.9 Reputation & feedback

1. A trigger (booking completed, visit recorded) schedules a survey after a configurable delay
2. The guest gets a one-question NPS/CSAT via email or SMS with a tokenised link
3. **Promoters (9–10 / 4–5★)** → thank-you + review request, rotating across Google / Yelp /
   Facebook by configurable weight; click tracked
4. **Passives (7–8 / 3★)** → "what would have made it a 10?" open comment
5. **Detractors (≤6 / 1–2★)** → **no public review ask.** Instead: immediate manager alert
   (email + SMS + in-app), a task with an SLA, and an optional recovery coupon on resolution
6. **Review inbox:** imported reviews (Google Business Profile API, Yelp, Facebook where
   permitted; CSV fallback), reply drafting from templates, reply logging
7. **Public review wall:** an embeddable widget showing featured 4–5★ reviews on the Castle's site

Reported: NPS trend, CSAT by attraction and by party host, detractor resolution time,
review velocity and rating trend, request→review conversion rate.

### 8.10 Memberships & season passes

Lifecycle state machine with automated transitions:

```
pending → active → expiring(T-30) → expired → lapsed(+60d) → [winback] → active
                 ↘ cancelled        ↘ frozen → active
```

- Plans: season pass, recurring membership, multi-visit punch card; family plans with
  `membership_members`
- Usage: `membership_usage` rows from visits; "unused pass" nudge when no usage in N weeks
- Renewal ladder at T-30 / T-14 / T-3 and on expiry, each with its own template and channel
- Win-back at +14 / +45 / +90 days post-expiry with escalating offers
- Upgrade/cross-sell: single → family, pass-holder → party booking
- Reports: active count, retention and renewal rate by plan and cohort, revenue per member,
  usage distribution, churn reasons, pass utilisation vs break-even

### 8.11 Forms & landing pages

- Field types: text, email, tel, number, date, time, select, radio, checkbox, textarea,
  hidden, consent checkbox (writes `consent_log`), file upload, child-repeater
  (name + birth date, for birthday forms)
- Each field maps to a contact/deal/booking column or a custom field via `maps_to`
- **Embed:** `<script src=".../forms/embed/{slug}.js">` (auto-resizing iframe, inherits
  nothing from the host page) or a direct hosted page at `/f/{slug}`
- On submit: spam checks (honeypot, time-to-submit, rate limit, optional CAPTCHA) →
  find-or-create contact → create deal in the configured pipeline/stage → assign by
  strategy → notify users → send autoresponder → fire `form.submitted`
- UTM and referrer captured and persisted onto both contact and deal for source reporting
- Seeded forms: Birthday Party Inquiry · Group/Field Trip Request · Corporate &
  Event Center Inquiry · Donation Request · Text Club Signup · General Contact ·
  Post-Visit Feedback · Membership Interest

### 8.12 Fundraising, donations & referrals

- Public donation-request form → `donation_requests` with status workflow and an
  **annual budget guard** (`donation_budget`): committed + requested vs budget, with a
  warning and an override permission when a decision would exceed it
- Approval issues a numbered certificate with a value and expiry; redemption is recorded
  against a visit or booking, so the Castle can report what its donations actually returned
- Referral program: per-contact codes, tracked signups, reward on a referred booking,
  advocate leaderboard

### 8.13 Tasks & team accountability

- Tasks on any entity, with priority, due date, assignee, and comments
- Auto-created by automations (e.g. "Call this lead", "Chase deposit", "Resolve detractor")
- Round-robin and load-balanced assignment honouring a user's active status
- **Daily rep digest** (6:30am): today's tasks, overdue tasks, SLA-breaching deals, today's parties
- **Weekly manager scorecard** (Monday 7am): per-rep leads, first-response median, quotes
  sent, win rate, revenue booked, stale-deal count; team leaderboard
- In-app notification centre plus optional email/SMS per notification type

### 8.14 Reports & dashboards

Report families, each filterable by date range, owner, source, pipeline, package, and
attraction, with CSV + print/PDF export and optional scheduled email delivery:

**Revenue:** bookings and revenue by day/week/month/year with YoY · revenue by booking type ·
by package · by attraction · average booking value · deposit vs balance aging ·
discount and coupon impact
**Sales:** funnel conversion by stage · velocity (days per stage) · win/loss by reason ·
rep performance · first-response time distribution · forecast vs goal · source ROI
**Marketing:** campaign performance · list growth and churn · email engagement ·
SMS deliverability and opt-out rate · Text Club redemption · attribution (first/last touch) ·
cost per booking by channel
**Guest:** new vs returning · visit frequency distribution · RFM segmentation ·
cohort retention curves · LTV by acquisition source · lapsed-guest counts · geographic
(ZIP/drive-time) and school-district breakdowns
**Operations:** bookings by day-of-week and hour · resource utilisation · host workload ·
waiver completion rate · no-show and cancellation rate · lead-time distribution
**Reputation:** NPS trend · CSAT by attraction/host · review velocity · rating trend ·
detractor resolution SLA

### 8.15 Settings & administration

Venue profile (name, address, phone, hours, logo, colours, timezone, tax rate) · Users,
roles, permissions, teams · Pipelines and stages · Attractions, packages, add-ons,
resources · Operating hours and exceptions · Membership plans · Templates · Forms ·
Automations · Segments · Email and SMS providers with test send · Deliverability (SPF/DKIM/
DMARC checker) · 10DLC registration · Quiet hours and send windows · Consent and compliance
text · Waiver versions · Coupons · Donation budget · Integrations and API tokens ·
Webhooks · Import/export · Job queue monitor · Audit log viewer · Backup and restore ·
Update checker.

---

## 9. Automation engine

The most important subsystem. Everything else is CRUD; this is what makes Castle CRM a
*playbook* platform.

### 9.1 Model

```
Automation
  ├─ Trigger        (one event type + config)
  ├─ Conditions     (entry filter, evaluated once at enrolment)
  └─ Steps[]        (ordered tree; branches via parent_step_id + branch)
       ├─ delay           (minutes | until a time of day | until N days before/after a date field)
       ├─ send_email      (template, from, reply-to)
       ├─ send_sms        (template)
       ├─ create_task     (title, assignee strategy, due offset)
       ├─ notify_user     (in-app + email/SMS)
       ├─ update_field    (contact/deal/booking field = value)
       ├─ add_tag / remove_tag
       ├─ move_deal_stage
       ├─ add_to_segment / remove_from_segment
       ├─ enroll_in_automation / exit_automation
       ├─ issue_coupon
       ├─ webhook         (POST JSON to a URL, HMAC-signed)
       ├─ condition       (if/else branch on any contact/deal/booking/engagement field)
       ├─ split_test      (percentage branch)
       └─ goal            (mark conversion and exit)
```

### 9.2 Triggers

**Contact:** created · field changed · tag added/removed · lifecycle changed · birthday
(contact or child, N days before) · consent granted/revoked · became lapsed (no visit in N days)
**Deal:** created · stage changed · won · lost · value changed · SLA breached · idle N days ·
quote sent / viewed / accepted
**Booking:** created · confirmed · deposit paid · N days before event · N hours before event ·
event completed · cancelled · rescheduled · no-show · waiver outstanding N days before
**Visit:** recorded · first-ever visit · Nth visit · spend above/below threshold
**Membership:** started · N days before expiry · expired · lapsed · usage idle N weeks · cancelled
**Communication:** email opened / clicked / bounced · SMS received · keyword received ·
conversation unanswered N hours · inbound message from unknown number
**Form & feedback:** form submitted · survey responded · NPS detractor · review received ·
review rating below threshold
**Commerce:** ticket purchased · cart abandoned · coupon redeemed · referral converted
**Fundraising:** donation request received · certificate expiring
**Time:** scheduled cron expression (for recurring blasts and the monthly Text Club offer)
**Manual / API:** enrolled by a user, by a segment, or via API

### 9.3 Execution

- `cron.php` runs each minute (or every 5 on a constrained host) and:
  1. drains due `jobs` within a wall-clock budget
  2. advances `automation_runs` where `status='waiting' AND next_run_at <= NOW()`
  3. evaluates time-based triggers (birthdays, T-minus dates, idle thresholds, cron automations)
  4. rolls up `report_daily_rollup` (nightly) and sends digests at their configured times
- Each step executes inside a transaction and writes `automation_step_log`
- A failed step retries with exponential backoff up to N attempts, then marks the run
  `failed` and notifies admins — a failure never silently stops a playbook
- **Idempotency:** a unique key on `(automation_id, contact_id)` enforces
  `run_once_per_contact`; re-entry honours `reentry_cooldown_hours`
- **Exit conditions** are evaluated before every step, so a guest who books mid-sequence
  immediately stops receiving "still thinking about it?" messages
- **Goals:** reaching `goal_event` within `goal_window_hours` records a conversion and
  exits the run, giving every automation a measurable conversion rate
- **Global guardrails:** quiet hours, per-contact message frequency cap (default 4
  marketing messages / 7 days), consent and suppression re-check at send time, and a
  **global kill switch** in settings that pauses all outbound

### 9.4 Builder UI

A vertical step list with inline branch nesting — deliberately not a free-form canvas,
which is hard to build well in vanilla JS and harder to use on a tablet. Each step is a
card: type icon, summary line, edit drawer. Features: drag to reorder, duplicate step,
disable step, live "what this does" plain-English summary, **test mode** (run against one
contact with sends redirected to the operator), and a per-step stats column (entered /
completed / goal) once live.

### 9.5 Safety

Castle CRM will send real messages to real families. Therefore:

- A new install starts in **Safe Mode**: all automations inactive, all outbound redirected
  to the owner's address, with a persistent banner. Turning Safe Mode off is an explicit,
  audited, confirm-by-typing action.
- Every automation displays its potential audience size before activation
- A send-rate ceiling per hour is configurable and enforced in the queue
- The kill switch is reachable in two clicks from every page in the admin header

### 9.6 Representative seeded automations

| Playbook | Automation | Trigger → shape |
|---|---|---|
| 01 Birthday | Instant inquiry response | `form.submitted` (birthday) → email + SMS within 2 min, task to owner, SLA timer |
| 01 Birthday | 9-touch quote nurture | `quote.sent` → day 1 SMS, day 3 email, day 5 call task, day 8 "date filling up", day 12 incentive, exit on `deal.won` |
| 01 Birthday | Deposit chase | `booking.held` and no deposit → T+1 email, T+3 SMS, T+5 release-warning, T+7 release + notify |
| 01 Birthday | Pre-party readiness | `booking.confirmed` → T-14 waiver link, T-7 headcount confirm, T-3 details + directions, T-1 SMS reminder |
| 01 Birthday | Annual rebook | child `birth_date` −45 days → "Ava turns 8 soon" email with last year's package pre-filled |
| 02 Group | School annual recurrence | `company.is_recurring_group` and last event +8 months → task + email to the teacher |
| 02 Group | Headcount firming | `booking.confirmed` (group) → T-21, T-10, T-3 headcount requests, escalate to task |
| 03 Visit | First-time visitor onboarding | `visit.first` → day 1 thank-you, day 3 "what to try next", day 10 return offer |
| 03 Visit | Weather advisory | outdoor booking T-2 and forecast risk → proactive rebooking offer |
| 04 Reputation | Feedback gate | `booking.completed` +20h → NPS; branch promoter → review ask, detractor → manager alert + task |
| 04 Reputation | Low review alert | `review.received` rating ≤ 3 → manager SMS + task with 4h SLA |
| 05 Membership | Renewal ladder | T-30 / T-14 / T-3 / expiry day, escalating offer, exit on renewal |
| 05 Membership | Unused pass nudge | no `membership_usage` in 35 days → "your pass is waiting" + attraction suggestion |
| 06 Text Club | Monthly offer | cron, 1st of month 10am → coupon to subscribed segment, respecting frequency cap |
| 06 Text Club | Slow-day flash | manual/cron → same-day offer to local ZIP segment within quiet hours |
| 06 Text Club | Lapsed win-back | `contact.lapsed` (no visit 120 days) → 3-step ladder with escalating offer |
| 07 Accountability | SLA escalation | `deal.sla_breached` → notify owner, +2h notify manager, +4h reassign round-robin |
| 07 Accountability | Weekly scorecard | cron Monday 7am → per-rep metrics email to managers |
| 08 Fundraising | Donation intake | `donation_requests` created → acknowledge, task to GM, budget check, T+7 decision reminder |
| 08 Fundraising | Certificate expiry | certificate −21 days and unredeemed → reminder to the organisation |

---

## 10. Installer

Eight steps, each independently re-runnable until completion.

| Step | Does |
|---|---|
| 1 Welcome & licence | Shows version; requires acceptance |
| 2 Requirements | Hard-checks PHP ≥ 8.1, `pdo_mysql`, `mbstring`, `json`, `openssl`, `curl`, `fileinfo`; writability of `config/`, `storage/*`; `mod_rewrite` via a live self-test request; warns on missing optional `zip`/`gd`. Blocks on any hard failure with the exact cPanel fix (e.g. "MultiPHP Manager › select 8.2"). |
| 3 Database | Host, port, name, user, password, table prefix. Tests the connection, verifies `CREATE`/`ALTER`/`INDEX` privileges, warns if the database is non-empty, offers "use existing" vs "abort". |
| 4 Schema | Runs numbered `schema/*.sql` in a transaction per file with a live progress log; records each in `migrations`. |
| 5 Venue profile | Name (default "The Castle Fun Center"), address (Chester, NY), phone, email, timezone (`America/New_York`), currency, tax rate, logo upload, brand colours. |
| 6 Owner account | Name, email, password (zxcvbn-style strength meter, 12-char minimum), optional MFA enrolment. |
| 7 Seed data | Checkboxes, all on by default: Attractions (§3.1) · Party packages (§3.3) · Add-ons · Resources · Operating hours · Pipelines & stages · 8 Playbooks · ~40 templates · 8 forms · 111 automations (inactive) · Membership plan examples · Default dashboards · Demo contacts/bookings (**off** by default). |
| 8 Finish | Writes `config/config.php` with a generated `APP_KEY`; drops `install/.lock`; shows (a) the **exact cron line** to paste into cPanel › Cron Jobs, (b) the SPF/DKIM/DMARC records for Zone Editor, (c) a reminder that **Safe Mode is on**, (d) a "delete the `install/` directory" instruction with a one-click self-delete attempt, (e) the login link. |

**Cron line shown:**
```
* * * * * /usr/local/bin/php /home/USER/public_html/cron.php >/dev/null 2>&1
```
with a documented 5-minute fallback and a token-guarded web-cron URL for hosts that
disallow CLI cron.

**Health check** (`/admin/health`, post-install): PHP version and extensions, DB
connectivity and schema version, cron last-run age (alerts if > 15 min), queue depth and
failure count, mail and SMS driver test results, DNS record status, disk usage, Safe Mode
state, pending updates.

**Updates:** a drop-in `update.php` compares `VERSION`, backs up the database to
`storage/backups/`, runs pending migrations, and clears caches.

---

## 11. UI / UX

### 11.1 Information architecture

```
Dashboard
Inbox                     (unread badge)
Contacts                  Contacts · Households · Companies · Segments · Tags · Import
Sales                     Pipelines · Deals · Quotes · Lost analysis
Bookings                  Calendar · Bookings · Run sheets · Waivers · Check-in
Marketing                 Campaigns · Templates · Forms · Text Club · Coupons · Automations
Reputation                Feedback · Reviews · Surveys · Review wall
Memberships               Members · Plans · Renewals · Usage
Playbooks                 The 8 playbooks, activation state, and their assets
Tasks
Reports
Settings
```

Sidebar navigation, collapsible to icons, with a persistent global search (`⌘K` / `/`)
that spans contacts, deals, bookings, and companies.

### 11.2 Visual language

Deliberately *not* the reference's black-and-neon-cyan "bold sports" treatment. Castle CRM
is a tool staff use for eight hours, so it optimises for legibility and low fatigue, while
nodding to the Castle's castle-and-dragon identity in accents only.

- **Light theme default, full dark theme**, driven by CSS custom properties on `:root`
  with `prefers-color-scheme` and an explicit `data-theme` override; both are first-class
- **Type:** system font stack (`-apple-system, Segoe UI, Roboto, …`) — no web font fetch,
  no layout shift, no third-party request
- **Colour:** a neutral grey scale for structure; one brand accent (deep castle purple)
  for primary actions; semantic green/amber/red reserved for state (SLA, delivery,
  sentiment) and never used decoratively
- **Density:** comfortable and compact table modes; compact is the default on list views
- **Components** (hand-rolled, ~1,400 lines of CSS total): buttons, inputs, select, toggle,
  checkbox/radio, badge, pill, card, table, tabs, modal, drawer, toast, tooltip, dropdown,
  pagination, empty state, skeleton, progress, avatar, stat tile, kanban column/card,
  timeline, calendar grid, chart frames
- **Charts:** inline SVG generated server-side or by `charts.js` (~300 lines) — line, bar,
  stacked bar, donut, gauge, sparkline. No charting library.
- **Icons:** a single inline SVG sprite sheet, ~60 icons

### 11.3 Interaction & accessibility

- Works without JavaScript for every core create/read/update/delete path; JS is progressive
  enhancement (Kanban drag has a "move to stage" `<select>` fallback)
- Keyboard: full tab order, visible focus rings, `⌘K` search, `j`/`k` list navigation,
  inbox shortcuts, `Esc` closes overlays, focus trapped and restored in modals
- **WCAG 2.1 AA:** ≥4.5:1 text contrast (≥3:1 for large text and UI boundaries), semantic
  landmarks and headings, labelled form controls with `aria-describedby` errors,
  `aria-live` for async results, `prefers-reduced-motion` honoured, no colour-only meaning
  (every status pairs a colour with a label or icon)
- Responsive from 360px: sidebar → bottom bar, tables → stacked cards, Kanban → horizontal
  snap-scroll. Front-desk and run-sheet views are explicitly tablet-first.
- Print stylesheets for run sheets, quotes, invoices, and reports

---

## 12. Integrations, API & compliance

### 12.1 Inbound integration surface

| System | Method |
|---|---|
| **CenterEdge MSSQL** (primary) | Read-only PDO connection (`pdo_sqlsrv` / `pdo_dblib` / ODBC), single-`SELECT` guarded, admin-editable queries, credentials encrypted at rest. Queries target `PlayerCardTrans`, `Sales`, `Receipt`, `ReaderSales` per §3.7. Optional capability — absent driver degrades gracefully (§8.3) |
| **CenterEdge card REST API** | SHA-1 auth with token caching, paginated fetch-all. Read: card balance, transactions, privileges, time plays. Optional write: `POST /cards/{n}` to issue a season-pass card. Capability-gated from `GET /capabilities` |
| **CEplay** | Optional. Deep-link from Castle CRM's reports into CEplay's per-game and per-station analytics rather than rebuilding them. If a shared sign-on is wanted, the seam is a signed handoff token — never a shared session or a cross-database write (§3.9) |
| **Slack** | Incoming webhook for internal alerts (detractor escalation, SLA breach, manager digest), reusing the workspace CEplay already posts to |
| Online ticketing / booking platform | Webhook in, or CSV import |
| Google Business Profile | OAuth, reviews read + reply write |
| Yelp / Facebook / TripAdvisor | API where permitted, CSV otherwise |
| Email providers | Mailgun / SendGrid / SES APIs + inbound and event webhooks |
| SMS providers | Twilio / Telnyx / Vonage; inbound + delivery-status webhooks |
| The Castle's website | Form embed script, review-wall widget, Text Club signup |
| Weather | OpenWeather (outdoor-booking advisories) |
| Calendar | iCal feeds out (per user, per resource) |
| Zapier / Make / n8n | Generic outbound webhooks + REST API |

### 12.2 REST API

`/api/v1/*`, bearer-token authenticated (`api_tokens` with per-ability scopes),
rate-limited, JSON-only, cursor-paginated. Resources: contacts, households, companies,
deals, bookings, visits, memberships, conversations, messages, tasks, forms,
form-submissions, campaigns, segments, automations (enrol/exit), reviews, reports.
Documented in `docs/API.md` with copy-paste cURL for every endpoint.

### 12.3 Outbound webhooks

Subscribe to any `Event::fire()` event. HMAC-SHA256 signature over the raw body in
`X-Castle-Signature`, timestamped against replay, retried with exponential backoff, with a
delivery log and manual replay.

### 12.4 Compliance

| Area | Position |
|---|---|
| **TCPA / SMS** | Express written consent captured with timestamp, source, IP, and the exact disclosure text in `consent_log`; double opt-in for Text Club; STOP honoured instantly and irreversibly without a human in the loop; quiet hours; 10DLC registration tracked |
| **CAN-SPAM** | Physical address and working one-click unsubscribe in every bulk email, enforced by the template validator; unsubscribes suppress within the same request |
| **Minors** | Children's records hold only what party operations need (name, birth date, allergies). Castle CRM never markets to a child: every message about a child's birthday addresses the **guardian** contact. No child record is ever a message recipient — enforced in the send path, not just by convention. |
| **Waivers** | Versioned waiver text; a signature stores the signed version, signer identity, guardian flag, listed minors, IP, UA, timestamp, and the signature image — so a signature remains defensible after the text changes |
| **PCI** | Castle CRM **does not store card data.** Payments are taken in the POS or a hosted processor page; Castle CRM records amount, method, and an opaque reference only. No PAN, CVV, or expiry field exists in the schema. |
| **Privacy** | Per-contact data export and erase; configurable retention (auto-purge inactive non-customer leads after N months); documented data inventory in `docs/PRIVACY.md` |
| **Audit** | Immutable `audit_log`; viewer with filtering; consent and send decisions recorded with the reason |

---

## 13. Delivery plan

| Phase | Scope | State |
|---|---|---|
| **0** | This specification | ✅ |
| **1** | Foundation: installer, full schema, auth/RBAC, routing, UI shell, dashboard, settings, cron, job queue, audit | **in progress** |
| **2** | Capture & Convert: contacts, households, companies, pipelines, deals, quotes, forms, tasks | |
| **3** | Bookings: attractions, packages, availability engine, quote calculator, bookings, calendar, run sheets, waivers, check-in | |
| **4** | Communicate: mail/SMS drivers, templates, inbox, segments, campaigns, Text Club, usage ledger | |
| **5** | Automate: engine, builder UI, 111 seeded automations, playbook registry and activation | |
| **6** | Improve: surveys, review routing, review inbox, review wall, memberships, full reporting suite | |
| **7** | Integrate: CenterEdge MSSQL import, card API, GBP, Slack, webhooks, REST API, iCal, weather | |
| **8** | Harden: security review, accessibility audit, load test at 50k contacts, docs, backup/restore, update channel | |

> **Phasing risk.** Phase 7 carries the CenterEdge integration, but open questions 3 and 6
> (§14) could invert the plan. If guest email/phone coverage in the POS turns out to be
> near zero, the import is worth far less than list-building, and Phases 4–6 are correctly
> ordered. If coverage is good *and* an MSSQL driver is reachable, the import should be
> pulled forward to Phase 3 — tens of thousands of guests with real visit history would
> make every later playbook land harder. **Answer question 3 before committing to Phase 4.**

### 13.1 Acceptance criteria for Phase 1

1. A clean cPanel-like environment installs successfully through the web installer in
   under 5 minutes with no shell access.
2. The installer blocks on a genuinely unmet requirement and explains the cPanel-level fix.
3. All schema files apply cleanly and are idempotent on re-run; `migrations` is populated.
4. An owner can log in, see a dashboard, and change settings; a Read Only user cannot.
5. Every mutating request without a valid CSRF token is rejected.
6. `cron.php` runs, logs a heartbeat, and the health page reports cron freshness.
7. `php -l` passes on every PHP file; no PHP notices/warnings at `E_ALL` on any page.
8. Safe Mode is on and visibly indicated.

### 13.2 Quality gates (every phase)

- `php -l` clean across the tree; `E_ALL` with zero notices
- No string-interpolated SQL (grep-enforced in review)
- Every new output path escaped; every new mutating route CSRF-protected
- Every new permission registered and enforced in both middleware and controller
- Keyboard-reachable and screen-reader-labelled new UI; AA contrast verified
- `docs/CHANGELOG.md` updated

---

## 14. Open questions for the Castle

These are seeded with defaults so nothing blocks, but confirming them improves accuracy.
Questions resolved by the CEplay codebase are marked **answered** and kept for the record.

| # | Question | Status |
|---|---|---|
| 1 | **POS system** | **Answered** — CenterEdge Advantage, with a Kiosoft-derived card REST API and a read-only MSSQL reporting path (§3.7) |
| 2 | **Timezone / locale** | **Answered** — `America/New_York` |
| 3 | **Does CenterEdge hold usable guest email and phone?** | **Open, and the most important question in this document.** `CustomerInfo` is write-side only (§3.8). Someone needs to run an Explorer query against the customer tables and report the **fill rate** for email and phone. If it is near zero, Castle CRM's first job is list-building, and the Text Club and forms matter more than the import |
| 4 | **Party/event booking in CenterEdge** | **Partly answered** — the POS clearly takes party deposits and event payments. Open: does Advantage expose a structured reservation record (room, time, package, headcount) that Castle CRM can read, or only the money? This decides whether §8.5's booking module mirrors the POS or runs ahead of it |
| 5 | **`Receipt.EventType` codes 28 / 74 / 90 / 92 / 96** | Open — CEplay flags these as unverified. Needed to attribute party revenue correctly in §8.3 source 2 |
| 6 | **MSSQL driver on the Castle CRM host** | Open — is `pdo_sqlsrv`/`pdo_dblib`/ODBC available where Castle CRM will live, and can that host reach the POS server? If Castle CRM sits on cPanel shared hosting the answer is probably no, and the POS feed needs a push from the venue side instead of a pull |
| 7 | **Season pass / membership** | Open — does a pass product exist today? CEplay's tender list includes a "Member" type, which hints at one |
| 8 | **SMS** | Open — existing Twilio account and a registered 10DLC brand, or start fresh? Carrier lead time is 2–4 weeks and gates Playbook 06 |
| 9 | **Sending domain** | Open — which domain/subdomain sends mail, and who controls DNS? |
| 10 | **Current party prices and the 7-day notice rule** | Open — §3.3 came from the public site and needs confirming |
| 11 | **Team shape** | Open — headcount, roles, and who owns party vs group inquiries |
| 12 | **Event Center** | Open — separate pipeline from corporate groups? Weddings behave nothing like a field trip |
| 13 | **Donation budget** | Open — annual figure and approver |
| 14 | **Existing lists** | Open — what contact data exists today, in what format, how large, and with what consent provenance |
| 15 | **CEplay relationship** | Open — shared sign-on wanted, or two separate logins? And should Castle CRM deep-link into CEplay's analytics or stay self-contained? |

---

## 15. Appendix: glossary

| Term | Meaning |
|---|---|
| **FEC** | Family Entertainment Center |
| **Playbook** | A ready-built bundle of pipeline, forms, templates, and automations for one revenue moment |
| **Module** | A software capability (inbox, pipelines, automations) that playbooks are built from |
| **Automation** | A trigger → condition → steps sequence that runs without a human |
| **Run** | One contact's journey through one automation |
| **Goal** | The conversion event an automation is trying to cause |
| **Safe Mode** | Install state where all outbound messaging is redirected to the owner |
| **Text Club** | The opt-in SMS marketing list |
| **Castle Card** | The Castle's stored-value card used to pay for attractions |
| **CenterEdge Advantage** | The Castle's POS and card system; also the system of record for party reservations and deposits |
| **CEplay** | The Castle's existing in-house app: arcade automation plus POS analytics. Castle CRM's sibling, not its parent (§3.8) |
| **Deferred value** | A card load is stored value, not a sale — so it appears in the card ledger and never in `Sales` (§3.2) |
| **`PlayerCardTrans`** | The CenterEdge card ledger: ~20 years of loads, plays, tickets, and adjustments. Castle CRM's primary visit source |
| **Identified-and-consented guest** | A contact with a reachable channel *and* a logged consent record. Castle CRM's real growth metric, as distinct from raw contact count |
| **Run sheet** | The printed operational brief a party host works from |
| **10DLC** | Carrier registration required to send A2P SMS from a US local number |
| **SLA** | The response-time commitment on a lead or task, tracked and escalated |
