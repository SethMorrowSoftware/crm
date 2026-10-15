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
 *   throttle:n,m    — at most n requests per m minutes per IP per path
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
// Phase 2+ module routes are added as each module lands. The placeholders
// below give the navigation somewhere real to point in Phase 1, and each
// renders an honest "coming in Phase N" state rather than a broken link.
// ---------------------------------------------------------------------------

$router->get('/contacts', ['PlaceholderController', 'contacts'], ['auth', 'can:view_contacts'], 'contacts');
$router->get('/inbox', ['PlaceholderController', 'inbox'], ['auth', 'can:view_inbox'], 'inbox');
$router->get('/deals', ['PlaceholderController', 'deals'], ['auth', 'can:view_deals'], 'deals');
$router->get('/bookings', ['PlaceholderController', 'bookings'], ['auth', 'can:view_bookings'], 'bookings');
$router->get('/marketing', ['PlaceholderController', 'marketing'], ['auth', 'can:view_marketing'], 'marketing');
$router->get('/reputation', ['PlaceholderController', 'reputation'], ['auth', 'can:view_reputation'], 'reputation');
$router->get('/memberships', ['PlaceholderController', 'memberships'], ['auth', 'can:view_memberships'], 'memberships');
$router->get('/tasks', ['PlaceholderController', 'tasks'], ['auth', 'can:view_tasks'], 'tasks');
$router->get('/reports', ['PlaceholderController', 'reports'], ['auth', 'can:view_reports'], 'reports');
