# What Castle CRM looks like

One picture per section, so somebody can see the application without
installing it. **39 images, 7615 KB in all.**

Taken by `tools/screenshots.js` against a demo install. Three things to know
before reading them:

- **These are a snapshot, not a baseline.** The data is relative to the day the
  capture ran, so two runs differ in every date and figure. Nothing compares
  against these images and no test will fail if they change — they document
  what the application looks like, and that is all.
- **Nothing real appears in any of them.** `tools/screenshot-guard.php` runs
  first and refuses the capture unless every detail of every contact is one a
  real person's record could not carry: an email on a domain the standards
  reserve for fiction and a phone in the reserved 555-0100 to 555-0199 block.
  A contact with neither is refused, since that is the ordinary shape of a
  guest who arrived by text, and so is any booking or Text Club number that is
  not plainly fictional. It also refuses a user account on a real email
  domain (the user list is one of these pictures, and the signed-in name is in
  the sidebar of all of them) and a real SMTP host or from-address (the email
  settings page shows them in full). The check is on the data, not on a flag
  somebody set.
- **The figures are real arithmetic over fake data.** Nothing here is a mockup:
  every total was computed by the application from the rows behind it.

To retake them: start a dev server, then `node tools/screenshots.js`.

## Sections

| Section | Path | What to look at |
|---|---|---|
| [Sign in](login.png) | `/login` | Rate limited, with a reset flow that works even when no mail provider is configured — the link goes to the log. |
| [Command Center](dashboard.png) | `/` | Today at a glance: the parties on, who is arriving, what needs doing. The year-over-year card sits here. |
| [Unified inbox](inbox.png) | `/inbox` | Email and SMS in one thread per guest, assignable and closeable. |
| [Contacts](contacts.png) | `/contacts` | Every guest, searchable by name, phone or email, with lifetime value and visit count derived rather than typed. |
| [One guest](contact-detail.png) | `/contacts?page=1` | Visit history, consent with its evidence, bookings, notes and the full audit of what changed. |
| [Households](households.png) | `/households` | Families grouped, so a parent and three children are one relationship rather than four strangers. |
| [Pipelines](deals.png) | `/deals` | The sales board. A stage is a transition: entering one stamps its clock, sets won or lost, and fires the follow-up. *(the top 3000px of a 5027px page)* |
| [Bookings](bookings.png) | `/bookings` | Every party, with the money ladder — subtotal, discount, tax, deposit, balance — held as integer cents. |
| [Calendar](calendar.png) | `/calendar` | Month, week and day, with resource lanes. Also published as an iCal feed. |
| [Check-in](checkin.png) | `/checkin` | The desk view on the day: who is here, who has signed a waiver, who still owes a balance. |
| [Memberships](memberships.png) | `/memberships` | Season passes as a lifecycle, not a flag — freezing gives the time back, renewing starts from the current end date. |
| [Campaigns](campaigns.png) | `/campaigns` | Compose, estimate the audience, send in chunks on the cron, then read what it did. |
| [Segments](segments.png) | `/segments` | Rules compiled to SQL, with a live count before anything is sent. |
| [Text Club](text-club.png) | `/text-club` | Double opt-in by law and by design, with the offer calendar and per-guest caps. |
| [Playbooks](playbooks.png) | `/playbooks` | Eight revenue playbooks. Each ships switched OFF; turning one on is a deliberate act. |
| [Automations](automations.png) | `/automations` | 112 seeded sequences, every one inactive until somebody activates it. A trigger that can never fire is a build failure. *(the top 3000px of a 9804px page)* |
| [Reputation](reputation.png) | `/reputation` | Surveys, the feedback gate that routes an unhappy guest to a person instead of a review site, and the review inbox. *(the top 3000px of a 4440px page)* |
| [Tasks](tasks.png) | `/tasks` | What the team owes, assignable, with due dates automations can set. |
| [Reports](reports.png) | `/reports` | 23 reports in six families. Actuals only — no projections, no averaged baselines, and null is never shown as zero. |
| [A report](report-revenue.png) | `/reports/revenue_by_day?period=last_90_days` | Revenue by day over ninety days, with CSV export and the prior period compared honestly. |
| [Waivers](waivers.png) | `/waivers` | Versioned: a signature records WHICH wording the guest agreed to, because that is the point of a waiver. |
| [Forms](forms.png) | `/forms` | Embeddable web forms that create a guest and a lead, with consent captured as evidence. |
| [Imports](imports.png) | `/imports` | CSV import for guests and visit history. Dry run first; it refuses a file rather than half-importing it. |
| [Settings](settings.png) | `/settings` | The whole configuration surface, each tile its own permission. |
| [Email settings](settings-mail.png) | `/settings/mail` | The driver, Safe Mode, and the DNS records — which name whoever actually sends your mail, rather than assuming it is this server. |
| [Users and roles](settings-users.png) | `/settings/users` | Roles are data, permissions are code. Each permission carries a scope: all, team, or own. |
| [Health](settings-health.png) | `/settings/health` | The cron heartbeat, the queue, the mail driver, the POS driver. The first page to open when something feels wrong. |
| [Audit log](settings-audit.png) | `/settings/audit` | Every change with before and after values. How you answer "who moved that booking". |
| [API tokens](settings-api.png) | `/settings/api-tokens` | Scoped bearer tokens. Read, write and delete are separate ticks, because they are separate permissions everywhere else. |
| [Backups](settings-backups.png) | `/settings/backups` | Nightly backups, CSV export, and removing the installer's example guests once you have finished looking at them. |

## Dark mode

The theme is one stylesheet, so a representative few rather than all of them.

| Section | Path |
|---|---|
| [Command Center](dashboard-dark.png) | `/` |
| [Contacts](contacts-dark.png) | `/contacts` |
| [Pipelines](deals-dark.png) | `/deals` |
| [Calendar](calendar-dark.png) | `/calendar` |
| [Reports](reports-dark.png) | `/reports` |
| [Health](settings-health-dark.png) | `/settings/health` |

## On a phone

Check-in is the page floor staff actually hold, so it leads. Three are
photographed here; **all 29 sections were measured at 390px and none
of them scrolls sideways** — the capture asserts that rather than leaving it
to be looked at.

| Section | Path | What to look at |
|---|---|---|
| [Check-in (phone)](phone-checkin.png) | `/checkin` | The page the desk holds on the day of a party: who is here, who has signed, who still owes a balance. |
| [Command Center (phone)](phone-dashboard.png) | `/` | The dashboard at 390px. No page in this application scrolls sideways on a phone. |
| [Bookings (phone)](phone-bookings.png) | `/bookings` | A wide table on a narrow screen: it scrolls inside its own container rather than dragging the layout. |
