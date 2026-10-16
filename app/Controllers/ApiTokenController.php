<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acl;
use App\Core\ApiToken;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * API tokens: mint, scope, revoke.
 *
 * Behind `integrations_manage` rather than `settings`, for the same reason
 * the mail settings are: this page hands out a credential that reaches guest
 * data from outside the building, which is a different decision from setting
 * the venue's opening hours.
 *
 * **THE PLAINTEXT IS SHOWN EXACTLY ONCE**, through a one-shot flash, and is
 * never stored anywhere this application can read it back. A venue that loses
 * one revokes it and mints another, which takes ten seconds; a venue that
 * could retrieve it from a page has a credential sitting in a database
 * forever, readable by anybody who ever gets a copy.
 */
final class ApiTokenController extends Controller
{
    public const PERMISSION = 'integrations_manage';

    /** Scoping a token to more than this is unmanageable; use "everything". */
    private const MAX_ABILITIES = 30;

    public function index(Request $request): Response
    {
        if (($denied = $this->authorize(self::PERMISSION)) !== null) {
            return $denied;
        }

        /*
         * The plaintext of a token just minted, if there is one. Pulled from
         * the session and CLEARED by the read, so a refresh does not show it
         * again and the browser's back button cannot either.
         */
        $fresh = Session::pull('api_token_plaintext');

        $tokens = ApiToken::all();

        foreach ($tokens as $index => $token) {
            $tokens[$index]['ability_list'] = ApiToken::abilities($token);
            $tokens[$index]['expired']      = ApiToken::hasExpired($token['expires_at'] ?? null);
        }

        return $this->view('settings/api-tokens', [
            'pageTitle'  => 'API tokens',
            'tokens'     => $tokens,
            'fresh'      => is_string($fresh) ? $fresh : null,
            'users'      => DB::all(
                'SELECT u.id, u.name, u.email, r.name AS role_name
                   FROM {users} u LEFT JOIN {roles} r ON r.id = u.role_id
                  WHERE u.deleted_at IS NULL AND u.is_active = 1
                  ORDER BY u.name'
            ),
            // Only the permissions the API actually CHECKS. The full
            // catalogue is 52, and the ~15 that matter were buried among
            // "Run backups" and "Leave Safe Mode" — which a token cannot use
            // for anything, because no API route asks about them. Offering
            // them was a list that looked thorough and read as noise.
            'catalogue'  => self::apiPermissions(),
            'maxAbilities' => self::MAX_ABILITIES,
        ]);
    }

    /**
     * The permissions an API token can usefully carry, grouped by resource.
     *
     * Built from `ApiResource::RESOURCES` rather than written out, so a
     * resource added there appears here without anybody remembering to.
     * `view_revenue` is added by hand because it gates money on every
     * resource rather than belonging to one.
     *
     * **EVERY PERMISSION THE API CONSULTS HAS TO BE OFFERED HERE**, which is
     * not a tidiness point: an ability a token cannot be given is a capability
     * that only a `['*']` token has. When DELETE stopped reusing the write
     * permission, this list still named only read and write — so a token could
     * be scoped to read and edit contacts but never to delete one, and an
     * operator who needed that had to leave every box unticked and mint a
     * token carrying everything its account can do. A narrowing fix that
     * pushes people onto the widest credential available has made things
     * worse. `tests/unit/ApiTokenTest.php` now asserts the two lists agree.
     *
     * Public so that test can read it; it is a catalogue of labels with no
     * I/O and nothing private in it.
     *
     * @return array<string,array<string,string>>
     */
    public static function apiPermissions(): array
    {
        $groups = [];

        foreach (\App\Services\ApiResource::RESOURCES as $name => $definition) {
            $label = ucfirst(str_replace('-', ' ', $name));

            $read = (string) $definition['read'];
            $groups[$label][$read] = 'Read — ' . (Acl::CATALOG[$read][1] ?? $read);

            if ($definition['write'] !== null && $definition['writable'] !== []) {
                $write = (string) $definition['write'];
                $groups[$label][$write] = 'Write — ' . (Acl::CATALOG[$write][1] ?? $write);
            }

            // Only where there is really something to delete into: a resource
            // with no `deleted_at` answers 405 whatever a token carries, and a
            // checkbox for it would promise a power it cannot grant.
            if ($definition['delete'] !== null && $definition['soft_delete']) {
                $delete = (string) $definition['delete'];
                $groups[$label][$delete] = 'Delete — ' . (Acl::CATALOG[$delete][1] ?? $delete);
            }
        }

        /*
         * The two writes that are their own endpoints rather than a field, so
         * they are named by `ApiController` directly and would otherwise be
         * missing from this list for the same reason delete was: recording
         * consent and moving a deal between stages.
         */
        $groups['Contacts']['contacts_manage'] ??= 'Write — ' . (Acl::CATALOG['contacts_manage'][1] ?? 'contacts_manage');
        $groups['Deals']['deals_manage']       ??= 'Write — ' . (Acl::CATALOG['deals_manage'][1] ?? 'deals_manage');

        $groups['Money'] = [
            'view_revenue' => 'See money: without this, every total comes back empty',
        ];

        return $groups;
    }

    public function store(Request $request): Response
    {
        if (($denied = $this->authorize(self::PERMISSION)) !== null) {
            return $denied;
        }

        $data = $this->validate($request, [
            'name'    => 'required|max:80',
            'user_id' => 'required|int',
        ], ['name' => 'Name', 'user_id' => 'User']);

        if ($data instanceof Response) {
            return $data;
        }

        $userId = (int) $data['user_id'];

        $user = DB::first(
            'SELECT id, name, role_id FROM {users} WHERE id = :id AND deleted_at IS NULL AND is_active = 1',
            ['id' => $userId]
        );

        if ($user === null) {
            flash('error', 'That user is not here any more.');

            return $this->redirect('/settings/api-tokens');
        }

        /*
         * A TOKEN CANNOT BE MINTED FOR SOMEBODY MORE POWERFUL THAN YOU.
         *
         * Without this, anybody holding `integrations_manage` could mint a
         * token for the owner and hold full access to everything — the
         * privilege-escalation route the role editor already closes for role
         * ASSIGNMENT, arriving through a different door.
         *
         * `Acl::mayManageUser()` is that same rule, reused rather than
         * rewritten: the comment on it records that the hole it closes
         * existed precisely because only one of two places checked.
         */
        if (!Acl::mayManageUser($user)) {
            flash('error', 'You cannot create a token for an account with more access than your own.');

            return $this->redirect('/settings/api-tokens');
        }

        $abilities = $request->all()['abilities'] ?? [];
        $abilities = is_array($abilities) ? $abilities : [];

        if ($abilities === [] || in_array(ApiToken::ALL, $abilities, true)) {
            $abilities = [ApiToken::ALL];
        }

        /*
         * A CHOSEN SET THAT SURVIVES NOTHING IS REFUSED, NOT MINTED EMPTY.
         *
         * `cleanAbilities()` drops anything not in the permission catalogue,
         * which is the right direction — but if it drops EVERYTHING the
         * result is a token that can do nothing, stored under a name that
         * suggests otherwise. (It used to be worse: an empty list read as
         * unrestricted, so the narrowest request produced the widest token.
         * `ApiToken::allows()` now fails closed, and this stops the useless
         * token being created in the first place.) The likely cause is a
         * renamed permission, so say which names were not recognised.
         */
        $cleaned = ApiToken::cleanAbilities($abilities);

        if ($cleaned === []) {
            flash(
                'error',
                'None of those abilities exist any more: '
                    . implode(', ', array_slice(array_map('strval', $abilities), 0, 6))
                    . '. Pick them again from the list.'
            );

            return $this->redirect('/settings/api-tokens');
        }

        if (count($abilities) > self::MAX_ABILITIES) {
            flash('error', 'That is a lot of separate abilities. Use "everything this user can do" instead.');

            return $this->redirect('/settings/api-tokens');
        }

        $created = ApiToken::create(
            $userId,
            (string) $data['name'],
            $abilities,
            ApiToken::expiryFrom($request->str('expires_on', '') ?: null)
        );

        Audit::event('api_token_created', [
            'token_id'  => $created['id'],
            'name'      => (string) $data['name'],
            'for_user'  => $userId,
            'abilities' => ApiToken::cleanAbilities($abilities),
        ], 'api_tokens', $created['id']);

        // One shot. Read and cleared by the page that shows it.
        Session::put('api_token_plaintext', $created['token']);

        flash('success', 'Token created. Copy it now — it is not shown again.');

        return $this->redirect('/settings/api-tokens');
    }

    public function destroy(Request $request): Response
    {
        if (($denied = $this->authorize(self::PERMISSION)) !== null) {
            return $denied;
        }

        $id = $request->routeInt('id');

        $token = DB::first(
            'SELECT t.id, t.name, t.user_id, u.role_id
               FROM {api_tokens} t JOIN {users} u ON u.id = t.user_id
              WHERE t.id = :id',
            ['id' => $id]
        );

        if ($token === null) {
            flash('error', 'That token is already gone.');

            return $this->redirect('/settings/api-tokens');
        }

        if (!Acl::mayManageUser(['id' => (int) $token['user_id'], 'role_id' => (int) $token['role_id']])) {
            flash('error', 'You cannot revoke a token belonging to an account with more access than your own.');

            return $this->redirect('/settings/api-tokens');
        }

        ApiToken::revoke($id);

        Audit::event('api_token_revoked', [
            'token_id' => $id,
            'name'     => (string) $token['name'],
        ], 'api_tokens', $id);

        // Said plainly, because it is the question somebody asks next: a
        // revoked token stops working on the very next request, not when
        // something expires.
        flash('success', 'Revoked. Anything using it stops working immediately.');

        return $this->redirect('/settings/api-tokens');
    }

}
