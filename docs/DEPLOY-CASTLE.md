# Deploying Castle CRM at The Castle Fun Center

`INSTALL.md` is the general guide. This is the same job with the Castle's own
pieces named, in the order they have to happen, because three of the steps
depend on facts about this venue's setup that a general guide cannot assume.

**The setup as given:** the domain is `thecastlefuncenter.com`, the hosting is
cPanel, mail is **Microsoft 365** (bought through GoDaddy), and DNS is at
**Cloudflare**. CEplay already runs on-site with read-only access to the
CenterEdge POS database.

Three consequences follow, and they are the reason this file exists:

1. **cPanel's Email Deliverability → Repair is not your shortcut.** Your DNS
   is at Cloudflare, so cPanel cannot write to the zone. Follow step 5.
2. **Your domain already has an SPF record**, for Microsoft 365. It must not
   be replaced. See step 5.
3. **The POS feed cannot be a pull.** A shared host cannot reach a server on
   your LAN. See step 8.

---

## Trying it before connecting it to anything

**You can install this, exercise every page, and have it reach nothing at
all.** That is the shipped default rather than a mode you have to find, and
these are the facts behind it — each one verified against the code and a real
install, not a claim about intent:

| | Ships as | What that means |
|---|---|---|
| **Safe Mode** | **on** | Every outbound message is redirected to the owner with the intended recipient noted at the top. A finished install **cannot contact a guest at all.** |
| **Mail driver** | **`log`** | Nothing leaves the server. Messages are written in full to `storage/logs/` instead. A password reset still works — the link is in the log. |
| **The 112 automations** | **all off** | Measured on a real install: 0 of 112 active, 0 of 8 playbooks active. And an automation inside an inactive playbook does not run even if its own switch is on. |
| **POS connection** | **none** | Nothing is configured, and this host has no MSSQL driver. **Settings → Health** reports its absence. It cannot reach your POS. |
| **API tokens** | **none exist** | Until you create one. No token, no API access — a signed-in browser session is refused by `/api/v1` outright. |
| **Webhooks** | **none** | Nothing is sent anywhere until you add an endpoint. |
| **Your guest data** | **not there** | Only what you import. Import nothing and there is nothing. |

### The tyre-kicking configuration

Do runbook steps **1 → 4** (upload, database, installer, cron), and at the
installer's seed step:

- ✅ **The Castle's operating shape** — attractions, packages, pipelines. No
  guest data; this is what makes the application look like your venue rather
  than a blank grid.
- ✅ **The playbooks and automations** — they arrive switched off, and seeding
  them now means you can read what each one would do before deciding.
- ✅ **Example guests and bookings** — *off by default, and worth turning ON
  for a trial.* Every row is tagged `demo-data` so you can delete the lot in
  one action, and none of it can be contacted. It is the difference between
  judging the reports on an empty table and judging them on something that
  looks like a Saturday.

Then **stop**. Skip steps 5 (mail), 7 (importing your lists) and 9 (leaving
Safe Mode). You now have the whole application, populated, reaching nothing.

### What is worth exercising in that state

- **Take a booking** end to end: the public `/book` page → the enquiry in the
  pipeline → quote → deposit → the run sheet → check-in.
- **Open the calendar** on month, week and day, and the resource lanes.
- **Read a report** and try to reconcile it by hand. Every figure is something
  that actually happened — no projections, no averaged baselines — so a number
  you cannot reconcile is a bug worth telling us about.
- **Click through the automations** and read the step sequences. This is the
  cheapest time to decide which playbooks you actually want.
- **Try Check-in on a phone.** It is the page the desk holds on the day of a
  party, and the one worth a real device rather than a desktop browser.
- **Build a role** in Settings and sign in as it. Hiding a section removes the
  nav item, bounces the route *and* closes the data behind it — worth seeing
  before you decide what the front desk can reach.
- **Send yourself a password reset** and fish the link out of
  `storage/logs/`. It proves the mail path works before any mail can leave.

### Then throw the demo data away

**Settings → Backups → Remove the example data** deletes everything the seed
created — guests, households, children, visits, deals, activities, tasks and
bookings — in one action. It tells you the count first, asks you to type
DELETE, and is recorded in the audit log. Do it before you import a single
real guest, so there is never a question about which rows are which.

It will NOT delete a booking or a deal you created yourself against an example
guest; those are yours, so they survive with no guest attached, and the page
says how many before you press anything. The card only appears while there is
something to remove.

---

## 1. Before you start

Have these to hand:

- cPanel login
- Cloudflare login for `thecastlefuncenter.com`
- Microsoft 365 admin login (to enable SMTP AUTH and check DKIM)
- your email lists as CSV
- about two hours, and ideally not a Saturday

Check the PHP version available in cPanel → **MultiPHP Manager**. Castle CRM
needs **8.1 or newer**. If only 8.0 is offered, stop here and ask the host —
nothing else in this guide will work.

---

## 2. Upload, and create the database

Follow `INSTALL.md` §1–§3. Nothing about those steps is Castle-specific. In
short:

1. Upload the files to the directory the domain serves.
2. cPanel → **MySQL Databases**: create a database and a user, and grant the
   user **all privileges** on it. Note all three values.
3. Make `storage/` and `config/` writable if the installer complains.

A subdomain is worth considering — `crm.thecastlefuncenter.com` — so the CRM
is separate from the public website and can be moved independently later.
Decide now; changing it afterwards means a new certificate and new links.

---

## 3. Run the installer

Open the site in a browser. The installer takes eight steps and checks its own
prerequisites as it goes.

Two answers that matter:

- **Timezone: America/New_York.** Every report groups by the venue's own
  calendar day, so a 9pm Saturday booking counts as Saturday's. Getting this
  wrong puts late-evening revenue on the wrong day, for ever.
- **Seed data: yes, seed the venue's own shape.** The attractions, packages,
  pipelines and the 111 automations all come from the seed. The automations
  arrive switched **off**; nothing sends until you turn a playbook on.

The final page shows the cron line and tells you Safe Mode is on. **Copy the
cron line before leaving that page** — it is the one thing you cannot get
back easily.

---

## 4. The cron line, then confirm it is running

cPanel → **Cron Jobs**, every minute, paste the line from the installer.

Then open **Settings → Health**. The cron heartbeat should show a time within
the last minute or two. If it says the cron has never run, nothing else in
this application works on a schedule: no campaigns, no automations, no
nightly rollup, no backups. Fix it before going further.

Castle CRM's whole scheduler is one cron line by design. If your host refuses
per-minute cron, `INSTALL.md` §5 has the fallback.

---

## 5. Mail and DNS — the step to be careful with

### 5a. In Microsoft 365, before touching DNS

1. Decide which mailbox sends. A dedicated one is better than a person's:
   `crm@thecastlefuncenter.com` or `parties@thecastlefuncenter.com`.
2. **Enable SMTP AUTH for that mailbox.** Microsoft disables it by default,
   both tenant-wide and per-mailbox, and this is the single most common reason
   a correct-looking SMTP setup refuses to authenticate.
3. If that account has MFA — it should — create an **app password** for it.
   The normal password will not authenticate over SMTP.
4. While you are in there, check whether **DKIM** is enabled for the domain
   (Defender portal → Policies → Email authentication). If it is not, turn it
   on; it will give you two CNAME targets for step 5c.

### 5b. In Castle CRM

**Settings → Email**:

| Field | Value |
|---|---|
| Driver | SMTP |
| Host | `smtp.office365.com` |
| Port | 587 |
| Encryption | STARTTLS |
| Username | the mailbox from 5a |
| Password | the app password from 5a |
| From address | the same mailbox |
| From name | The Castle Fun Center |

Save. The DNS table on that page will now name **Microsoft 365** as your
sender and print the records for it. Use those, not the ones in any older
version of the guide.

Do not press **Send a test** yet — do step 5c first, or the test lands in
spam and tells you nothing.

### 5c. In Cloudflare

**Read this whole subsection before changing a record.**

**SPF.** You almost certainly already have `v=spf1
include:spf.protection.outlook.com -all` or similar on the root domain.
**Leave it alone.** Because you send through Microsoft, Microsoft is already
authorised, and there is nothing to add. If you find no SPF record at all,
add exactly the one Castle CRM's page shows.

> A domain publishes **one** SPF record. Two records fail both. Replacing the
> Microsoft record stops every email your company sends — staff mail,
> everything — not just the CRM's. If you are ever unsure, change nothing and
> check first.

**DKIM.** Add the two CNAMEs Microsoft gave you in 5a:
`selector1._domainkey` and `selector2._domainkey`.

**DMARC.** If there is no `_dmarc` TXT record, add the one Castle CRM's page
shows — it starts at `p=none`, which reports without rejecting anything.
Leave it there for a few weeks, read the reports, and only then consider
tightening it.

**Then the Cloudflare-specific trap:** set **every** mail-related record to
**DNS only** — the grey cloud. Not proxied, not the orange cloud. A proxied
DKIM record silently fails and nothing anywhere tells you why.

### 5d. Now test

Back in **Settings → Email**, press **Send a test**. If it fails to
authenticate, it is SMTP AUTH (5a step 2) nine times out of ten.

Expect DNS to take a few hours to propagate. Test again tomorrow before
concluding anything is broken.

### 5e. Know the ceiling

Microsoft 365 accepts roughly **30 messages a minute** and **10,000
recipients a day**. Castle CRM sends in chunks on the cron, so these throttle
it rather than break it — a 4,000-person campaign simply takes a while.

**But bulk marketing from a company mailbox risks that mailbox's reputation,
and it is the same reputation your staff's own email depends on.** This setup
is right for transactional mail: password resets, booking confirmations, run
sheets, survey invitations. Before you send campaigns to thousands, move
those to a sending service on a subdomain (`mail.thecastlefuncenter.com`),
which keeps the two reputations apart. That is a later job, not a launch
blocker.

---

## 6. Certificate, then require HTTPS

In this order, or you lock yourself out:

1. cPanel → **SSL/TLS Status** → tick the domain → **Run AutoSSL**.
2. Confirm `https://` loads with a padlock.
3. **Settings → System** → tick **Require HTTPS for every page**.

If your Cloudflare SSL mode is **Flexible**, change it to **Full (strict)**
once the certificate exists. Flexible plus a redirect-to-HTTPS rule is the
classic infinite redirect loop.

---

## 7. Bring the guests across

Safe Mode is still on. Nothing can reach a guest. Import now, while that is
true.

1. **Settings → Imports** → upload a CSV → map the columns.
2. **Dry run first.** It reports what it would do, per row, and refuses the
   file rather than half-importing it.
3. Read the refusals. An ambiguous date (`03/04/2024`) is refused rather than
   guessed at, because guessing wrong sends a birthday offer to the wrong
   week every year.
4. Commit.

**On consent, because this one is not negotiable and the import enforces it:**
imported contacts arrive consented to **nothing**, whatever the file says. A
consent column is honoured only if you tick the affirmation that you hold
real evidence for it — and that tick is audited with your name on it. An
import can never change a consent that already exists, in either direction. A
do-not-contact column always wins.

If you are unsure whether a list has provable consent, import it without
consent and earn it back: the Text Club double opt-in and the web forms exist
for exactly that, and a small list that can be mailed beats a big one that
cannot.

Visit history, if you have it, imports the same way and goes through the same
recorder the front desk uses — one guest on one day is one visit however many
transactions the file holds.

---

## 8. The POS feed: push from CEplay, do not pull from here

A pull will not work, and the reason is not cPanel. Two things block it:

1. PHP here needs an MSSQL driver (`pdo_sqlsrv`, `pdo_dblib` or ODBC), which
   is a root-level install. **Settings → Health** tells you whether this host
   has one. Probably not.
2. **Your POS server is on the venue LAN.** A shared host in a datacenter
   cannot reach a private address, and the only way to make it reachable is
   exposing SQL Server to the internet, which nobody should do.

**The shape that works:** CEplay already runs on-site, already reads the POS
read-only, already has a working `pdo_dblib` container and already has a
cron. A script there reads the POS and POSTs into Castle CRM's REST API —
which exists, with scoped bearer tokens, idempotent visit recording and
contact upsert. Outbound HTTPS from the venue needs no firewall change.

To prepare for it:

1. **Settings → API tokens** → create a token for a dedicated account, ticked
   for exactly `view_contacts`, `contacts_manage` (which also records visits)
   and nothing else. Copy it once; it is not shown again.
2. Keep the token on the CEplay box, not in a repository.

### Before building that, answer one question

**Does CenterEdge hold usable guest email and phone numbers at all?** Nobody
has ever looked, and the answer changes how much the bridge is worth. It
takes five minutes in a tool you already have:

1. Open CEplay → **Database Explorer** (`#/explorer`).
2. Browse to the **`Customers`** table (~131,000 rows) and read its column
   names off the table browser. CEplay's notes mark this table as a candidate
   whose columns have never been verified, so read them rather than assuming.
3. In the guarded SELECT box, count how many of those rows carry a real
   address — something along the lines of:

   ```sql
   SELECT COUNT(*) AS rows_total,
          SUM(CASE WHEN <email column> LIKE '%@%.%' THEN 1 ELSE 0 END) AS with_email,
          SUM(CASE WHEN LEN(<phone column>) >= 10 THEN 1 ELSE 0 END)   AS with_phone
     FROM Customers
   ```

   substituting the column names from step 2.

**What the answer means.** Near zero, and importing contacts is worth little:
the CSV lists you already have plus list-building through Text Club and the
web forms is the whole plan, and the bridge is only worth building for visit
history. A decent fill rate, and the bridge is worth building properly for
both.

**Also worth ten minutes while you are there:** `GroupBirthdays` (~24,000
rows). For a birthday-party venue, a child's name and birthday with the
booking parent attached may be the most valuable list in the building, and it
is not the one anybody thinks to look for. `CustVisits` (~173,000) and
`CustSales` (~545,000) tie visit and spend history to a *person* rather than
a card number, which is the thing the card ledger cannot do.

---

## 9. Turn Safe Mode off — last, and deliberately

Everything above works with Safe Mode on. Every message is redirected to the
owner with the intended recipient noted at the top, so you can exercise the
whole application without a guest ever receiving anything.

Before turning it off:

- send yourself a test of each template you plan to use
- check **Settings → Health** is clean
- confirm the quiet hours and the weekly per-guest cap suit the venue
- decide which playbooks to switch on, and switch them on **one at a time**

Leaving Safe Mode needs its own permission, asks you to type the venue's
name, and is audited as the decision it is. That friction is deliberate.

---

## 10. The first week

- **Back it up.** The nightly backup runs on the cron; download one by hand
  and keep it somewhere that is not the server.
- **Read the audit log** a few times. It records every change with before and
  after values, and it is how you answer "who moved that booking".
- **Watch one report you can verify by hand** — bookings this week, say —
  until you trust the numbers. Every figure in this application is something
  that actually happened; there are no projections or averaged baselines
  anywhere, so a number you can't reconcile is a bug worth reporting, not a
  modelling choice.
- **Add the real party prices** (`SPEC.md` §14 #10). The seeded ones came off
  the public website and nobody has confirmed them.
- **Start the 10DLC registration** if you want the Text Club. It is 2–4 weeks
  of carrier lead time and it gates nothing else, so the sooner it is in the
  queue the better.

---

## 11. Turning things on, one at a time

Each of these is independent. Nothing below requires anything above it, so
stop wherever you like and stay there as long as you like.

| Step | What it exposes | What it still cannot do |
|---|---|---|
| **Mail on `log`** (the default) | nothing | reach anybody |
| **SMTP configured, Safe Mode on** | your own inbox | reach a guest — every message is redirected to the owner |
| **Import your lists, Safe Mode on** | real guest data in the app | contact any of them |
| **Safe Mode off, all playbooks off** | transactional mail only — password resets, booking confirmations, run sheets | send a campaign or run a sequence; nothing is scheduled |
| **One playbook on** | that sequence, to the guests its segment matches | anything the other seven would do |
| **A campaign sent** | a one-off to a segment you chose and previewed | — |
| **An API token** | whatever you ticked, to whoever holds the token | more than you ticked; it is scoped and revocable |
| **The POS bridge** | visit history flowing in from CEplay | write to your POS — the feed is read-only by construction |

Two things worth knowing about the order:

- **Leaving Safe Mode is the only irreversible-feeling step**, and it has
  friction on purpose: its own permission, typing the venue's name, and an
  audit row with your name on it. Everything else is a switch you can flip
  back.
- **Turn playbooks on one at a time, with a week between them.** Not because
  the application struggles, but because if guest replies start arriving you
  want to know which sequence caused them. Eight at once is unreadable.

### The one thing to check before leaving Safe Mode

Open **Settings → Health** and confirm the cron heartbeat is current. With
Safe Mode on, a dead cron is invisible — nothing was going to send anyway.
With Safe Mode off and a dead cron, campaigns and sequences silently never
run, which looks exactly like "the automations do not work".
