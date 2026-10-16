<?php

declare(strict_types=1);

/**
 * The route table.
 *
 * Every URL the application answers is listed here with the permission that
 * guards it. Reading this file top to bottom should tell you what Castle CRM
 * can do and who is allowed to do it.
 *
 * Middleware shorthand:
 *   auth            — a signed-in user is required
 *   guest           — only when signed out
 *   csrf            — mutating verbs must carry a valid token
 *   can:<perm>      — the user must hold this permission (checked against the
 *                     Acl catalogue, so a typo throws rather than passing)
 *   throttle:n,m    — at most n requests per m minutes per IP, per method and
 *                     path. The method matters: reading a public page and
 *                     submitting it carry different limits, and a shared
 *                     counter would let reads spend the writes' allowance.
 *
 * @var \App\Core\Router $router
 */

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

$router->get('/login', ['AuthController', 'showLogin'], ['guest'], 'login');
$router->post('/login', ['AuthController', 'login'], ['guest', 'csrf', 'throttle:12,5']);
$router->post('/logout', ['AuthController', 'logout'], ['auth', 'csrf'], 'logout');

$router->get('/forgot-password', ['AuthController', 'showForgot'], ['guest'], 'password.forgot');
$router->post('/forgot-password', ['AuthController', 'sendReset'], ['guest', 'csrf', 'throttle:5,15']);
$router->get('/reset-password/{token:[a-f0-9]{64}}', ['AuthController', 'showReset'], ['guest'], 'password.reset');
$router->post('/reset-password', ['AuthController', 'resetPassword'], ['guest', 'csrf', 'throttle:10,15']);

// ---------------------------------------------------------------------------
// Public, unauthenticated guest endpoints
//
// One-click unsubscribe must work from an email client with no login. The
// signed token IS the authorisation, which is why the POST form is exempt
// from CSRF: RFC 8058 has the recipient's mail provider submit it, so a
// session token could never be present.
// ---------------------------------------------------------------------------

$router->get('/u/{token:[A-Za-z0-9_-]+}', ['PublicController', 'unsubscribe'], ['throttle:60,5'], 'unsubscribe');
$router->post('/u/{token:[A-Za-z0-9_-]+}', ['PublicController', 'unsubscribe'], ['throttle:60,5']);

// A hosted web form. No login: this is the party enquiry a parent fills in
// from the venue's website.
//
// Unlike one-click unsubscribe above, the POST is NOT CSRF-exempt. Rendering
// the page mints a token into the visitor's own session, so the submission can
// carry one — and without it every form on the venue's site would be a write
// endpoint any other site could aim at a visitor's browser. The throttle is
// the second spam check after the per-form honeypot; the GET is limited more
// loosely because a page view is cheap and a real guest may reload.
$router->get('/f/{slug:[a-z0-9-]+}', ['FormController', 'show'], ['throttle:120,5'], 'forms.public');
$router->post('/f/{slug:[a-z0-9-]+}', ['FormController', 'submit'], ['csrf', 'throttle:15,10']);

// ---------------------------------------------------------------------------
// Dashboard
// ---------------------------------------------------------------------------

$router->get('/', ['DashboardController', 'index'], ['auth', 'can:view_dashboard'], 'dashboard');

// ---------------------------------------------------------------------------
// Account — anything a signed-in user may do to their own record
// ---------------------------------------------------------------------------

$router->group('/account', ['auth'], function ($router): void {
    $router->get('', ['AccountController', 'edit'], [], 'account');
    $router->post('/profile', ['AccountController', 'updateProfile'], ['csrf']);
    $router->post('/password', ['AccountController', 'updatePassword'], ['csrf']);
    $router->post('/preferences', ['AccountController', 'updatePreferences'], ['csrf']);
    $router->post('/sessions/revoke', ['AccountController', 'revokeSessions'], ['csrf']);
});

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

$router->group('/settings', ['auth', 'can:settings'], function ($router): void {
    $router->get('', ['SettingsController', 'index'], [], 'settings');

    $router->get('/venue', ['SettingsController', 'venue'], [], 'settings.venue');
    $router->post('/venue', ['SettingsController', 'saveVenue'], ['csrf']);

    $router->get('/system', ['SettingsController', 'system'], [], 'settings.system');
    $router->post('/system', ['SettingsController', 'saveSystem'], ['csrf']);

    // Email provider configuration holds a credential, so it carries its own
    // permission rather than riding along with general settings.
    $router->get('/mail', ['MailSettingsController', 'edit'], ['can:integrations_manage'], 'settings.mail');
    $router->post('/mail', ['MailSettingsController', 'save'], ['csrf', 'can:integrations_manage']);
    $router->post('/mail/test', ['MailSettingsController', 'test'], ['csrf', 'can:integrations_manage']);

    // Leaving Safe Mode is its own permission: it is the moment the app starts
    // messaging real guests, so it should not ride along with general settings.
    $router->post('/safe-mode', ['SettingsController', 'toggleSafeMode'], ['csrf', 'can:sending_override']);
    $router->post('/pause-sending', ['SettingsController', 'togglePauseSending'], ['csrf', 'can:sending_override']);
});

// ---------------------------------------------------------------------------
// Users, roles & teams
// ---------------------------------------------------------------------------

$router->group('/settings/users', ['auth', 'can:users_manage'], function ($router): void {
    $router->get('', ['UserController', 'index'], [], 'users');
    $router->get('/new', ['UserController', 'create'], [], 'users.create');
    $router->post('', ['UserController', 'store'], ['csrf']);
    $router->get('/{id:\d+}', ['UserController', 'edit'], [], 'users.edit');
    $router->post('/{id:\d+}', ['UserController', 'update'], ['csrf']);
    $router->post('/{id:\d+}/delete', ['UserController', 'destroy'], ['csrf']);
    $router->post('/{id:\d+}/reactivate', ['UserController', 'reactivate'], ['csrf']);
});

$router->group('/settings/roles', ['auth', 'can:roles_manage'], function ($router): void {
    $router->get('', ['RoleController', 'index'], [], 'roles');
    $router->get('/new', ['RoleController', 'create'], [], 'roles.create');
    $router->post('', ['RoleController', 'store'], ['csrf']);
    $router->get('/{id:\d+}', ['RoleController', 'edit'], [], 'roles.edit');
    $router->post('/{id:\d+}', ['RoleController', 'update'], ['csrf']);
    $router->post('/{id:\d+}/delete', ['RoleController', 'destroy'], ['csrf']);
});

// ---------------------------------------------------------------------------
// Backups & data export
//
// `backup_manage` rather than `settings`: a downloaded backup is the whole
// guest database in one file, so the permission to take one is deliberately
// narrower than the permission to change a setting.
// ---------------------------------------------------------------------------

$router->group('/settings/backups', ['auth', 'can:backup_manage'], function ($router): void {
    $router->get('', ['BackupController', 'index'], [], 'backups');
    $router->post('', ['BackupController', 'create'], ['csrf', 'throttle:6,10']);
    $router->post('/settings', ['BackupController', 'saveSettings'], ['csrf']);
    $router->get('/export', ['BackupController', 'export'], ['throttle:20,10'], 'backups.export');

    // The filename pattern matches what Backup::create() writes, and
    // Backup::pathFor() re-validates it against the real directory before
    // anything is opened. A route constraint alone is never the only guard on
    // a path that reaches the filesystem.
    $router->get(
        '/{filename:castle-crm-[0-9]{8}-[0-9]{6}\.sql(?:\.gz)?}/download',
        ['BackupController', 'download'],
        ['throttle:20,10'],
        'backups.download'
    );
    $router->post(
        '/{filename:castle-crm-[0-9]{8}-[0-9]{6}\.sql(?:\.gz)?}/delete',
        ['BackupController', 'destroy'],
        ['csrf']
    );
});

// ---------------------------------------------------------------------------
// Audit log
// ---------------------------------------------------------------------------

$router->get('/settings/audit', ['AuditController', 'index'], ['auth', 'can:view_logs'], 'audit');
$router->get('/settings/audit/export', ['AuditController', 'export'], ['auth', 'can:view_logs', 'can:reports_export']);

// ---------------------------------------------------------------------------
// Health & diagnostics
// ---------------------------------------------------------------------------

$router->get('/settings/health', ['HealthController', 'index'], ['auth', 'can:settings'], 'health');
$router->post('/settings/health/test-cron', ['HealthController', 'testCron'], ['auth', 'can:settings', 'csrf']);

// A machine-readable probe for uptime monitoring. No auth — it reveals only
// liveness, never configuration — but rate limited so it cannot be used to
// hammer the database.
$router->get('/health.json', ['HealthController', 'probe'], ['throttle:30,1'], 'health.json');

// ---------------------------------------------------------------------------
// Playbooks — the 8 revenue playbooks and their activation state
// ---------------------------------------------------------------------------

$router->group('/playbooks', ['auth', 'can:view_automations'], function ($router): void {
    $router->get('', ['PlaybookController', 'index'], [], 'playbooks');
    $router->get('/{slug:[a-z0-9-]+}', ['PlaybookController', 'show'], [], 'playbooks.show');
    $router->post('/{id:\d+}/activate', ['PlaybookController', 'activate'], ['csrf', 'can:playbooks_activate']);
    $router->post('/{id:\d+}/deactivate', ['PlaybookController', 'deactivate'], ['csrf', 'can:playbooks_activate']);
});

// ---------------------------------------------------------------------------
// Contacts & households
//
// Reading gates on view_contacts and is scoped per role by
// Acl::scopeCondition(); writing gates on contacts_manage; deleting has its
// own narrower permission.
//
// Consent is NOT part of the contact form. It has its own two endpoints
// because recording it means stamping a time, naming a source and appending a
// consent_log row — docs/PRIVACY.md §3. A guest's permission to be marketed
// to is evidence of something they did, so it cannot be a column somebody
// edits in passing.
// ---------------------------------------------------------------------------

$router->group('/contacts', ['auth', 'can:view_contacts'], function ($router): void {
    $router->get('', ['ContactController', 'index'], [], 'contacts');
    $router->get('/new', ['ContactController', 'create'], ['can:contacts_manage'], 'contacts.create');
    $router->post('', ['ContactController', 'store'], ['csrf', 'can:contacts_manage']);
    $router->get('/{id:\d+}', ['ContactController', 'show'], [], 'contacts.show');
    $router->get('/{id:\d+}/edit', ['ContactController', 'edit'], ['can:contacts_manage'], 'contacts.edit');
    $router->post('/{id:\d+}', ['ContactController', 'update'], ['csrf', 'can:contacts_manage']);
    $router->post('/{id:\d+}/notes', ['ContactController', 'storeNote'], ['csrf', 'can:contacts_manage']);
    $router->post('/{id:\d+}/consent', ['ContactController', 'updateConsent'], ['csrf', 'can:contacts_manage']);
    $router->post('/{id:\d+}/do-not-contact', ['ContactController', 'setDoNotContact'], ['csrf', 'can:contacts_manage']);
    $router->post('/{id:\d+}/delete', ['ContactController', 'destroy'], ['csrf', 'can:contacts_delete']);
});

$router->group('/households', ['auth', 'can:view_contacts'], function ($router): void {
    $router->get('', ['HouseholdController', 'index'], [], 'households');
    $router->get('/new', ['HouseholdController', 'create'], ['can:contacts_manage'], 'households.create');
    $router->post('', ['HouseholdController', 'store'], ['csrf', 'can:contacts_manage']);
    $router->get('/{id:\d+}', ['HouseholdController', 'show'], [], 'households.show');
    $router->get('/{id:\d+}/edit', ['HouseholdController', 'edit'], ['can:contacts_manage'], 'households.edit');
    $router->post('/{id:\d+}', ['HouseholdController', 'update'], ['csrf', 'can:contacts_manage']);
    $router->post('/{id:\d+}/members', ['HouseholdController', 'addMember'], ['csrf', 'can:contacts_manage']);
    $router->post('/{id:\d+}/members/remove', ['HouseholdController', 'removeMember'], ['csrf', 'can:contacts_manage']);
});

// ---------------------------------------------------------------------------
// Sales pipelines and deals (replaces the /deals placeholder)
//
// Reading is view_deals, and it is SCOPED: the controller runs every read
// through Acl::scopeCondition(), so a Party Host holding view_deals at 'own'
// sees their own leads and a manager holding it at 'all' sees the venue's.
// Editing is deals_manage, deleting is deals_delete, and taking over somebody
// else's deal needs deals_reassign — checked in the controller, which is where
// it can compare the submitted owner against the deal's current one.
// ---------------------------------------------------------------------------

$router->group('/deals', ['auth', 'can:view_deals'], function ($router): void {
    $router->get('', ['DealController', 'index'], [], 'deals');
    // The same deals without the grid: a phone, a screen reader, or sorting by
    // value down a column.
    $router->get('/table', ['DealController', 'table'], [], 'deals.table');

    $router->get('/new', ['DealController', 'create'], ['can:deals_manage'], 'deals.create');
    $router->post('', ['DealController', 'store'], ['csrf', 'can:deals_manage']);

    $router->get('/{id:\d+}', ['DealController', 'show'], [], 'deals.show');
    $router->get('/{id:\d+}/edit', ['DealController', 'edit'], ['can:deals_manage'], 'deals.edit');
    $router->post('/{id:\d+}', ['DealController', 'update'], ['csrf', 'can:deals_manage']);

    // Moving a deal is its own route because it writes a stage-history row and
    // may close the deal, and because the board and the table both post to it.
    $router->post('/{id:\d+}/stage', ['DealController', 'move'], ['csrf', 'can:deals_manage']);

    $router->post('/{id:\d+}/delete', ['DealController', 'destroy'], ['csrf', 'can:deals_delete']);
});

$router->group('/pipelines', ['auth', 'can:view_deals'], function ($router): void {
    $router->get('', ['PipelineController', 'index'], [], 'pipelines');
    $router->get('/new', ['PipelineController', 'create'], ['can:deals_manage'], 'pipelines.create');
    $router->post('', ['PipelineController', 'store'], ['csrf', 'can:deals_manage']);
    $router->get('/{id:\d+}', ['PipelineController', 'edit'], ['can:deals_manage'], 'pipelines.edit');
    $router->post('/{id:\d+}', ['PipelineController', 'update'], ['csrf', 'can:deals_manage']);
    $router->post('/{id:\d+}/stages', ['PipelineController', 'saveStages'], ['csrf', 'can:deals_manage']);
});


// ---------------------------------------------------------------------------
// Quotes
//
// `view_deals` opens the group, because a quote belongs to a deal and the two
// are the same thing to look at. Editing needs `deals_manage`. SENDING has its
// own permission, `quotes_send`, and only the send route carries it: that is
// the moment a figure leaves the building with the venue's name on it, and a
// Party Host who may price a party is not automatically the person who commits
// it to a guest in writing.
//
// There is no status route that can reach `sent` — see QuoteController's
// TRANSITIONS — so the send gate cannot be stepped around through the
// cheaper-gated status form.
// ---------------------------------------------------------------------------

$router->group('/quotes', ['auth', 'can:view_deals'], function ($router): void {
    $router->get('', ['QuoteController', 'index'], [], 'quotes');
    $router->get('/new', ['QuoteController', 'create'], ['can:deals_manage'], 'quotes.create');
    $router->post('', ['QuoteController', 'store'], ['csrf', 'can:deals_manage']);
    $router->get('/{id:\d+}', ['QuoteController', 'show'], [], 'quotes.show');
    $router->get('/{id:\d+}/print', ['QuoteController', 'printable'], [], 'quotes.print');
    $router->get('/{id:\d+}/edit', ['QuoteController', 'edit'], ['can:deals_manage'], 'quotes.edit');
    $router->post('/{id:\d+}', ['QuoteController', 'update'], ['csrf', 'can:deals_manage']);
    $router->post('/{id:\d+}/status', ['QuoteController', 'status'], ['csrf', 'can:deals_manage']);
    $router->post('/{id:\d+}/send', ['QuoteController', 'send'], ['csrf', 'can:quotes_send']);
});

// ---------------------------------------------------------------------------
// Web forms
//
// `view_marketing` to read a form or its submissions, `forms_manage` to build
// one: a form carries guest enquiries, so looking and editing are separate.
// The public pages this module serves are registered with the other
// unauthenticated endpoints above, not here.
// ---------------------------------------------------------------------------

$router->group('/forms', ['auth', 'can:view_marketing'], function ($router): void {
    $router->get('', ['FormController', 'index'], [], 'forms');
    $router->get('/new', ['FormController', 'create'], ['can:forms_manage'], 'forms.create');
    $router->post('', ['FormController', 'store'], ['csrf', 'can:forms_manage']);

    $router->get('/{id:\d+}/submissions', ['FormController', 'submissions'], [], 'forms.submissions');
    $router->get(
        '/{id:\d+}/submissions/{submission:\d+}',
        ['FormController', 'submission'],
        [],
        'forms.submission'
    );

    $router->get('/{id:\d+}', ['FormController', 'edit'], ['can:forms_manage'], 'forms.edit');
    $router->post('/{id:\d+}', ['FormController', 'update'], ['csrf', 'can:forms_manage']);
    $router->post('/{id:\d+}/delete', ['FormController', 'destroy'], ['csrf', 'can:forms_manage']);
});

// ---------------------------------------------------------------------------
// Tasks (replaces the /tasks placeholder)
//
// `view_tasks` to read, and it is SCOPED: the controller runs every read
// through Acl::scopeCondition('view_tasks', 't.assigned_to'), so a Front Desk
// account holding it at 'own' sees the tasks assigned to them and a manager
// holding it at 'all' sees the venue's.
//
// Writing gates on `inbox_assign`. Acl::CATALOG has no `tasks_manage` key and
// this module does not add one, so it uses the closest existing fit — "Assign
// and close conversations" is the same capability these buttons exercise, and
// in the seeded roles it lands on exactly the people who hand work out. See
// TaskController::WRITE_PERMISSION, which states the trade-off.
//
// Completing and reopening are POSTs with their own routes rather than a
// status field on the edit form alone, because each one stamps who finished the
// task and when — the record the weekly scorecard is built from.
// ---------------------------------------------------------------------------

$router->group('/tasks', ['auth', 'can:view_tasks'], function ($router): void {
    $router->get('', ['TaskController', 'index'], [], 'tasks');
    $router->get('/new', ['TaskController', 'create'], ['can:inbox_assign'], 'tasks.create');
    $router->post('', ['TaskController', 'store'], ['csrf', 'can:inbox_assign']);
    $router->get('/{id:\d+}', ['TaskController', 'show'], [], 'tasks.show');
    $router->get('/{id:\d+}/edit', ['TaskController', 'edit'], ['can:inbox_assign'], 'tasks.edit');
    $router->post('/{id:\d+}', ['TaskController', 'update'], ['csrf', 'can:inbox_assign']);
    $router->post('/{id:\d+}/complete', ['TaskController', 'complete'], ['csrf']);
    $router->post('/{id:\d+}/reopen', ['TaskController', 'reopen'], ['csrf']);
    $router->post('/{id:\d+}/comments', ['TaskController', 'storeComment'], ['csrf']);
});

// ---------------------------------------------------------------------------
// Bookings (replaces the /bookings placeholder)
//
// Reading is `view_bookings`. Writing is `bookings_manage`, with two actions
// split out of it:
//
//   * cancelling has its own permission (`bookings_cancel`), because it is the
//     one action that gives a room back and may owe a family a refund. Front
//     Desk deliberately does not hold it;
//   * recording money has its own (`payments_record`), so whoever answers the
//     phone is not automatically whoever settles the books.
//
// Nothing here uses a gate LOWER than `bookings_manage`. Ticking a checklist
// item was written that way at first, copying the task module's rule that an
// assignee may close their own work, and it does not transfer: Front Desk and
// Party Host both already hold `bookings_manage`, so the looser gate bought
// nobody anything while letting the seeded Read Only role write to a booking.
//
// `/bookings/availability` is a JSON read behind `view_bookings`: it reports
// where the venue has gaps, which is commercial information.
// ---------------------------------------------------------------------------

$router->group('/bookings', ['auth', 'can:view_bookings'], function ($router): void {
    $router->get('', ['BookingController', 'index'], [], 'bookings');
    $router->get('/new', ['BookingController', 'create'], ['can:bookings_manage'], 'bookings.create');
    $router->post('', ['BookingController', 'store'], ['csrf', 'can:bookings_manage']);
    $router->get('/availability', ['BookingController', 'availability'], [], 'bookings.availability');
    $router->get('/{id:\d+}', ['BookingController', 'show'], [], 'bookings.show');
    $router->get('/{id:\d+}/run-sheet', ['BookingController', 'runSheet'], [], 'bookings.runsheet');
    $router->get('/{id:\d+}/edit', ['BookingController', 'edit'], ['can:bookings_manage'], 'bookings.edit');
    $router->post('/{id:\d+}', ['BookingController', 'update'], ['csrf', 'can:bookings_manage']);
    $router->post('/{id:\d+}/status', ['BookingController', 'status'], ['csrf', 'can:bookings_manage']);
    $router->post('/{id:\d+}/cancel', ['BookingController', 'cancel'], ['csrf', 'can:bookings_cancel']);
    $router->post('/{id:\d+}/host', ['BookingController', 'assignHost'], ['csrf', 'can:bookings_manage']);

    $router->post('/{id:\d+}/payments', ['BookingController', 'storePayment'], ['csrf', 'can:payments_record']);
    $router->post(
        '/{id:\d+}/payments/delete',
        ['BookingController', 'destroyPayment'],
        ['csrf', 'can:payments_record']
    );

    $router->post('/{id:\d+}/tasks', ['BookingController', 'storeTask'], ['csrf', 'can:bookings_manage']);
    $router->post(
        '/{id:\d+}/tasks/toggle',
        ['BookingController', 'toggleTask'],
        ['csrf', 'can:bookings_manage']
    );

    $router->post('/{id:\d+}/guests', ['BookingController', 'storeGuest'], ['csrf', 'can:bookings_manage']);
    $router->post(
        '/{id:\d+}/guests/delete',
        ['BookingController', 'destroyGuest'],
        ['csrf', 'can:bookings_manage']
    );
});

// ---------------------------------------------------------------------------
// The calendar
//
// Month, week and day across every booking, plus the iCal feed.
//
// `/calendar/feed.ics` IS NOT BEHIND `auth`, and that is the whole design
// problem of an iCal feed: a calendar application cannot sign in, so a bearer
// token in the query string is the only authentication available. The feed
// therefore carries the time, the room, the headcount and the name on each
// party and NOT the allergy note, the money or the family's details — see
// CalendarController's class docblock. It is also rate limited, because an
// unauthenticated endpoint that reads the diary is worth guessing at, and the
// token is re-checked against the live user and role on every fetch.
//
// Minting and revoking a token are ordinary CSRF-protected posts behind
// `view_bookings`: a feed shows what its holder may see, so the permission to
// create one is the permission to read the diary.
// ---------------------------------------------------------------------------

$router->get(
    '/calendar/feed.ics',
    ['CalendarController', 'feed'],
    ['throttle:60,5'],
    'calendar.feed'
);

$router->group('/calendar', ['auth', 'can:view_bookings'], function ($router): void {
    $router->get('', ['CalendarController', 'index'], [], 'calendar');
    $router->post('/feed', ['CalendarController', 'createToken'], ['csrf']);
    $router->post('/feed/revoke', ['CalendarController', 'revokeToken'], ['csrf']);
});

// ---------------------------------------------------------------------------
// Waivers and check-in
//
// `/waiver` and `/waiver/{token}` ARE NOT BEHIND `auth`: a parent signing a
// waiver on their phone at the door has no account, and never will. They are
// the second and third public pages in the application after the web forms,
// and they follow the same rules — CSRF-protected (rendering the page mints a
// token into the visitor's own session), rate limited, never indexed, never
// cached, and handed only the keys a guest may know.
//
// Managing VERSIONS is `waivers_manage`, because the wording is a legal
// document. Checking guests in is `checkin`, which the Front Desk role holds
// and which deliberately does not include editing a booking: the person at
// the counter with a child in front of them should be able to add the name
// and tick them in without being able to re-price the party.
// ---------------------------------------------------------------------------

$router->get('/waiver', ['WaiverController', 'sign'], ['throttle:120,5'], 'waiver');
$router->post('/waiver', ['WaiverController', 'storeSignature'], ['csrf', 'throttle:20,10']);
$router->get('/waiver/{token:[a-f0-9]{32}}', ['WaiverController', 'receipt'], ['throttle:60,5'], 'waiver.receipt');

$router->group('/waivers', ['auth', 'can:waivers_manage'], function ($router): void {
    $router->get('', ['WaiverController', 'index'], [], 'waivers');
    $router->get('/new', ['WaiverController', 'create'], [], 'waivers.create');
    $router->post('', ['WaiverController', 'store'], ['csrf']);
    $router->get('/{id:\d+}', ['WaiverController', 'show'], [], 'waivers.show');
    $router->get('/{id:\d+}/edit', ['WaiverController', 'edit'], [], 'waivers.edit');
    $router->post('/{id:\d+}', ['WaiverController', 'update'], ['csrf']);
    $router->post('/{id:\d+}/publish', ['WaiverController', 'publish'], ['csrf']);
    $router->post('/{id:\d+}/delete', ['WaiverController', 'destroy'], ['csrf']);
});

// ---------------------------------------------------------------------------
// Asking for a party, from the venue's own website
//
// `/book` IS NOT BEHIND `auth` either, and is the fourth and last public page.
// It answers 404 until the venue switches it on (`bookings.public_enabled`),
// and the same 404 when it is on but no package is active — one answer for
// every reason it will not serve, so poking at the URL says nothing about the
// configuration behind it.
//
// The GET is throttled because it runs an availability pass per request; the
// POST is throttled harder because it WRITES, and what it writes holds a room.
// The daily allowance inside the controller is the second half of that bound:
// the throttle limits how fast enquiries can arrive, the allowance limits how
// many one date will take at all.
// ---------------------------------------------------------------------------

$router->get('/book', ['PublicBookingController', 'show'], ['throttle:120,5'], 'book');
$router->post('/book', ['PublicBookingController', 'store'], ['csrf', 'throttle:10,10']);

$router->group('/checkin', ['auth', 'can:checkin'], function ($router): void {
    $router->get('', ['CheckinController', 'index'], [], 'checkin');
    $router->get('/{id:\d+}', ['CheckinController', 'show'], [], 'checkin.show');
    $router->post('/{id:\d+}/guest', ['CheckinController', 'toggleGuest'], ['csrf']);
    $router->post('/{id:\d+}/arrive-all', ['CheckinController', 'arriveAll'], ['csrf']);
    $router->post('/{id:\d+}/guests', ['CheckinController', 'storeGuest'], ['csrf']);
    $router->post('/{id:\d+}/waiver', ['CheckinController', 'linkWaiver'], ['csrf']);
});

// ---------------------------------------------------------------------------
// Phase 2+ module routes are added as each module lands. The placeholders
// below give the navigation somewhere real to point in Phase 1, and each
// renders an honest "coming in Phase N" state rather than a broken link.
// ---------------------------------------------------------------------------

$router->get('/inbox', ['PlaceholderController', 'inbox'], ['auth', 'can:view_inbox'], 'inbox');
$router->get('/marketing', ['PlaceholderController', 'marketing'], ['auth', 'can:view_marketing'], 'marketing');
$router->get('/reputation', ['PlaceholderController', 'reputation'], ['auth', 'can:view_reputation'], 'reputation');
$router->get('/memberships', ['PlaceholderController', 'memberships'], ['auth', 'can:view_memberships'], 'memberships');
$router->get('/reports', ['PlaceholderController', 'reports'], ['auth', 'can:view_reports'], 'reports');
