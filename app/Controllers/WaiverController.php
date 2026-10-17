<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acl;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Event;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\AvailabilityEngine;
use App\Services\BookingRepository;
use PDOException;

/**
 * Liability waivers.
 *
 * A waiver is the one record in this application whose value is entirely
 * evidential: it exists so that months later somebody can say *this person
 * agreed to exactly this text, at this time, from this address*. Everything
 * here follows from that.
 *
 * A PUBLISHED VERSION IS IMMUTABLE. Editing the text somebody has already
 * signed destroys the only thing the signature was for — it would leave a row
 * claiming a guest agreed to wording they never saw. So only a DRAFT can be
 * edited; a published version can be retired and replaced, never rewritten.
 * This is the same rule as a sent quote, and it matters more.
 *
 * A SIGNATURE NAMES ITS VERSION. `waiver_signatures.waiver_id` is not a
 * convenience: it is what makes the text recoverable. The signing page records
 * the version that was active at the moment of signing, so publishing a new
 * one tomorrow does not retroactively change what anybody agreed to.
 *
 * EXACTLY ONE VERSION IS ACTIVE. The schema allows several — there is no
 * unique key on `is_active` — so publishing is a transaction that retires the
 * others. Two active versions would mean the public page's answer to "what am
 * I signing?" depended on row order.
 *
 * THE SIGNATURE IS A TYPED NAME, not a drawing. A typed full name with an
 * explicit agreement tick is the ordinary e-signature pattern, it is reachable
 * by keyboard and screen reader, and it needs no canvas and no JavaScript —
 * this application has no build step. What is stored is what was typed, the
 * method, and the wording of the agreement, which is what a dispute turns on.
 * A drawn signature could be added later; it would be decoration on top of
 * this, not a replacement for it.
 */
final class WaiverController extends Controller
{
    /** How long a signature stands before it is asked for again. */
    public const EXPIRY_SETTING = 'waivers.expiry_days';

    /** A year, which is what a venue that re-signs annually means. */
    public const DEFAULT_EXPIRY_DAYS = 365;

    /** Minors on one signature. A school trip signs per group, not per bus. */
    public const MAX_MINORS = 30;

    /** The agreement wording, stored with every signature as evidence. */
    public const AGREEMENT = 'I have read this waiver in full, I understand it, '
        . 'and I agree to it on behalf of myself and of anybody I have named above.';

    // =================================================================
    // The admin side
    // =================================================================

    public function index(Request $request): Response
    {
        if ($refusal = $this->authorize('waivers_manage')) {
            return $refusal;
        }

        return $this->view('waivers/index', [
            'pageTitle' => 'Waivers',
            'waivers'   => DB::all(
                'SELECT w.id, w.version, w.title, w.requires_guardian, w.is_active,
                        w.published_at, w.created_at, w.updated_at,
                        (SELECT COUNT(*) FROM {waiver_signatures} s WHERE s.waiver_id = w.id) AS signatures
                   FROM {waivers} w
                  ORDER BY w.is_active DESC, w.published_at DESC, w.id DESC'
            ),
            'publicUrl' => url('/waiver'),
            'expiryDays' => $this->expiryDays(),
        ]);
    }

    public function create(Request $request): Response
    {
        if ($refusal = $this->authorize('waivers_manage')) {
            return $refusal;
        }

        return $this->view('waivers/form', [
            'pageTitle' => 'New waiver version',
            'waiver'    => null,
        ]);
    }

    public function store(Request $request): Response
    {
        if ($refusal = $this->authorize('waivers_manage')) {
            return $refusal;
        }

        $data = $this->readWaiver($request, '/waivers/new');

        if ($data instanceof Response) {
            return $data;
        }

        try {
            $id = DB::insert('waivers', $data + [
                // A new version is always a DRAFT. Publishing is its own
                // decision, with its own button, because it changes what every
                // guest is shown from that moment on.
                'is_active'  => 0,
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                return $this->refuse(
                    $request,
                    'There is already a version called "' . $data['version'] . '".',
                    '/waivers/new'
                );
            }

            throw $exception;
        }

        Audit::record('waiver.created', 'waivers', $id, null, ['version' => $data['version']]);

        return $this->success($request, 'Draft saved. Publish it when the wording is right.', '/waivers/' . $id);
    }

    public function show(Request $request): Response
    {
        if ($refusal = $this->authorize('waivers_manage')) {
            return $refusal;
        }

        $waiver = $this->find($request->routeInt('id'));

        if ($waiver === null) {
            return $this->notFound('That waiver version does not exist.');
        }

        $id = (int) $waiver['id'];

        return $this->view('waivers/show', [
            'pageTitle'  => 'Waiver ' . $waiver['version'],
            'waiver'     => $waiver,
            'signatures' => DB::all(
                'SELECT s.id, s.signer_name, s.signer_email, s.is_guardian, s.minors,
                        s.signed_at, s.expires_at, s.ip, s.booking_id,
                        b.booking_number
                   FROM {waiver_signatures} s
                   LEFT JOIN {bookings} b ON b.id = s.booking_id
                  WHERE s.waiver_id = :id
                  ORDER BY s.signed_at DESC, s.id DESC
                  LIMIT 200',
                ['id' => $id]
            ),
            'total'      => (int) DB::value(
                'SELECT COUNT(*) FROM {waiver_signatures} WHERE waiver_id = :id',
                ['id' => $id]
            ),
            'publicUrl'  => url('/waiver'),
            'editable'   => (int) $waiver['is_active'] === 0 && $waiver['published_at'] === null,
        ]);
    }

    public function edit(Request $request): Response
    {
        if ($refusal = $this->authorize('waivers_manage')) {
            return $refusal;
        }

        $waiver = $this->find($request->routeInt('id'));

        if ($waiver === null) {
            return $this->notFound('That waiver version does not exist.');
        }

        if (!$this->isDraft($waiver)) {
            return $this->refuse($request, $this->frozenMessage($waiver), '/waivers/' . (int) $waiver['id']);
        }

        return $this->view('waivers/form', [
            'pageTitle' => 'Edit waiver ' . $waiver['version'],
            'waiver'    => $waiver,
        ]);
    }

    public function update(Request $request): Response
    {
        if ($refusal = $this->authorize('waivers_manage')) {
            return $refusal;
        }

        $id     = $request->routeInt('id');
        $waiver = $this->find($id);

        if ($waiver === null) {
            return $this->notFound('That waiver version does not exist.');
        }

        // THE RULE THIS CLASS EXISTS FOR. A published version is what people
        // have already agreed to; rewriting it would leave their signatures
        // pointing at wording they never saw.
        if (!$this->isDraft($waiver)) {
            return $this->refuse($request, $this->frozenMessage($waiver), '/waivers/' . $id);
        }

        $data = $this->readWaiver($request, '/waivers/' . $id . '/edit');

        if ($data instanceof Response) {
            return $data;
        }

        DB::update('waivers', $data + ['updated_at' => now_utc()], 'id = :id', ['id' => $id]);

        Audit::record('waiver.updated', 'waivers', $id, $waiver, $data);

        return $this->success($request, 'Draft updated.', '/waivers/' . $id);
    }

    /**
     * Publish a draft, retiring whatever was active.
     *
     * One transaction, because two active versions would make the public
     * page's answer to "what am I signing?" depend on row order.
     */
    public function publish(Request $request): Response
    {
        if ($refusal = $this->authorize('waivers_manage')) {
            return $refusal;
        }

        $id     = $request->routeInt('id');
        $waiver = $this->find($id);

        if ($waiver === null) {
            return $this->notFound('That waiver version does not exist.');
        }

        if ((int) $waiver['is_active'] === 1) {
            return $this->refuse($request, 'That version is already the live one.', '/waivers/' . $id);
        }

        if (trim((string) $waiver['body']) === '') {
            return $this->refuse(
                $request,
                'A waiver with no text cannot be published — there would be nothing to agree to.',
                '/waivers/' . $id
            );
        }

        $retired = DB::transaction(function () use ($id): array {
            $previous = DB::all('SELECT id, version FROM {waivers} WHERE is_active = 1');

            DB::update('waivers', ['is_active' => 0, 'updated_at' => now_utc()], 'is_active = 1', []);

            DB::update('waivers', [
                'is_active' => 1,
                // Stamped once, the first time it goes live: re-publishing a
                // version that was live before should not rewrite the date its
                // wording came into force.
                'published_at' => DB::value('SELECT published_at FROM {waivers} WHERE id = :id', ['id' => $id])
                    ?? now_utc(),
                'updated_at' => now_utc(),
            ], 'id = :id', ['id' => $id]);

            return $previous;
        });

        Event::fire('waiver.published', ['waiver_id' => $id, 'version' => (string) $waiver['version']]);
        Audit::record('waiver.published', 'waivers', $id, null, [
            'version' => (string) $waiver['version'],
            'retired' => array_map(static fn (array $r): string => (string) $r['version'], $retired),
        ]);

        return $this->success(
            $request,
            'Version ' . $waiver['version'] . ' is now the one guests sign.'
                . ($retired === [] ? '' : ' Signatures on the old version still stand.'),
            '/waivers/' . $id
        );
    }

    public function destroy(Request $request): Response
    {
        if ($refusal = $this->authorize('waivers_manage')) {
            return $refusal;
        }

        $id     = $request->routeInt('id');
        $waiver = $this->find($id);

        if ($waiver === null) {
            return $this->notFound('That waiver version does not exist.');
        }

        $signatures = (int) DB::value(
            'SELECT COUNT(*) FROM {waiver_signatures} WHERE waiver_id = :id',
            ['id' => $id]
        );

        // A version with signatures is EVIDENCE and is never deleted — the
        // foreign key refuses it anyway (no ON DELETE), and that refusal would
        // reach the operator as a 500 rather than as an explanation.
        if ($signatures > 0) {
            return $this->refuse(
                $request,
                'That version has ' . $signatures . ' signature' . ($signatures === 1 ? '' : 's')
                    . ' against it. It cannot be deleted: those signatures are the record of what '
                    . 'each guest agreed to. Publish a new version instead.',
                '/waivers/' . $id
            );
        }

        if ((int) $waiver['is_active'] === 1) {
            return $this->refuse(
                $request,
                'That is the live version. Publish another one first, so guests always have '
                    . 'something to sign.',
                '/waivers/' . $id
            );
        }

        DB::delete('waivers', 'id = :id', ['id' => $id]);

        Audit::record('waiver.deleted', 'waivers', $id, $waiver, null);

        return $this->success($request, 'Draft deleted.', '/waivers');
    }

    // =================================================================
    // The public side
    // =================================================================

    /**
     * The signing page, reached with no account.
     *
     * Two of the three views in this application rendered to somebody with no
     * session (the other being a web form), so the same rules apply: it echoes
     * only what this method hands it, and the operator-written waiver text is
     * untrusted input to the page like anything else — it goes through the
     * same escaping as a guest's own typing.
     */
    public function sign(Request $request): Response
    {
        $waiver = $this->active();

        if ($waiver === null) {
            // Honest rather than broken: a venue that has not published a
            // waiver should not show a guest an empty form to agree to.
            return $this->publicResponse($this->view('waivers/public', [
                'pageTitle' => 'Waiver',
                'waiver'    => null,
                'booking'   => null,
                'sent'      => false,
                'signature' => null,
                'venueName' => Settings::venueName(),
                'agreement' => self::AGREEMENT,
                'maxMinors' => self::MAX_MINORS,
            ], 'layouts/bare'));
        }

        return $this->publicResponse($this->view('waivers/public', [
            'pageTitle' => (string) $waiver['title'],
            'waiver'    => $this->publicWaiver($waiver),
            // A booking number typed into the link attaches the signature to
            // that party. Only its number and date come back — enough to say
            // "signing for Priya's party on Saturday" and nothing more.
            'booking'   => $this->publicBooking($request->str('booking')),
            'sent'      => false,
            'signature' => null,
            'venueName' => Settings::venueName(),
            'agreement' => self::AGREEMENT,
            'maxMinors' => self::MAX_MINORS,
        ], 'layouts/bare'));
    }

    public function storeSignature(Request $request): Response
    {
        $waiver = $this->active();

        if ($waiver === null) {
            return $this->signRefusal($request, ['_form' => 'There is no waiver to sign just now.']);
        }

        $booking = $this->publicBooking($request->str('booking'));

        $name  = trim($request->str('signer_name'));
        $email = trim($request->str('signer_email'));
        $phone = trim($request->str('signer_phone'));

        $errors = [];

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['signer_name'] = 'Please type your full name.';
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['signer_email'] = 'That does not look like an email address.';
        }

        if ($email === '' && $phone === '') {
            // Not pedantry: a signature nobody can be matched back to is
            // worth very little, and the venue needs a way to reach whoever
            // signed if something happens.
            $errors['signer_email'] = 'Please give an email address or a phone number.';
        }

        if ($request->str('agree') === '') {
            $errors['agree'] = 'The waiver has to be agreed to before it can be signed.';
        }

        $minors = $this->readMinors($request);

        if ($minors === null) {
            $errors['minors'] = 'A child\'s name is needed for each one being signed for.';
        }

        $isGuardian = $minors !== null && $minors !== [];

        if ((int) $waiver['requires_guardian'] === 1 && !$isGuardian && $request->str('for_self') === '') {
            $errors['_form'] = 'Say whether you are signing for yourself, for children, or both.';
        }

        if ($errors !== []) {
            return $this->signRefusal($request, $errors);
        }

        $expiryDays = $this->expiryDays();

        $signatureId = DB::transaction(function () use (
            $waiver,
            $booking,
            $name,
            $email,
            $phone,
            $minors,
            $isGuardian,
            $expiryDays,
            $request
        ): int {
            $token = bin2hex(random_bytes(16));

            $id = DB::insert('waiver_signatures', [
                // The version that was active AT SIGNING. This is the whole
                // point of the column: publishing a new waiver tomorrow must
                // not change what anybody has already agreed to.
                'waiver_id'   => (int) $waiver['id'],
                'contact_id'  => $this->matchContact($email, $phone),
                'booking_id'  => $booking === null ? null : (int) $booking['id'],
                'signer_name' => $name,
                'signer_email' => $email === '' ? null : strtolower($email),
                'signer_phone' => normalize_phone($phone === '' ? null : $phone),
                'is_guardian' => $isGuardian ? 1 : 0,
                'minors'      => $minors === [] ? null : json_encode($minors, JSON_UNESCAPED_UNICODE),
                // WHAT WAS AGREED TO, not a picture of a pen stroke: the typed
                // name, the method, and the wording shown. That is what a
                // dispute actually turns on.
                'signature_data' => json_encode([
                    'method'    => 'typed_name',
                    'typed'     => $name,
                    'agreement' => self::AGREEMENT,
                    'version'   => (string) $waiver['version'],
                ], JSON_UNESCAPED_UNICODE),
                'ip'          => $request->ip(),
                'user_agent'  => mb_substr($request->userAgent(), 0, 500),
                'signed_at'   => now_utc(),
                'expires_at'  => AvailabilityEngine::addDays(BookingRepository::today(), $expiryDays),
                'public_token' => $token,
            ]);

            // Link it to a guest on the party where the names match, so the
            // check-in desk sees a tick rather than having to read two lists.
            if ($booking !== null) {
                $this->linkGuests($id, (int) $booking['id'], $name, $minors);
            }

            return $id;
        });

        Event::fire('waiver.signed', [
            'signature_id' => $signatureId,
            'waiver_id'    => (int) $waiver['id'],
            'booking_id'   => $booking === null ? null : (int) $booking['id'],
        ]);

        // Source `waiver`, and no actor: this is a guest's action, not a
        // member of staff's.
        Audit::event('waiver.signed', [
            'version' => (string) $waiver['version'],
            'minors'  => count($minors),
        ], 'waiver_signatures', $signatureId);

        $signature = DB::first(
            'SELECT signer_name, signed_at, expires_at, public_token, minors
               FROM {waiver_signatures} WHERE id = :id',
            ['id' => $signatureId]
        );

        return $this->publicResponse($this->view('waivers/public', [
            'pageTitle' => 'Signed',
            'waiver'    => $this->publicWaiver($waiver),
            'booking'   => $booking,
            'sent'      => true,
            'signature' => $signature,
            'venueName' => Settings::venueName(),
            'agreement' => self::AGREEMENT,
            'maxMinors' => self::MAX_MINORS,
        ], 'layouts/bare'));
    }

    /**
     * A signed waiver's own receipt page.
     *
     * The token is the authentication, so this carries only what the signer
     * already knows: their own name, what they signed, and when it runs out.
     * No booking details, no other signature, nothing about the venue's diary.
     */
    public function receipt(Request $request): Response
    {
        $token = (string) $request->route('token', '');

        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return $this->notFound('That link does not point at a signed waiver.');
        }

        $signature = DB::first(
            'SELECT s.signer_name, s.signed_at, s.expires_at, s.minors, s.is_guardian,
                    w.version, w.title, w.body
               FROM {waiver_signatures} s
               JOIN {waivers} w ON w.id = s.waiver_id
              WHERE s.public_token = :token
              LIMIT 1',
            ['token' => $token]
        );

        if ($signature === null) {
            return $this->notFound('That link does not point at a signed waiver.');
        }

        return $this->publicResponse($this->view('waivers/receipt', [
            'pageTitle' => 'Your signed waiver',
            'signature' => $signature,
            'venueName' => Settings::venueName(),
        ], 'layouts/bare'));
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return DB::first('SELECT * FROM {waivers} WHERE id = :id', ['id' => $id]);
    }

    /**
     * The version a guest signs right now.
     *
     * `ORDER BY id DESC` is a belt-and-braces tie-break: publish() keeps
     * exactly one row active, and if a hand-edited database ever had two this
     * picks the newer deterministically rather than depending on row order.
     *
     * @return array<string,mixed>|null
     */
    private function active(): ?array
    {
        return DB::first('SELECT * FROM {waivers} WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
    }

    private function isDraft(array $waiver): bool
    {
        return (int) $waiver['is_active'] === 0 && $waiver['published_at'] === null;
    }

    private function frozenMessage(array $waiver): string
    {
        return 'Version ' . $waiver['version'] . ' has been published, so its wording is what '
            . 'guests have already agreed to and cannot be changed. Create a new version instead — '
            . 'the signatures on this one stay exactly as they are.';
    }

    /**
     * @return array<string,mixed>|Response
     */
    private function readWaiver(Request $request, string $back): array|Response
    {
        $data = $this->validate($request, [
            'version'           => 'required|string|min:1|max:20',
            'title'             => 'required|string|min:3|max:191',
            'body'              => 'required|string|min:40',
            'requires_guardian' => 'nullable|string',
        ], [
            'version' => 'Version',
            'title'   => 'Heading',
            'body'    => 'The waiver text',
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        return [
            'version' => trim((string) $data['version']),
            'title'   => trim((string) $data['title']),
            'body'    => (string) $data['body'],
            'requires_guardian' => ($data['requires_guardian'] ?? '') === '' ? 0 : 1,
        ];
    }

    /**
     * The children being signed for.
     *
     * Returns null when a row has an age or a note but no name — which means
     * somebody started typing a child and stopped, and silently dropping that
     * row would leave a child uncovered by a waiver their parent believes they
     * signed.
     *
     * @return list<array{name:string,age:int|null}>|null
     */
    private function readMinors(Request $request): ?array
    {
        $out = [];

        foreach ($request->array('minors') as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $age  = trim((string) ($row['age'] ?? ''));

            if ($name === '') {
                if ($age !== '') {
                    return null;
                }

                continue;
            }

            if (count($out) >= self::MAX_MINORS) {
                break;
            }

            $out[] = [
                'name' => mb_substr($name, 0, 120),
                'age'  => $age === '' || !ctype_digit($age) ? null : min(120, (int) $age),
            ];
        }

        return $out;
    }

    /**
     * The booking a signature attaches to, by its number.
     *
     * Only the number and the date come back. A guest typing a booking number
     * into a URL must not be able to read the party's figures, its notes or
     * the family's details out of this page, and a cancelled party is not
     * offered at all.
     *
     * @return array<string,mixed>|null
     */
    private function publicBooking(string $number): ?array
    {
        $number = trim($number);

        if ($number === '' || mb_strlen($number) > 32) {
            return null;
        }

        return DB::first(
            'SELECT id, booking_number, event_date, guest_of_honor_name
               FROM {bookings}
              WHERE booking_number = :number AND deleted_at IS NULL
                AND status NOT IN (\'cancelled\', \'no_show\')
              LIMIT 1',
            ['number' => $number]
        );
    }

    /**
     * The waiver, reduced to the keys a guest may know.
     *
     * The same shape as FormController's publicField(): a view handed a
     * database row grows whatever a later SELECT adds to it, and this page is
     * read by the public.
     *
     * @param array<string,mixed> $waiver
     * @return array<string,string>
     */
    private function publicWaiver(array $waiver): array
    {
        return [
            'id'      => (string) $waiver['id'],
            'version' => (string) $waiver['version'],
            'title'   => (string) $waiver['title'],
            'body'    => (string) $waiver['body'],
            'requires_guardian' => (string) (int) $waiver['requires_guardian'],
        ];
    }

    /** An existing guest this signature belongs to, by email then phone. */
    private function matchContact(string $email, string $phone): ?int
    {
        if ($email !== '') {
            $id = DB::value(
                'SELECT id FROM {contacts} WHERE email = :e AND deleted_at IS NULL ORDER BY id LIMIT 1',
                ['e' => strtolower($email)]
            );

            if ($id !== null) {
                return (int) $id;
            }
        }

        $normalized = normalize_phone($phone === '' ? null : $phone);

        if ($normalized === null) {
            return null;
        }

        // Phone is a fallback and never a tie-break, exactly as in the web
        // form: a household landline or a parent's mobile on a child's form
        // would otherwise attach a signature to the wrong person.
        $id = DB::value(
            'SELECT id FROM {contacts} WHERE phone_normalized = :p AND deleted_at IS NULL ORDER BY id LIMIT 1',
            ['p' => $normalized]
        );

        return $id === null ? null : (int) $id;
    }

    /**
     * Tie a signature to the guests it covers.
     *
     * Name matching, deliberately narrow: an exact case-insensitive match on a
     * guest whose waiver is not already recorded. A fuzzy match here would put
     * a tick against the wrong child, and at a check-in desk a wrong tick is
     * worse than no tick — somebody walks past a waiver nobody signed.
     *
     * @param list<array{name:string,age:int|null}> $minors
     */
    private function linkGuests(int $signatureId, int $bookingId, string $signerName, array $minors): void
    {
        $names = array_map(
            static fn (array $minor): string => $minor['name'],
            $minors
        );

        $names[] = $signerName;

        foreach ($names as $name) {
            DB::query(
                'UPDATE {booking_guests}
                    SET waiver_id = :sid
                  WHERE booking_id = :bid AND waiver_id IS NULL AND LOWER(name) = LOWER(:name)',
                ['sid' => $signatureId, 'bid' => $bookingId, 'name' => $name]
            );
        }
    }

    private function expiryDays(): int
    {
        $stored = Settings::get(self::EXPIRY_SETTING);

        if ($stored === null || $stored === '') {
            return self::DEFAULT_EXPIRY_DAYS;
        }

        // A year either way of sense. Zero would expire a signature the moment
        // it was given; ten years is longer than any venue means.
        return max(1, min(3650, (int) $stored));
    }

    /**
     * Re-render the public page with its errors, keeping what was typed.
     *
     * @param array<string,string> $errors
     */
    private function signRefusal(Request $request, array $errors): Response
    {
        Session::put('_errors', array_map(static fn (string $m): array => [$m], $errors));

        $input = $request->all();
        unset($input['_token']);

        Session::put('_old_input_next', $input);

        flash('error', (string) reset($errors));

        // A REDIRECT, not a re-render. Session::put() writes for the NEXT
        // request, so rendering here would show the form with no messages on
        // it at all — the flash and every field error would appear one
        // request late, on whatever page the guest went to next. The booking
        // number is carried so the party it was signed against is not lost.
        $booking = trim($request->str('booking'));

        return $this->publicResponse($this->redirect(
            '/waiver' . ($booking === '' ? '' : '?booking=' . urlencode($booking))
        ));
    }

    /** Public pages are never indexed and never cached. */
    private function publicResponse(Response $response): Response
    {
        return $response->header('X-Robots-Tag', 'noindex, nofollow')->noCache();
    }
}
