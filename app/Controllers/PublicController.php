<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Event;
use App\Core\Log;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

/**
 * Endpoints a guest reaches without an account.
 *
 * Unsubscribing has to work on the first click, from an email client, with no
 * login and no confirmation step — that is both the law's expectation and
 * simple decency. So this controller is deliberately forgiving about method
 * and token age, and deliberately strict about doing nothing else.
 */
final class PublicController extends Controller
{
    /**
     * One-click unsubscribe.
     *
     * Answers both GET (a human clicking the footer link) and POST (RFC 8058
     * one-click, which Gmail and Yahoo send on the recipient's behalf). The
     * POST form is exempt from CSRF in the route table, because the whole
     * point is that a third party submits it — and the token is itself the
     * authorisation.
     */
    public function unsubscribe(Request $request): Response
    {
        $token = (string) $request->route('token', '');

        $parsed = Mailer::parseUnsubscribeToken($token);

        if ($parsed === null) {
            Log::notice('Invalid unsubscribe token', ['ip' => $request->ip()]);

            return $this->unindexed($this->view('public/unsubscribe', [
                'pageTitle' => 'Unsubscribe',
                'state'     => 'invalid',
                'address'   => null,
            ], 'layouts/bare'));
        }

        $address = strtolower($parsed['address']);

        // Idempotent: a second click, or a mail client prefetching the link,
        // must not error or report something different.
        $already = DB::exists(
            'SELECT id FROM {suppressions} WHERE channel = :c AND address = :a',
            ['c' => 'email', 'a' => $address]
        );

        $unsubscribed = null;

        if (!$already) {
            DB::transaction(static function () use ($address, $parsed, $request, &$unsubscribed): void {
                DB::insert('suppressions', [
                    'channel'    => 'email',
                    'address'    => $address,
                    'reason'     => 'unsubscribe',
                    'source'     => 'one-click link',
                    'created_at' => now_utc(),
                ]);

                $contactId = $parsed['contact_id'];

                // Fall back to matching on the address, since a token may
                // predate the contact record.
                if ($contactId === null) {
                    $found = DB::value(
                        'SELECT id FROM {contacts} WHERE email = :e AND deleted_at IS NULL LIMIT 1',
                        ['e' => $address]
                    );
                    $contactId = $found === null ? null : (int) $found;
                }

                $unsubscribed = $contactId;

                if ($contactId !== null) {
                    DB::update('contacts', [
                        'email_consent'        => 0,
                        'marketing_opt_out_at' => now_utc(),
                        'updated_at'           => now_utc(),
                    ], 'id = :id', ['id' => $contactId]);

                    // The revocation is evidence too: a consent record that
                    // only logs grants cannot answer "when did they opt out?"
                    DB::insert('consent_log', [
                        'channel'    => 'email',
                        'action'     => 'revoked',
                        'source'     => 'one-click unsubscribe link',
                        'contact_id' => $contactId,
                        'ip'         => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'evidence'   => json_encode([
                            'method'  => $request->method(),
                            'address' => $address,
                        ], JSON_UNESCAPED_SLASHES),
                        'created_at' => now_utc(),
                    ]);
                }
            });

            Audit::event('contact.unsubscribed', ['address' => $address, 'via' => 'one-click']);

            // After the transaction, and only when a contact was found: an
            // unsubscribe for an address nobody matches has nothing an
            // automation could act on. A venue's "confirm the removal and ask
            // why" sequence hangs off this.
            if ($unsubscribed !== null) {
                Event::fire('contact.consent_revoked', [
                    'contact_id' => $unsubscribed,
                    'channel'    => 'email',
                    'source'     => 'one-click unsubscribe link',
                ]);
            }
        }

        // RFC 8058 wants a 200 with no body requirement for the POST form.
        if ($request->isMethod('POST')) {
            return $this->unindexed(Response::text("Unsubscribed.\n"));
        }

        return $this->unindexed($this->view('public/unsubscribe', [
            'pageTitle' => 'Unsubscribed',
            'state'     => $already ? 'already' : 'done',
            'address'   => $address,
            'venueName' => Settings::venueName(),
        ], 'layouts/bare'));
    }

    /**
     * Keep a page out of search engines and out of caches.
     *
     * An unsubscribe URL is a bearer token in a path. Indexed, it becomes an
     * unsubscribe link anybody can find and follow; cached by a shared proxy,
     * one guest's confirmation can be served to the next visitor. Neither is
     * hypothetical — search engines have indexed plenty of tokenised URLs
     * that a mail client prefetched.
     */
    private function unindexed(Response $response): Response
    {
        return $response
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->noCache();
    }
}
