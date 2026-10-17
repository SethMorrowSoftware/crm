// A picture of every section, for somebody who has not seen the application.
//
// Run it against a DEMO install and commit what it writes. Three properties
// make the output worth keeping in the repository:
//
//   1. **It refuses to photograph anything real.** `tools/screenshot-guard.php`
//      runs first and checks the DATA, not a flag: every contact must carry
//      positive evidence of being fictional, and no user account or mail
//      setting may be on a real domain. Screenshots get committed, and a
//      venue's guest list in git history is in every clone for ever.
//   2. **Every image says what it is a picture of.** The index it writes
//      carries the section's name, its path, and what to look at — a folder of
//      PNGs nobody can caption is decoration.
//   3. **It is honest about being a snapshot, not a baseline.** The data is
//      relative to the day it ran, so two runs differ. These document what the
//      application looks like; they are not golden-master tests and nothing
//      compares against them.
//
// Needs Playwright, which this repository deliberately does not vendor (there
// is no npm in the application). Install it once, anywhere on the path:
//   npm install --no-save playwright
//
// Usage, from the install root, with a dev server already running:
//   node tools/screenshots.js
//   node tools/screenshots.js --base http://127.0.0.1:8777 --out docs/screenshots
//
// Environment: CC_SHOT_EMAIL / CC_SHOT_PASSWORD for the sign-in, defaulting to
// the dev credentials.

let chromium;
try {
  ({ chromium } = require('playwright'));
} catch (e) {
  console.error('Playwright is not installed. Run `npm install --no-save playwright` first.');
  process.exit(1);
}
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const arg = (name, fallback) => {
  const i = process.argv.indexOf('--' + name);
  return i === -1 ? fallback : process.argv[i + 1];
};

const BASE = arg('base', 'http://127.0.0.1:8777').replace(/\/$/, '');
const OUT = path.resolve(arg('out', 'docs/screenshots'));
const EMAIL = process.env.CC_SHOT_EMAIL || 'demo@castle.test';
const PASSWORD = process.env.CC_SHOT_PASSWORD || 'demo-passphrase-99';
const CHROME = process.env.CC_SHOT_CHROME
  || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

// The sections, in the order the sidebar lists them, each with the thing a
// reader should actually look at. `wait` is a selector to settle on where a
// page draws itself after load.
const SECTIONS = [
  { file: 'dashboard', name: 'Command Center', path: '/',
    look: 'Today at a glance: the parties on, who is arriving, what needs doing. The year-over-year card sits here.' },
  { file: 'inbox', name: 'Unified inbox', path: '/inbox',
    look: 'Email and SMS in one thread per guest, assignable and closeable.' },

  { file: 'contacts', name: 'Contacts', path: '/contacts',
    look: 'Every guest, searchable by name, phone or email, with lifetime value and visit count derived rather than typed.' },
  { file: 'contact-detail', name: 'One guest', path: '/contacts?page=1', follow: '.table tbody tr th a, .table tbody tr td a',
    look: 'Visit history, consent with its evidence, bookings, notes and the full audit of what changed.' },
  { file: 'households', name: 'Households', path: '/households',
    look: 'Families grouped, so a parent and three children are one relationship rather than four strangers.' },
  { file: 'deals', name: 'Pipelines', path: '/deals',
    look: 'The sales board. A stage is a transition: entering one stamps its clock, sets won or lost, and fires the follow-up.' },
  { file: 'bookings', name: 'Bookings', path: '/bookings',
    look: 'Every party, with the money ladder — subtotal, discount, tax, deposit, balance — held as integer cents.' },
  { file: 'calendar', name: 'Calendar', path: '/calendar',
    look: 'Month, week and day, with resource lanes. Also published as an iCal feed.' },
  { file: 'checkin', name: 'Check-in', path: '/checkin',
    look: 'The desk view on the day: who is here, who has signed a waiver, who still owes a balance.' },
  { file: 'memberships', name: 'Memberships', path: '/memberships',
    look: 'Season passes as a lifecycle, not a flag — freezing gives the time back, renewing starts from the current end date.' },

  { file: 'campaigns', name: 'Campaigns', path: '/campaigns',
    look: 'Compose, estimate the audience, send in chunks on the cron, then read what it did.' },
  { file: 'segments', name: 'Segments', path: '/segments',
    look: 'Rules compiled to SQL, with a live count before anything is sent.' },
  { file: 'text-club', name: 'Text Club', path: '/text-club',
    look: 'Double opt-in by law and by design, with the offer calendar and per-guest caps.' },
  { file: 'playbooks', name: 'Playbooks', path: '/playbooks',
    look: 'Eight revenue playbooks. Each ships switched OFF; turning one on is a deliberate act.' },
  { file: 'automations', name: 'Automations', path: '/automations',
    look: '112 seeded sequences, every one inactive until somebody activates it. A trigger that can never fire is a build failure.' },
  { file: 'reputation', name: 'Reputation', path: '/reputation',
    look: 'Surveys, the feedback gate that routes an unhappy guest to a person instead of a review site, and the review inbox.' },

  { file: 'tasks', name: 'Tasks', path: '/tasks',
    look: 'What the team owes, assignable, with due dates automations can set.' },
  { file: 'reports', name: 'Reports', path: '/reports',
    look: '23 reports in six families. Actuals only — no projections, no averaged baselines, and null is never shown as zero.' },
  { file: 'report-revenue', name: 'A report', path: '/reports/revenue_by_day?period=last_90_days',
    look: 'Revenue by day over ninety days, with CSV export and the prior period compared honestly.' },

  { file: 'waivers', name: 'Waivers', path: '/waivers',
    look: 'Versioned: a signature records WHICH wording the guest agreed to, because that is the point of a waiver.' },
  { file: 'forms', name: 'Forms', path: '/forms',
    look: 'Embeddable web forms that create a guest and a lead, with consent captured as evidence.' },
  { file: 'imports', name: 'Imports', path: '/imports',
    look: 'CSV import for guests and visit history. Dry run first; it refuses a file rather than half-importing it.' },

  { file: 'settings', name: 'Settings', path: '/settings',
    look: 'The whole configuration surface, each tile its own permission.' },
  { file: 'settings-mail', name: 'Email settings', path: '/settings/mail',
    look: 'The driver, Safe Mode, and the DNS records — which name whoever actually sends your mail, rather than assuming it is this server.' },
  { file: 'settings-users', name: 'Users and roles', path: '/settings/users',
    look: 'Roles are data, permissions are code. Each permission carries a scope: all, team, or own.' },
  { file: 'settings-health', name: 'Health', path: '/settings/health',
    look: 'The cron heartbeat, the queue, the mail driver, the POS driver. The first page to open when something feels wrong.' },
  { file: 'settings-audit', name: 'Audit log', path: '/settings/audit',
    look: 'Every change with before and after values. How you answer "who moved that booking".' },
  { file: 'settings-api', name: 'API tokens', path: '/settings/api-tokens',
    look: 'Scoped bearer tokens. Read, write and delete are separate ticks, because they are separate permissions everywhere else.' },
  { file: 'settings-backups', name: 'Backups', path: '/settings/backups',
    look: 'Nightly backups, CSV export, and removing the installer\'s example guests once you have finished looking at them.' },
];

// The pages worth seeing at phone width. Check-in is the one floor staff
// actually hold, so it leads.
const PHONE = [
  { file: 'phone-checkin', name: 'Check-in (phone)', path: '/checkin',
    look: 'The page the desk holds on the day of a party: who is here, who has signed, who still owes a balance.' },
  { file: 'phone-dashboard', name: 'Command Center (phone)', path: '/',
    look: 'The dashboard at 390px. No page in this application scrolls sideways on a phone.' },
  { file: 'phone-bookings', name: 'Bookings (phone)', path: '/bookings',
    look: 'A wide table on a narrow screen: it scrolls inside its own container rather than dragging the layout.' },
];

// Dark mode, for a representative few rather than all of them — the theme is
// one stylesheet, and six pages establish it without doubling the folder.
const DARK = ['dashboard', 'contacts', 'deals', 'calendar', 'reports', 'settings-health'];

/*
 * 1280 is a real desktop and the layout is responsive, so this costs no
 * information and a third of the bytes against 1440. The height cap is the
 * other half of keeping the folder carryable.
 */
const WIDTH = 1280;
const MAX_HEIGHT = 3000;

const run = async () => {
  // ---------------------------------------------------------------- the guard
  console.log('Checking the database holds no real guest data…');

  try {
    const verdict = execFileSync('php', ['tools/screenshot-guard.php'], { encoding: 'utf8' });
    console.log('  ' + verdict.trim().split('\n')[0]);
  } catch (e) {
    console.error('\nREFUSED — not taking screenshots of this database.\n');
    console.error((e.stdout || '') + (e.stderr || ''));
    process.exit(1);
  }

  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });
  const captured = [];
  const problems = [];

  const context = await browser.newContext({
    viewport: { width: WIDTH, height: 900 },
    deviceScaleFactor: 1,
  });
  const page = await context.newPage();

  // Every page the capture opens carries the same three checks. `pageerror`
  // only sees uncaught exceptions, so a `console.error` needs its own listener.
  const watch = (p, label) => {
    const where = label ? ` (${label})` : '';
    p.on('pageerror', e => problems.push(`pageerror${where}: ${String(e).slice(0, 120)}`));
    p.on('console', m => {
      if (m.type() === 'error') problems.push(`console error${where}: ${m.text().slice(0, 120)}`);
    });
    p.on('response', r => {
      if (r.status() >= 500) problems.push(`${r.status()} ${r.url().replace(BASE, '')}${where}`);
    });
  };

  watch(page, '');

  // ---------------------------------------------------------------- sign in
  await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.click('form[action$="/login"] button[type="submit"]'),
  ]);

  if (/\/login/.test(page.url())) {
    throw new Error('sign-in failed — check CC_SHOT_EMAIL / CC_SHOT_PASSWORD');
  }

  // The login page itself, signed out, is worth one too — but it has to be
  // taken from a context with no session.
  const anon = await browser.newContext({ viewport: { width: WIDTH, height: 900 } });
  const anonPage = await anon.newPage();
  watch(anonPage, 'signed out');
  await anonPage.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await anonPage.screenshot({ path: path.join(OUT, 'login.png'), fullPage: true });
  await anon.close();
  captured.push({ file: 'login', name: 'Sign in', path: '/login',
    look: 'Rate limited, with a reset flow that works even when no mail provider is configured — the link goes to the log.' });
  console.log('  login.png');

  const shoot = async (target, p, suffix = '') => {
    const file = target.file + suffix + '.png';

    try {
      const landed = await p.goto(BASE + target.path, { waitUntil: 'domcontentloaded', timeout: 30000 });

      /*
       * The STATUS, not just the body text. A page that answers 403, 405 or
       * 429 is a picture of a refusal, and the three of them are the easiest
       * way to end up with a folder of them: a role without a permission, a
       * route that moved, or the login throttle (12 per 5 minutes per IP,
       * and each run of this script spends two of them).
       */
      if (landed !== null && landed.status() !== 200) {
        problems.push(`${target.path} answered ${landed.status()}`);
      }

      // Follow into a detail page where the section asks for it, so the index
      // shows a record rather than only a list.
      if (target.follow) {
        const link = await p.$(target.follow);
        if (link !== null) {
          await Promise.all([
            p.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
            link.click(),
          ]);
        }
      }

      // Let the stylesheet's fonts and any chart settle; the app has no
      // client-side router, so there is nothing else to wait for.
      await p.waitForTimeout(350);

      /*
       * And the body text, as a second witness — a `follow` click navigates
       * after the response above was read, so a detail page that refuses is
       * only visible here.
       *
       * These are THIS application's own error titles, read out of
       * app/Core/Middleware.php and app/Core/Router.php rather than guessed.
       * The first version of this list guessed — "Not found|Forbidden|
       * Something went wrong" — and the app renders none of those three: it
       * says "Not permitted" (403), "Too many requests" (429) and "Method
       * not allowed" (405). Every one of those would have been photographed
       * and committed with the run still exiting 0.
       */
      const text = await p.evaluate(() => document.body.innerText.slice(0, 200));

      if (/Not permitted|Too many requests|Method not allowed|Page not found|Something went wrong/i.test(text)) {
        problems.push(`${target.path} rendered an error page: ${text.split('\n').filter(Boolean)[0] || ''}`);
      }

      /*
       * BOUNDED, because a full page is not always a sensible picture. The
       * automations list is 112 rows and came to 1.9MB on its own — ten times
       * any other page, for a picture nobody reads past the first screen of.
       * A tall page is captured down to MAX_HEIGHT and the index says so, so
       * the folder stays a reasonable weight to carry in a repository for ever.
       */
      const height = await p.evaluate(() => document.documentElement.scrollHeight);
      const width = p.viewportSize().width;
      const clipped = height > MAX_HEIGHT;

      await p.screenshot(clipped
        ? { path: path.join(OUT, file), clip: { x: 0, y: 0, width, height: MAX_HEIGHT } }
        : { path: path.join(OUT, file), fullPage: true });

      if (clipped) target.clipped = height;

      console.log('  ' + file + (clipped ? `  (top ${MAX_HEIGHT}px of ${height}px)` : ''));

      return true;
    } catch (e) {
      problems.push(`${target.file}: ${String(e.message).slice(0, 100)}`);
      return false;
    }
  };

  // ------------------------------------------------------------ the sections
  console.log(`\nSections, ${WIDTH}px, light:`);

  for (const section of SECTIONS) {
    if (await shoot(section, page)) captured.push(section);
  }

  // ------------------------------------------------------------- dark mode
  console.log('\nThe same pages in dark mode:');

  await page.addInitScript(() => {
    document.addEventListener('DOMContentLoaded', () =>
      document.documentElement.setAttribute('data-theme', 'dark'));
  });

  const darkCaptured = [];

  for (const section of SECTIONS.filter(s => DARK.includes(s.file))) {
    if (await shoot(section, page, '-dark')) darkCaptured.push(section);
  }

  // ----------------------------------------------------------------- phone
  console.log('\nPhone width, 390px:');

  const phoneContext = await browser.newContext({
    viewport: { width: 390, height: 844 },
    // 1x, not 2x: a retina capture of a phone layout doubled the file size
    // for a picture nobody zooms into.
    deviceScaleFactor: 1,
    isMobile: true,
    hasTouch: true,
  });
  const phonePage = await phoneContext.newPage();

  watch(phonePage, 'phone');

  await phonePage.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await phonePage.fill('input[name="email"]', EMAIL);
  await phonePage.fill('input[name="password"]', PASSWORD);
  await Promise.all([
    phonePage.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    phonePage.click('form[action$="/login"] button[type="submit"]'),
  ]);

  /*
   * And the same assertion. This is the SECOND sign-in of the run, and
   * POST /login is throttled 12 per 5 minutes per IP — so on a sixth or
   * seventh run while iterating, the desktop login is allowed and this one
   * is answered with the 429 page. Unchecked, the three phone captures
   * become three pictures of a login form and the run still exits 0.
   */
  if (/\/login/.test(phonePage.url())) {
    throw new Error(
      'the phone sign-in failed — if the desktop one worked, this is the login'
      + ' throttle (12 per 5 minutes per IP); wait five minutes and run it again'
    );
  }

  const phoneCaptured = [];

  for (const section of PHONE) {
    if (await shoot(section, phonePage)) phoneCaptured.push(section);

    // The thing worth proving on a phone: no horizontal scroll anywhere.
    const overflow = await phonePage.evaluate(() =>
      document.documentElement.scrollWidth - document.documentElement.clientWidth);

    if (overflow > 1) problems.push(`${section.path} scrolls sideways by ${overflow}px at 390px`);
  }

  /*
   * EVERY section measured at phone width, not only the three photographed
   * there. The index claims "no page in this application scrolls sideways at
   * 390px" and attributes it to the capture — which was true of three pages
   * out of twenty-nine, so the sentence was doing work the tool had not done.
   * Measuring is cheap (a navigation, no screenshot) and makes the claim the
   * capture's own rather than a hope.
   */
  const phoneOnly = new Set(PHONE.map(s => s.path));
  let measured = PHONE.length;

  for (const section of SECTIONS) {
    if (phoneOnly.has(section.path)) continue;

    try {
      await phonePage.goto(BASE + section.path, { waitUntil: 'domcontentloaded', timeout: 30000 });
      await phonePage.waitForTimeout(120);

      const overflow = await phonePage.evaluate(() =>
        document.documentElement.scrollWidth - document.documentElement.clientWidth);

      measured++;

      if (overflow > 1) problems.push(`${section.path} scrolls sideways by ${overflow}px at 390px`);
    } catch (e) {
      problems.push(`${section.path} could not be measured at 390px: ${String(e.message).slice(0, 80)}`);
    }
  }

  console.log(`\nMeasured ${measured} sections at 390px for horizontal scroll.`);

  await phoneContext.close();
  await browser.close();

  // ----------------------------------------------------------------- index
  const sizes = {};
  let totalBytes = 0;

  for (const f of fs.readdirSync(OUT).filter(f => f.endsWith('.png'))) {
    sizes[f] = fs.statSync(path.join(OUT, f)).size;
    totalBytes += sizes[f];
  }

  const kb = n => Math.round(n / 1024) + ' KB';
  const row = s => `| [${s.name}](${s.file}.png) | \`${s.path}\` | ${s.look}`
    + (s.clipped ? ` *(the top ${MAX_HEIGHT}px of a ${s.clipped}px page)*` : '')
    + ' |';

  const readme = `# What Castle CRM looks like

One picture per section, so somebody can see the application without
installing it. **${Object.keys(sizes).length} images, ${kb(totalBytes)} in all.**

Taken by \`tools/screenshots.js\` against a demo install. Three things to know
before reading them:

- **These are a snapshot, not a baseline.** The data is relative to the day the
  capture ran, so two runs differ in every date and figure. Nothing compares
  against these images and no test will fail if they change — they document
  what the application looks like, and that is all.
- **Nothing real appears in any of them.** \`tools/screenshot-guard.php\` runs
  first and refuses the capture unless every contact carries a mark a real
  person's record could not: an email on a domain the standards reserve for
  fiction, a phone in the reserved 555-01xx block, or the installer seed's own
  marker. A missing email is not such a mark — that is the ordinary shape of a
  guest who arrived by text. It also refuses a user account on a real email
  domain (the user list is one of these pictures, and the signed-in name is in
  the sidebar of all of them) and a real SMTP host or from-address (the email
  settings page shows them in full). The check is on the data, not on a flag
  somebody set.
- **The figures are real arithmetic over fake data.** Nothing here is a mockup:
  every total was computed by the application from the rows behind it.

To retake them: start a dev server, then \`node tools/screenshots.js\`.

## Sections

| Section | Path | What to look at |
|---|---|---|
${captured.map(row).join('\n')}

## Dark mode

The theme is one stylesheet, so a representative few rather than all of them.

| Section | Path |
|---|---|
${darkCaptured.map(s => `| [${s.name}](${s.file}-dark.png) | \`${s.path}\` |`).join('\n')}

## On a phone

Check-in is the page floor staff actually hold, so it leads. Three are
photographed here; **all ${measured} sections were measured at 390px and none
of them scrolls sideways** — the capture asserts that rather than leaving it
to be looked at.

| Section | Path | What to look at |
|---|---|---|
${phoneCaptured.map(row).join('\n')}
`;

  fs.writeFileSync(path.join(OUT, 'README.md'), readme);

  console.log(`\n${Object.keys(sizes).length} images, ${kb(totalBytes)} total`);
  console.log('index: ' + path.join(OUT, 'README.md'));

  const biggest = Object.entries(sizes).sort((a, b) => b[1] - a[1]).slice(0, 3);
  console.log('largest: ' + biggest.map(([f, n]) => `${f} ${kb(n)}`).join(', '));

  if (problems.length) {
    console.log('\nPROBLEMS:');
    for (const p of [...new Set(problems)]) console.log('  - ' + p);
    process.exitCode = 1;
  } else {
    console.log('\nno errors, no 500s, nothing scrolls sideways');
  }
};

run().catch(e => {
  console.error('SCREENSHOTS FAILED: ' + e.message);
  process.exit(1);
});
