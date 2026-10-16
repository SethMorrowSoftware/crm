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
     * @return array<string,array<string,string>>
     */
    private static function apiPermissions(): array
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
        }

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
