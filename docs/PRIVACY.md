# Data, consent and privacy in Castle CRM

This document is for the venue, not for lawyers. It says what personal data
Castle CRM holds, where it came from, who inside the venue can see it, how
long it is kept, and what the software will refuse to do.

It is not legal advice, and it is not a privacy policy you can publish —
you need one of those too, written for your guests, and it should describe
your actual practice rather than this software's defaults. What this document
gives you is the factual basis to write it from.

**Jurisdiction.** The Castle Fun Center is in New York. The rules that
actually bite are federal: **CAN-SPAM** for email, the **TCPA** for text
messages, and **COPPA** for anything to do with children's data. New York's
SHIELD Act applies to the security of the data. If you take bookings from
guests outside the US, GDPR-style rights may apply to those guests; the export
and erasure features here exist partly for that.

---

## 1. What is held, and why

### Guests

| Data | Why it is held | Where it comes from |
|---|---|---|
| Name | to address a guest, and to find their booking | booking form, POS import, staff entry |
| Email address | confirmations, receipts, marketing (with consent) | the same |
| Phone number | reminders, text club (with consent) | the same |
| Postal address, city, ZIP | catchment analysis, direct mail | booking form, POS |
| Visit dates and spend | identifying lapsed guests, measuring a campaign | derived from the POS |
| CenterEdge card number | the key that joins a guest to their POS history | CenterEdge Advantage |
| Consent flags and their evidence | proof that contacting them is lawful | consent events, see §3 |
| Notes staff write | operational memory — a complaint, a preference | staff entry |

### Children

Castle CRM stores a child's **first name** and **date of birth**, and
optionally **allergies** and notes, attached to a guardian's record.

This is deliberate and deliberately minimal. The date of birth drives the
birthday campaign, which is the venue's single largest revenue mechanism; the
allergies field is printed in bold on the party run sheet, because getting it
wrong is the worst thing that can happen at a children's venue.

What is **not** stored: a child's surname, their own email address, their own
phone number, their photograph, or any contact route that reaches the child
rather than the guardian.

**Every message about a child goes to the guardian.** There is no path in the
software that sends anything to a child, because there is no channel stored
for one. That is the structural answer to COPPA: the software does not collect
personal information from children online, and cannot contact them.

### Staff

Name, email address, a password hash (Argon2id), role, and the audit trail of
what they did. The audit trail is the point: it is what makes "who exported
the guest list?" an answerable question.

### What is never stored, anywhere

- **Card numbers, CVVs, expiry dates.** Not in a column, not in a note, not in
  a log. Payments are taken in the POS or on a hosted page; Castle CRM holds
  only an opaque reference. There is a test in the suite that fails the build
  if a schema column looks like it could hold card data. PCI scope is avoided
  structurally rather than by policy.
- **Social Security numbers, driver's licence numbers, bank details.**
- **Passwords in readable form**, including a staff member's.
- **Precise location.** ZIP codes, not coordinates.

---

## 2. Where guest data comes from — and what that implies

Four routes, with very different consequences.

**1. A guest gave it to you.** A booking form, a waiver, a text-club sign-up.
The guest knows you have it. If the form said what you would use it for, you
have consent for that.

**2. Staff typed it in.** A phone inquiry, a walk-in. The guest knows you have
it, but there is no record of what they were told.

**3. It came from the POS.** A CenterEdge card registration, a receipt. **This
is the important one.** A guest who bought a wristband and typed an email
address into a kiosk has not agreed to receive your marketing. They may not
remember giving it to you at all.

So **POS-derived contacts arrive with no consent**, and Castle CRM will not
market to them. They can be called, and they can be sent transactional mail
about something they bought, but they cannot be put in a campaign until
consent is recorded.

**4. It was imported from a spreadsheet.** A list from whatever you used
before Castle CRM. **This is the one with the least evidence attached**, and it
is treated accordingly.

A file exported from another system says nothing about whether those people
agreed to be emailed. The column that looks like it does — `Opt-In`,
`Subscribed`, `Newsletter` — is very often a default somebody set once, years
ago, for everybody in the database. So:

- **Imported contacts arrive consented to nothing**, whatever the file says.
- A consent column IS honoured, but only if you tick a box affirming that you
  hold records of those people agreeing. That tick is audited with your name
  and the time, because "who said these people agreed?" is the question a
  complaint turns into.
- Even then, only a row whose consent column actually says yes is consented,
  the date comes from the file where there is one, and **no date is invented**
  — a consent with no date is recorded as a consent with no date, because the
  date is what a complaint is answered with.
- A `Do Not Contact` column is **always** honoured, ticked or not. Those
  guests are imported and never messaged.
- Importing cannot change a consent that already exists, in either direction.
  The record here was made with evidence — a form, a double opt-in text — that
  a spreadsheet does not have, so a file can neither grant one nor take one
  away.

This is why the dashboard's headline growth number is **identified and
consented guests**, not total contacts. A contact list that cannot be
contacted is not an asset, and counting it as one is how a venue ends up
sending a campaign it is not entitled to send.

---

## 3. Consent, and the evidence for it

Consent is stored **per channel** — email and SMS separately — with the time
it was given, where it came from, and the exact wording the guest agreed to.

The wording matters more than the tick. TCPA compliance turns on **what the
guest was shown**, and "we have a flag set to 1" is not a defence. So every
consent event writes a row to `consent_log` holding:

- which channel, and whether consent was **granted** or **revoked**
- the source — which form, which keyword, which staff member
- the IP address and browser, for a web form
- the **disclosure text as displayed**, stored as evidence

Those rows are append-only. There is no edit, and no delete.

### Withdrawal

Every marketing email carries a working unsubscribe link, and supports
one-click unsubscribe (RFC 8058) so a guest's own mail provider can do it for
them. Unsubscribing:

1. adds the address to the **suppression list**, which is checked on every
   send regardless of what any campaign says;
2. clears the contact's email consent and timestamps the opt-out;
3. writes a **revoked** row to `consent_log`, with the time, the IP and the
   method.

It needs no login, works on the first click, and is idempotent — a mail
client that prefetches the link does no harm.

For SMS, the keywords **STOP**, **STOPALL**, **END**, **CANCEL**, **QUIT**
and **UNSUBSCRIBE** all unsubscribe, and **HELP** and **INFO** return help
text. These are seeded and mandatory.

### What stays after withdrawal

Transactional messages. If a guest unsubscribes and then books a party, they
still get the confirmation, the waiver link and the reminder — those are the
service they bought, not marketing, and withholding them would be worse
service, not better privacy.

The unsubscribe confirmation page says this in as many words, so nobody is
surprised.

---

## 4. Who can see what

Permissions are data, not code, and they fail closed: a permission that is not
granted is denied, and a visibility scope that cannot be resolved produces a
query that returns nothing rather than everything.

Three default roles, by way of illustration:

| Role | Guest data | Export | Backups |
|---|---|---|---|
| Owner | everything | yes | yes |
| General manager | everything | yes | yes |
| Sales manager | their team's | yes | no |
| Party host | the parties they are assigned | no | no |
| Front desk | lookup only, one guest at a time | no | no |
| Read-only | as scoped, no changes | no | no |

The distinction that matters for privacy is **one record at a time** versus
**all of them at once**. A front-desk account can look up the family standing
in front of them; it cannot download the guest list. Bulk export and backups
carry their own permission (`backup_manage`), held by two roles by default,
and every export and every backup download is written to the audit log with
the actor's name, the table and the row count.

That log is the difference between a privacy incident you can describe and one
you cannot.

A separation like that only holds if the account holding it cannot be taken
over by somebody who does not. It could be: a non-owner holding `users_manage`
was able to set the **Owner's** password — leaving the Owner's role untouched,
so the check on which roles you may grant never fired — and then sign in as
them, inheriting `backup_manage` and everything else. That is fixed: you may
only edit, deactivate or delete an account whose role is a subset of your own
permissions, and only an Owner may act on the Owner. The rule is enforced in
`Acl::mayManageUser()` and tested end to end over HTTP, including the check
that a manager can still reset a front-desk password — a fix that broke
ordinary support would be worse than the hole.

---

## 5. Retention

| Data | Default | Configurable | Notes |
|---|---|---|---|
| Guest records | kept while the relationship exists | — | deleted on request, see §6 |
| Visit and spend history | kept | — | the basis of every report |
| Messages sent and received | kept | — | the record of what you told a guest |
| Consent records | **kept as long as the contact exists** | no | deliberately not prunable: it is the evidence |
| Audit log | 730 days | yes, Settings → System | 0 keeps it forever |
| Sign-in sessions | 8 hours idle, 30 days absolute | yes, config | pruned nightly |
| Password reset tokens | 1 hour | — | pruned nightly |
| Completed background jobs | 7 days | — | kept briefly for inspection |
| Database backups | newest 7 | yes, Settings → Backups | each one holds everything above |

Two deliberate asymmetries:

**Consent records are never pruned by a retention setting.** Shortening the
audit window should not quietly destroy the proof that a guest agreed to be
contacted. They go when the contact goes.

**Backups extend every other retention period.** A guest erased today is still
in last week's backup. That is unavoidable and worth being honest about: the
practical answer is that backups are retained for weeks, not years, and that
an erasure is re-applied if a backup is ever restored. Note it in your own
privacy policy rather than claiming an immediate erasure you cannot deliver.

---

## 6. A guest's requests, and how to answer them

**"What do you have about me?"** — Settings → Backups → *Export a table as
CSV*, then filter to that guest. Needs `backup_manage`.

**"Stop emailing me."** — the unsubscribe link does it. Staff can also set the
contact's consent to off, which writes the same evidence.

**"Stop contacting me entirely."** — set **do not contact** on the record. That
flag overrides every consent flag, every channel and every message kind,
transactional included. It is the one switch that stops everything.

**"Delete me."** — soft-delete removes the record from every list and report
while keeping referential integrity for historical bookings. **A hard erase
is not built yet.** The permission for it (`contacts_erase`) is in the
catalogue, and this page said until October 2026 that the erase existed; it
did not, and nothing used the permission. Until it is built, the honest
answer to an erasure request is: soft-delete the record, set **do not
contact** before deleting it so no import or automation can reach the
address again, and tell the guest that booking and payment history is kept
for the venue's records. Decide before you are asked which your policy
promises: a venue that promised deletion and performed a soft delete has
made a false statement.

**"Correct my details."** — ordinary editing. Every change is audited with
the before and after values.

---

## 7. What the software refuses to do

Castle CRM is built so that the expensive mistakes are difficult rather than
merely discouraged.

- **Safe Mode is on at install.** Every message is redirected to the owner. A
  fresh install cannot contact a guest at all. Leaving Safe Mode needs a
  separate permission and typing the venue's name to confirm, and is audited.
- **Marketing without consent is refused in the send path**, not in the user
  interface. A controller, a campaign or a script cannot opt out of the check.
- **A suppressed address is never written to**, for any message kind,
  transactional included. A hard bounce or a spam complaint means that address
  is finished.
- **Quiet hours** are enforced in venue-local time. Nothing marketing goes out
  at 3am.
- **A weekly cap per guest** applies across every campaign and automation at
  once, so two sequences cannot combine into a daily barrage.
- **A global pause** stops all outbound sending immediately, queueing rather
  than failing.
- **Bulk email always carries** the venue's postal address and a working
  unsubscribe route, appended in the send path so a template cannot omit them.
- **A credential is never rendered back** into a settings form, and is
  encrypted at rest with a key held in a file outside the database.

---

## 8. Security, briefly

- Passwords: **Argon2id** (bcrypt where the host lacks it), never recoverable.
- Sessions: stored server-side in the database, regenerated on sign-in, idle
  and absolute timeouts, revocable per device by the account holder.
- Stored credentials: **AES-256-GCM**, keyed from `config/config.php`.
- Every form: CSRF-protected by middleware, so a new route is protected by
  default rather than by remembering.
- Every query: prepared statements. The test suite fails the build on a string
  interpolated into SQL.
- Output: escaped by default in views.
- Headers: a strict Content-Security-Policy with no inline script,
  `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, and HSTS
  once HTTPS works.
- HTTPS: optional at install (a new domain has no certificate yet) and
  enforceable from Settings → System once AutoSSL has run. Turn it on.
- Rate limits: on sign-in, password reset and the public unsubscribe endpoint.
- Backups: written outside the web root's servable paths, guarded by their own
  `.htaccess`, downloadable only with `backup_manage`, and every download
  audited.

### If something goes wrong

New York's SHIELD Act requires notification of a breach of private
information. Castle CRM gives you three things to answer with: the **audit
log** (who did what, and when), the **message log** (what was sent to whom),
and the **consent log** (what each guest had agreed to). Keep the audit
retention long enough to be useful — two years is the default for that reason.

---

## 9. For your own privacy policy

Things the venue must decide, which this software cannot decide for you:

1. **How long do you keep a guest record after their last visit?** The software
   keeps it indefinitely. "Indefinitely" is a choice you should make on purpose
   and state plainly.
2. **Does "delete me" mean soft or hard?** Both are available. Promise the one
   you will actually do.
3. **What does your booking form say** about marketing? That wording is your
   consent, and it is stored as evidence. Make it specific: "email me about
   offers and events" is consent; a pre-ticked box next to the word "updates"
   is a complaint waiting to happen.
4. **Who at the venue holds `backup_manage`?** That is the list of people who
   can walk out with the whole guest database.
5. **Where do downloaded backups live?** A copy on a staff laptop in a
   Downloads folder is the most likely way this data ever leaks.
6. **What do you tell parents about their child's date of birth?** You hold it,
   you use it to sell them a party, and they should know both.
