<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acl;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Event;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\View;
use DateTimeImmutable;
use Exception;
use PDOException;

/**
 * Quotes: the priced offer a venue sends a family or a group organiser.
 *
 * Three things drive the shape of this class.
 *
 * THE ARITHMETIC IS THE PRODUCT. A quote that disagrees with its own lines is
 * worse than no quote at all — the venue has put a number in writing. So every
 * figure is derived, in integer cents, by one pure function (totals()), and the
 * derived figures are the ONLY ones written. Nothing a form posts is trusted as
 * a total. `qty` is DECIMAL(10,2) in the schema, so quantities are carried as
 * integer HUNDREDTHS and never as a float: 0.1 has no exact binary
 * representation, and three lines of it is how a total ends up a cent out.
 *
 * EDITS STOP AT THE DRAFT BOUNDARY. Once a quote is sent, the guest is holding
 * a copy of it. Re-pricing it in place would leave the venue and the guest
 * reading different documents with the same number on them, so editing a sent
 * quote is refused and the operator has to put it back to draft deliberately.
 *
 * WHO SENT IT COMES FROM THE AUDIT LOG. `cc_quotes` records `sent_at` but has
 * no `sent_by` column, and the schema is not ours to change here. The actor is
 * therefore read back out of the audit row that the send writes — which is the
 * durable record anyway, and the one a dispute would be settled from.
 */
final class QuoteController extends Controller
{
    /** Statuses the `cc_quotes` enum actually declares. Read, not guessed. */
    public const STATUSES = ['draft', 'sent', 'viewed', 'accepted', 'declined', 'expired'];

    /**
     * Which status may follow which.
     *
     * `accepted` is terminal: a quote the guest has agreed to is the basis of a
     * booking, and quietly reopening it would detach the booking from the
     * numbers it was sold on. A declined or expired quote CAN go back to draft,
     * because revising and re-sending is the normal next move.
     *
     * `sent` is absent from every list on purpose — only send() sets it, so the
     * `quotes_send` gate cannot be stepped around through the status form.
     *
     * @var array<string,list<string>>
     */
    private const TRANSITIONS = [
        'draft'    => [],
        'sent'     => ['viewed', 'accepted', 'declined', 'expired', 'draft'],
        'viewed'   => ['accepted', 'declined', 'expired', 'draft'],
        'accepted' => [],
        'declined' => ['draft'],
        'expired'  => ['draft'],
    ];

    /** Statuses a quote may be sent (or re-sent) from. */
    private const SENDABLE = ['draft', 'sent', 'viewed'];

    /** A quote may only be re-priced while it is a draft. */
    private const EDITABLE = ['draft'];

    /** Lines per quote. Enough for a party package with every add-on. */
    private const MAX_LINES = 60;

    /** $9,999,999.99 a line. A bigger figure is a typo, not a party. */
    private const MAX_UNIT_CENTS = 999999999;

    /** Quantity ceiling, in hundredths — 10,000.00. */
    private const MAX_QTY_HUNDREDTHS = 1000000;

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    public function index(Request $request): Response
    {
        if ($refusal = $this->authorize('view_deals')) {
            return $refusal;
        }

        $status = (string) $request->query('status', '');

        if (!in_array($status, self::STATUSES, true)) {
            $status = '';
        }

        // view_deals is a scoped permission, so a Party Host sees quotes for
        // their own deals and a manager sees the team's. A quote with no deal
        // has no owner to scope by; it is shown to anyone who may see quotes at
        // all, which is the same reach the deal-less quote itself has.
        [$scope, $scopeParams] = Acl::scopeCondition('view_deals', 'd.owner_id');

        $params = $scopeParams;
        $where  = '(q.deal_id IS NULL OR ' . $scope . ')';

        if ($status !== '') {
            $where .= ' AND q.status = :status';
            $params['status'] = $status;
        }

        $page  = $this->pagination($request, 25, 100);
        $total = (int) DB::value(
            'SELECT COUNT(*) FROM {quotes} q LEFT JOIN {deals} d ON d.id = q.deal_id WHERE ' . $where,
            $params
        );

        $rows = DB::all(
            'SELECT q.id, q.number, q.status, q.total_cents, q.valid_until, q.sent_at,
                    q.created_at, q.deal_id,
                    d.title AS deal_title,
                    c.first_name, c.last_name,
                    (SELECT COUNT(*) FROM {quote_lines} l WHERE l.quote_id = q.id) AS line_count
               FROM {quotes} q
               LEFT JOIN {deals} d ON d.id = q.deal_id
               LEFT JOIN {contacts} c ON c.id = d.contact_id
              WHERE ' . $where . '
              ORDER BY q.id DESC
              LIMIT :limit OFFSET :offset',
            $params + ['limit' => $page['perPage'], 'offset' => $page['offset']]
        );

        return $this->view('quotes/index', [
            'pageTitle' => 'Quotes',
            // A DATE column needs its own formatter — see calendarDate().
            'calendarDate' => self::calendarDate(...),
            'quotes'    => $rows,
            'total'     => $total,
            'page'      => $page['page'],
            'lastPage'  => max(1, (int) ceil($total / $page['perPage'])),
            'status'    => $status,
            'statuses'  => self::STATUSES,
        ]);
    }

    public function show(Request $request): Response
    {
        if ($refusal = $this->authorize('view_deals')) {
            return $refusal;
        }

        $quote = $this->findQuote($request->routeInt('id'));

        if ($quote === null) {
            return $this->notFound('That quote does not exist.');
        }

        return $this->view('quotes/show', [
            'pageTitle'  => 'Quote ' . $quote['number'],
            'calendarDate' => self::calendarDate(...),
            'quote'      => $quote,
            'lines'      => $this->lines((int) $quote['id']),
            'venue'      => $this->venue(),
            'sentBy'     => $this->sentBy((int) $quote['id']),
            'next'       => self::TRANSITIONS[(string) $quote['status']] ?? [],
            'sendable'   => in_array((string) $quote['status'], self::SENDABLE, true),
            'editable'   => in_array((string) $quote['status'], self::EDITABLE, true),
            'recipient'  => $this->recipient($quote),
        ]);
    }

    /**
     * The printable copy — chrome is removed by the stylesheet's @media print
     * block, so this is the same document on screen and on paper.
     */
    public function printable(Request $request): Response
    {
        if ($refusal = $this->authorize('view_deals')) {
            return $refusal;
        }

        $quote = $this->findQuote($request->routeInt('id'));

        if ($quote === null) {
            return $this->notFound('That quote does not exist.');
        }

        return $this->view('quotes/print', [
            'pageTitle' => 'Quote ' . $quote['number'],
            'calendarDate' => self::calendarDate(...),
            'quote'     => $quote,
            'lines'     => $this->lines((int) $quote['id']),
            'venue'     => $this->venue(),
            'recipient' => $this->recipient($quote),
        ]);
    }

    // -----------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------

    public function create(Request $request): Response
    {
        if ($refusal = $this->authorize('deals_manage')) {
            return $refusal;
        }

        return $this->view('quotes/form', [
            'pageTitle' => 'New quote',
            'quote'     => null,
            'lines'     => [],
            'deals'     => $this->selectableDeals(),
        ]);
    }

    public function store(Request $request): Response
    {
        if ($refusal = $this->authorize('deals_manage')) {
            return $refusal;
        }

        $data = $this->validate($request, [
            'deal_id'     => 'nullable|int|exists:deals',
            'valid_until' => 'nullable|date',
            'terms'       => 'nullable|string|max:5000',
            'notes'       => 'nullable|string|max:5000',
        ], ['deal_id' => 'Deal', 'valid_until' => 'Expiry date']);

        if ($data instanceof Response) {
            return $data;
        }

        $parsed = $this->readLines($request);

        if (is_string($parsed)) {
            return $this->refuse($request, $parsed, '/quotes/new');
        }

        $money = $this->readMoney($request, $parsed);

        if (is_string($money)) {
            return $this->refuse($request, $money, '/quotes/new');
        }

        $dealId = $this->resolveDeal($data);

        if ($dealId instanceof Response) {
            return $dealId;
        }

        $id = $this->insertQuote($dealId, $data, $parsed, $money);

        if ($id === null) {
            return $this->refuse(
                $request,
                'Could not allocate a quote number — several quotes were created at once. Try again.',
                '/quotes/new'
            );
        }

        return $this->success($request, 'Quote created.', '/quotes/' . $id);
    }

    public function edit(Request $request): Response
    {
        if ($refusal = $this->authorize('deals_manage')) {
            return $refusal;
        }

        $quote = $this->findQuote($request->routeInt('id'), 'deals_manage');

        if ($quote === null) {
            return $this->notFound('That quote does not exist.');
        }

        // Don't offer a form whose submission will be refused.
        if (!in_array((string) $quote['status'], self::EDITABLE, true)) {
            return $this->refuse($request, $this->frozenMessage($quote), '/quotes/' . (int) $quote['id']);
        }

        return $this->view('quotes/form', [
            'pageTitle' => 'Edit quote ' . $quote['number'],
            'quote'     => $quote,
            'lines'     => $this->lines((int) $quote['id']),
            'deals'     => $this->selectableDeals(),
        ]);
    }

    public function update(Request $request): Response
    {
        if ($refusal = $this->authorize('deals_manage')) {
            return $refusal;
        }

        $id    = $request->routeInt('id');
        $quote = $this->findQuote($id, 'deals_manage');

        if ($quote === null) {
            return $this->notFound('That quote does not exist.');
        }

        // Before anything is read off the request: the guest is holding a copy
        // of anything past draft.
        if (!in_array((string) $quote['status'], self::EDITABLE, true)) {
            return $this->refuse($request, $this->frozenMessage($quote), '/quotes/' . $id);
        }

        $data = $this->validate($request, [
            'deal_id'     => 'nullable|int|exists:deals',
            'valid_until' => 'nullable|date',
            'terms'       => 'nullable|string|max:5000',
            'notes'       => 'nullable|string|max:5000',
        ], ['deal_id' => 'Deal', 'valid_until' => 'Expiry date']);

        if ($data instanceof Response) {
            return $data;
        }

        $parsed = $this->readLines($request);

        if (is_string($parsed)) {
            return $this->refuse($request, $parsed, '/quotes/' . $id . '/edit');
        }

        $money = $this->readMoney($request, $parsed);

        if (is_string($money)) {
            return $this->refuse($request, $money, '/quotes/' . $id . '/edit');
        }

        $dealId = $this->resolveDeal($data);

        if ($dealId instanceof Response) {
            return $dealId;
        }

        $after = [
            'deal_id'        => $dealId,
            'subtotal_cents' => $money['subtotal_cents'],
            'discount_cents' => $money['discount_cents'],
            'tax_cents'      => $money['tax_cents'],
            'total_cents'    => $money['total_cents'],
            'deposit_cents'  => $money['deposit_cents'],
            'valid_until'    => $data['valid_until'] ?? null,
            'terms'          => $data['terms'] ?? null,
            'notes'          => $data['notes'] ?? null,
            'updated_at'     => now_utc(),
        ];

        DB::transaction(static function () use ($id, $after, $quote, $parsed): void {
            DB::update('quotes', $after, 'id = :id', ['id' => $id]);

            // Replace the lines wholesale. Diffing them against what the form
            // posted would be a second source of truth for the same figures,
            // and the totals above are computed from the posted set.
            DB::delete('quote_lines', 'quote_id = :qid', ['qid' => $id]);

            foreach ($parsed as $index => $line) {
                DB::insert('quote_lines', [
                    'quote_id'    => $id,
                    'description' => $line['description'],
                    'qty'         => $line['qty'],
                    'unit_cents'  => $line['unit_cents'],
                    'total_cents' => $line['total_cents'],
                    'sort_order'  => $index,
                ]);
            }

            Audit::record('quote.updated', 'quotes', $id, $quote, $after);
        });

        return $this->success($request, 'Quote ' . $quote['number'] . ' updated.', '/quotes/' . $id);
    }

    /**
     * Move a quote along its lifecycle.
     *
     * Deliberately cannot reach `sent`: that is send()'s job, behind
     * `quotes_send`.
     */
    public function status(Request $request): Response
    {
        if ($refusal = $this->authorize('deals_manage')) {
            return $refusal;
        }

        $id    = $request->routeInt('id');
        $quote = $this->findQuote($id, 'deals_manage');

        if ($quote === null) {
            return $this->notFound('That quote does not exist.');
        }

        $from = (string) $quote['status'];
        $to   = $request->str('status');

        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return $this->refuse(
                $request,
                'A quote that is ' . $from . ' cannot become ' . ($to === '' ? 'that' : $to) . '.',
                '/quotes/' . $id
            );
        }

        $after = ['status' => $to, 'updated_at' => now_utc()];

        // Each stamp is set once, when it first happens, so a re-send or a
        // second open does not rewrite the history of the first.
        if ($to === 'viewed' && $quote['viewed_at'] === null) {
            $after['viewed_at'] = now_utc();
        }

        if ($to === 'accepted' && $quote['accepted_at'] === null) {
            $after['accepted_at'] = now_utc();
        }

        DB::update('quotes', $after, 'id = :id', ['id' => $id]);

        Audit::record('quote.' . $to, 'quotes', $id, ['status' => $from], $after);

        if ($to === 'viewed' || $to === 'accepted') {
            Event::fire('quote.' . $to, [
                'quote_id' => $id,
                'deal_id'  => $quote['deal_id'] === null ? null : (int) $quote['deal_id'],
            ]);
        }

        return $this->success($request, 'Quote ' . $quote['number'] . ' is now ' . $to . '.', '/quotes/' . $id);
    }

    /**
     * Send the quote to the guest.
     *
     * Mail goes through Mailer::send() and nowhere else, so Safe Mode,
     * suppression, the do-not-contact flag and the sending kill switch all
     * apply. The body is rendered inline rather than through a seeded template:
     * the shipped party-quote template expects a tokenised public link this
     * module does not yet publish, and inventing one here would send guests a
     * URL that answers nothing.
     */
    public function send(Request $request): Response
    {
        if ($refusal = $this->authorize('quotes_send')) {
            return $refusal;
        }

        $id    = $request->routeInt('id');
        $quote = $this->findQuote($id, 'quotes_send');

        if ($quote === null) {
            return $this->notFound('That quote does not exist.');
        }

        if (!in_array((string) $quote['status'], self::SENDABLE, true)) {
            return $this->refuse(
                $request,
                'A quote that is ' . $quote['status'] . ' cannot be sent again. Put it back to draft first.',
                '/quotes/' . $id
            );
        }

        $lines = $this->lines($id);

        if ($lines === []) {
            return $this->refuse($request, 'Add at least one line before sending this quote.', '/quotes/' . $id);
        }

        $recipient = $this->recipient($quote);
        $to        = $request->str('to') !== '' ? $request->str('to') : (string) ($recipient['email'] ?? '');

        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return $this->refuse(
                $request,
                'There is no email address to send to. Add one to the guest, or type one on this page.',
                '/quotes/' . $id
            );
        }

        $result = Mailer::send([
            'to'         => $to,
            'to_name'    => (string) ($recipient['name'] ?? ''),
            'contact_id' => $recipient['contact_id'],
            'kind'       => Mailer::TRANSACTIONAL,
            'subject'    => 'Your quote from ' . Settings::venueName() . ' (' . $quote['number'] . ')',
            'html'       => $this->emailBody($quote, $lines),
        ]);

        if (!$result['sent']) {
            // Mailer's refusals are policy decisions — suppressed, no consent,
            // sending paused — so they belong in front of the operator rather
            // than in a log. The quote stays a draft.
            Audit::event('quote.send_failed', [
                'quote'  => (string) $quote['number'],
                'reason' => $result['reason'],
            ], 'quotes', $id);

            return $this->refuse($request, 'Not sent: ' . $result['reason'] . '.', '/quotes/' . $id);
        }

        $sentAt = now_utc();

        // sent_at is the FIRST send; a re-send does not rewrite it, so "how
        // long has the guest had this?" keeps its answer.
        $after = [
            'status'     => 'sent',
            'sent_at'    => $quote['sent_at'] ?? $sentAt,
            'updated_at' => $sentAt,
        ];

        DB::update('quotes', $after, 'id = :id', ['id' => $id]);

        // This row is the "by whom": Audit::record() stamps Auth::id(), and
        // sentBy() reads it back for the quote page. There is no sent_by
        // column on cc_quotes to write instead.
        Audit::record('quote.sent', 'quotes', $id, ['status' => $quote['status']], $after + [
            'to'         => $to,
            'message_id' => $result['message_id'],
            'redirected' => $result['redirected'],
        ]);

        Event::fire('quote.sent', [
            'quote_id' => $id,
            'deal_id'  => $quote['deal_id'] === null ? null : (int) $quote['deal_id'],
            'to'       => $to,
        ]);

        $message = $result['redirected']
            ? 'Safe Mode is on, so quote ' . $quote['number'] . ' went to the owner instead of the guest.'
            : 'Quote ' . $quote['number'] . ' sent to ' . $to . '.';

        return $this->success($request, $message, '/quotes/' . $id);
    }

    // -----------------------------------------------------------------
    // The arithmetic
    // -----------------------------------------------------------------

    /**
     * Every figure on a quote, derived from its lines.
     *
     * Pure, integer-only, and the single place any of these numbers is worked
     * out. Quantities arrive as integer HUNDREDTHS so that `3 × 999` is exactly
     * 2997 and `1.5 × 999` rounds once, predictably, rather than accumulating
     * binary error across a dozen lines.
     *
     * @param list<array{qty_hundredths:int,unit_cents:int}> $lines
     * @return array{
     *     subtotal_cents:int, discount_cents:int, tax_cents:int,
     *     total_cents:int, deposit_cents:int, line_totals:list<int>
     * }
     */
    public static function totals(
        array $lines,
        string $discountMode = 'amount',
        int $discountInput = 0,
        int $taxCents = 0,
        int $depositCents = 0
    ): array {
        $lineTotals = [];
        $subtotal   = 0;

        foreach ($lines as $line) {
            $total        = self::lineTotal($line['qty_hundredths'], $line['unit_cents']);
            $lineTotals[] = $total;
            $subtotal    += $total;
        }

        // 'percent' arrives as hundredths of a percent, so 100% is 10000 and
        // 33.33% is 3333 — no float anywhere in the path.
        $discount = $discountMode === 'percent'
            ? self::divideRound($subtotal * $discountInput, 10000)
            : $discountInput;

        // A discount can zero a quote but never invert it: a negative total
        // would read as the venue owing the guest money.
        $discount = max(0, min($subtotal, $discount));
        $tax      = max(0, $taxCents);
        $total    = $subtotal - $discount + $tax;
        $deposit  = max(0, min($total, $depositCents));

        return [
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'tax_cents'      => $tax,
            'total_cents'    => $total,
            'deposit_cents'  => $deposit,
            'line_totals'    => $lineTotals,
        ];
    }

    /** One line, in cents: qty (hundredths) × unit price, rounded once. */
    public static function lineTotal(int $qtyHundredths, int $unitCents): int
    {
        return self::divideRound($qtyHundredths * $unitCents, 100);
    }

    /**
     * Integer division rounding half away from zero.
     *
     * intdiv() truncates towards zero, which would systematically under-charge
     * on every fractional quantity. Doing it by hand keeps the whole money path
     * free of float: `round()` takes and returns one.
     */
    private static function divideRound(int $numerator, int $denominator): int
    {
        $half = intdiv($denominator, 2);

        return intdiv($numerator + ($numerator >= 0 ? $half : -$half), $denominator);
    }

    /**
     * Parse a decimal quantity into integer hundredths.
     *
     * Returns null for anything that is not a plain positive decimal with at
     * most two places — which is all `qty DECIMAL(10,2)` can hold, so a third
     * place would be silently rounded by MySQL and the stored line total would
     * then disagree with the stored quantity.
     */
    public static function parseQty(string $input): ?int
    {
        $input = trim($input);

        if (preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/', $input, $match) !== 1) {
            return null;
        }

        $hundredths = ((int) $match[1]) * 100 + (int) str_pad($match[2] ?? '0', 2, '0');

        return $hundredths > 0 ? $hundredths : null;
    }

    /**
     * Parse a money figure typed as dollars into integer cents.
     *
     * Accepts `1,299`, `$12.50`, `0`. Rejects a third decimal place rather
     * than rounding it away behind the operator's back.
     */
    public static function parseMoney(string $input): ?int
    {
        $input = str_replace([',', '$', ' '], '', trim($input));

        if ($input === '') {
            return 0;
        }

        if (preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', $input, $match) !== 1) {
            return null;
        }

        return ((int) $match[1]) * 100 + (int) str_pad($match[2] ?? '0', 2, '0');
    }

    /** A percentage typed as `12.5` into hundredths of a percent. */
    public static function parsePercent(string $input): ?int
    {
        $input = str_replace(['%', ' '], '', trim($input));

        if ($input === '') {
            return 0;
        }

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $input, $match) !== 1) {
            return null;
        }

        $hundredths = ((int) $match[1]) * 100 + (int) str_pad($match[2] ?? '0', 2, '0');

        return $hundredths <= 10000 ? $hundredths : null;
    }

    // -----------------------------------------------------------------
    // Request reading
    // -----------------------------------------------------------------

    /**
     * The posted line items, normalised, or a message explaining the refusal.
     *
     * @return list<array{description:string,qty:string,qty_hundredths:int,unit_cents:int,total_cents:int}>|string
     */
    private function readLines(Request $request): array|string
    {
        $posted = $request->post('lines');

        if (!is_array($posted)) {
            return 'A quote needs at least one line.';
        }

        $parsed = [];
        $row    = 0;

        foreach ($posted as $line) {
            $row++;

            if (!is_array($line)) {
                continue;
            }

            $description = trim((string) ($line['description'] ?? ''));
            $qtyInput    = (string) ($line['qty'] ?? '');
            $unitInput   = (string) ($line['unit'] ?? '');

            // A wholly blank row is the empty template row the form always
            // offers, not a mistake.
            if ($description === '' && trim($qtyInput) === '' && trim($unitInput) === '') {
                continue;
            }

            if ($description === '') {
                return 'Line ' . $row . ' needs a description.';
            }

            if (mb_strlen($description) > 255) {
                return 'Line ' . $row . '\'s description is too long (255 characters maximum).';
            }

            $qty = self::parseQty($qtyInput === '' ? '1' : $qtyInput);

            if ($qty === null || $qty > self::MAX_QTY_HUNDREDTHS) {
                return 'Line ' . $row . ' needs a quantity above zero, with at most two decimal places.';
            }

            $unit = self::parseMoney($unitInput);

            if ($unit === null || $unit > self::MAX_UNIT_CENTS) {
                return 'Line ' . $row . ' needs a unit price in dollars and cents, such as 24.99.';
            }

            $parsed[] = [
                'description'    => $description,
                // DECIMAL(10,2) — send MySQL the exact two-place string the
                // hundredths represent, not a float.
                'qty'            => $this->qtyString($qty),
                'qty_hundredths' => $qty,
                'unit_cents'     => $unit,
                'total_cents'    => self::lineTotal($qty, $unit),
            ];
        }

        if ($parsed === []) {
            return 'A quote needs at least one line.';
        }

        if (count($parsed) > self::MAX_LINES) {
            return 'A quote can carry at most ' . self::MAX_LINES . ' lines.';
        }

        return $parsed;
    }

    /**
     * The derived money block for a posted quote, or a refusal message.
     *
     * @param list<array{qty_hundredths:int,unit_cents:int}> $lines
     * @return array{subtotal_cents:int,discount_cents:int,tax_cents:int,total_cents:int,deposit_cents:int}|string
     */
    private function readMoney(Request $request, array $lines): array|string
    {
        $mode = $request->str('discount_mode', 'amount');

        if (!in_array($mode, ['amount', 'percent'], true)) {
            return 'Choose whether the discount is an amount or a percentage.';
        }

        $discountRaw = $request->str('discount');

        $discount = $mode === 'percent'
            ? self::parsePercent($discountRaw)
            : self::parseMoney($discountRaw);

        if ($discount === null) {
            return $mode === 'percent'
                ? 'The discount percentage must be between 0 and 100.'
                : 'The discount must be an amount in dollars and cents.';
        }

        $tax = self::parseMoney($request->str('tax'));

        if ($tax === null || $tax > self::MAX_UNIT_CENTS) {
            return 'Tax must be an amount in dollars and cents.';
        }

        $deposit = self::parseMoney($request->str('deposit'));

        if ($deposit === null || $deposit > self::MAX_UNIT_CENTS) {
            return 'The deposit must be an amount in dollars and cents.';
        }

        $totals = self::totals($lines, $mode, $discount, $tax, $deposit);

        unset($totals['line_totals']);

        return $totals;
    }

    /**
     * A deal id the current user may actually attach a quote to, or a refusal.
     *
     * `deals_manage` is scoped, so a Party Host quoting somebody else's deal is
     * refused here rather than at the exists: rule, which only proves the row
     * is there.
     *
     * @param array<string,mixed> $data
     */
    private function resolveDeal(array $data): int|null|Response
    {
        $dealId = isset($data['deal_id']) && $data['deal_id'] !== null ? (int) $data['deal_id'] : null;

        if ($dealId === null) {
            return null;
        }

        [$scope, $params] = Acl::scopeCondition('deals_manage', 'owner_id');

        $allowed = DB::exists(
            'SELECT 1 FROM {deals} WHERE id = :id AND deleted_at IS NULL AND (' . $scope . ')',
            $params + ['id' => $dealId]
        );

        if (!$allowed) {
            return Response::html(View::renderError(403, 'Not permitted', [
                'message' => 'That deal is not yours to quote on.',
            ]), 403);
        }

        return $dealId;
    }

    // -----------------------------------------------------------------
    // Persistence
    // -----------------------------------------------------------------

    /**
     * Insert the quote and its lines, allocating a number.
     *
     * `number` is UNIQUE, and two people clicking Create in the same second
     * would otherwise collide. The whole transaction is retried with a fresh
     * number on a duplicate-key error, which is correct whether the clash came
     * from another request or from a gap in the sequence.
     *
     * @param list<array{description:string,qty:string,unit_cents:int,total_cents:int}> $lines
     * @param array<string,mixed> $data
     * @param array<string,int>   $money
     */
    private function insertQuote(?int $dealId, array $data, array $lines, array $money): ?int
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $number = $this->nextNumber();

            try {
                return DB::transaction(static function () use ($number, $dealId, $data, $lines, $money): int {
                    $row = [
                        'deal_id'        => $dealId,
                        'number'         => $number,
                        'status'         => 'draft',
                        'subtotal_cents' => $money['subtotal_cents'],
                        'discount_cents' => $money['discount_cents'],
                        'tax_cents'      => $money['tax_cents'],
                        'total_cents'    => $money['total_cents'],
                        'deposit_cents'  => $money['deposit_cents'],
                        'valid_until'    => $data['valid_until'] ?? null,
                        'terms'          => $data['terms'] ?? null,
                        'notes'          => $data['notes'] ?? null,
                        'created_by'     => Auth::id(),
                        'created_at'     => now_utc(),
                        'updated_at'     => now_utc(),
                    ];

                    $id = DB::insert('quotes', $row);

                    foreach ($lines as $index => $line) {
                        DB::insert('quote_lines', [
                            'quote_id'    => $id,
                            'description' => $line['description'],
                            'qty'         => $line['qty'],
                            'unit_cents'  => $line['unit_cents'],
                            'total_cents' => $line['total_cents'],
                            'sort_order'  => $index,
                        ]);
                    }

                    Audit::record('quote.created', 'quotes', $id, null, $row);

                    return $id;
                });
            } catch (PDOException $e) {
                // 23000 is the integrity-constraint class; anything else is a
                // real fault and should surface as a 500 rather than be retried.
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        return null;
    }

    /**
     * The next quote number for this year.
     *
     * Zero-padded, so a lexicographic MAX() is also the numeric one and the
     * sequence can be read off the column without a counter table.
     */
    private function nextNumber(): string
    {
        $prefix = 'Q-' . gmdate('Y') . '-';

        $last = DB::value(
            'SELECT MAX(number) FROM {quotes} WHERE number LIKE :like',
            ['like' => $prefix . '%']
        );

        $sequence = is_string($last) && $last !== ''
            ? (int) substr($last, strlen($prefix))
            : 0;

        return $prefix . str_pad((string) ($sequence + 1), 4, '0', STR_PAD_LEFT);
    }

    // -----------------------------------------------------------------
    // Reads shared by the views
    // -----------------------------------------------------------------

    /**
     * A quote the current user may reach FOR THE ACTION ABOUT TO BE TAKEN.
     *
     * The permission is a parameter, not a constant, because the scopes come
     * apart: a role may hold `view_deals` at `all` and `deals_manage` at `own`
     * — a manager who sees the whole team's quotes but edits only their own.
     * Scoping every lookup by `view_deals` would let that role edit, re-status
     * or send somebody else's quote, since the route gate only proves they
     * hold the permission SOMEWHERE and the scope decides on which rows.
     *
     * So each caller passes the permission whose scope should decide: the two
     * read paths `view_deals`, the three edit paths `deals_manage`, and send()
     * `quotes_send`.
     *
     * @return array<string,mixed>|null
     */
    private function findQuote(int $id, string $permission = 'view_deals'): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $quote = DB::first(
            'SELECT q.*, d.title AS deal_title, d.owner_id AS deal_owner_id,
                    d.event_date, d.guest_count, d.contact_id,
                    c.first_name, c.last_name, c.email AS contact_email,
                    c.address_1 AS contact_address_1, c.address_2 AS contact_address_2,
                    c.city AS contact_city, c.state AS contact_state,
                    c.postal_code AS contact_postal_code,
                    u.name AS created_by_name
               FROM {quotes} q
               LEFT JOIN {deals} d ON d.id = q.deal_id
               LEFT JOIN {contacts} c ON c.id = d.contact_id
               LEFT JOIN {users} u ON u.id = q.created_by
              WHERE q.id = :id',
            ['id' => $id]
        );

        if ($quote === null) {
            return null;
        }

        // A deal-attached quote is only visible to whoever may see that deal.
        // Reported as "does not exist": whether a record exists is itself
        // information somebody outside its scope should not get.
        if ($quote['deal_id'] !== null) {
            $scope = Acl::scope($permission);

            if ($scope === null) {
                return null;
            }

            if ($scope !== 'all') {
                [$condition, $params] = Acl::scopeCondition($permission, 'owner_id');

                if (!DB::exists(
                    'SELECT 1 FROM {deals} WHERE id = :id AND (' . $condition . ')',
                    $params + ['id' => (int) $quote['deal_id']]
                )) {
                    return null;
                }
            }
        }

        return $quote;
    }

    /** @return list<array<string,mixed>> */
    private function lines(int $quoteId): array
    {
        return DB::all(
            'SELECT id, description, qty, unit_cents, total_cents, sort_order
               FROM {quote_lines} WHERE quote_id = :qid ORDER BY sort_order, id',
            ['qid' => $quoteId]
        );
    }

    /**
     * The venue's own details, for the top of a document a guest will read.
     *
     * @return array<string,string>
     */
    private function venue(): array
    {
        $venue = Settings::group('venue');

        $city = trim(implode(' ', array_filter([
            trim((string) ($venue['city'] ?? '')) . (isset($venue['state']) && $venue['state'] !== '' ? ',' : ''),
            (string) ($venue['state'] ?? ''),
            (string) ($venue['postal_code'] ?? ''),
        ], static fn (string $part): bool => trim($part) !== '')));

        return [
            'name'    => Settings::venueName(),
            'line1'   => trim((string) ($venue['address_1'] ?? '')),
            'line2'   => trim((string) ($venue['address_2'] ?? '')),
            'city'    => $city,
            'phone'   => trim((string) ($venue['phone_display'] ?? $venue['phone'] ?? '')),
            'email'   => trim((string) ($venue['email'] ?? '')),
            'website' => trim((string) ($venue['website'] ?? '')),
        ];
    }

    /**
     * Who the quote is addressed to.
     *
     * `cc_quotes` has no contact column, so the addressee comes from the deal.
     * A quote with no deal has no stored guest at all — it is printable and can
     * be mailed to an address typed on the page, and says so.
     *
     * @param array<string,mixed> $quote
     * @return array{name:string,email:string,contact_id:int|null,address:list<string>}
     */
    private function recipient(array $quote): array
    {
        $name = trim(((string) ($quote['first_name'] ?? '')) . ' ' . ((string) ($quote['last_name'] ?? '')));

        $cityLine = trim(implode(' ', array_filter([
            trim((string) ($quote['contact_city'] ?? ''))
                . (($quote['contact_state'] ?? '') !== '' ? ',' : ''),
            (string) ($quote['contact_state'] ?? ''),
            (string) ($quote['contact_postal_code'] ?? ''),
        ], static fn (string $part): bool => trim($part) !== '')));

        $address = array_values(array_filter([
            trim((string) ($quote['contact_address_1'] ?? '')),
            trim((string) ($quote['contact_address_2'] ?? '')),
            $cityLine,
        ], static fn (string $part): bool => $part !== ''));

        return [
            'name'       => $name,
            'email'      => trim((string) ($quote['contact_email'] ?? '')),
            'contact_id' => $quote['contact_id'] === null ? null : (int) $quote['contact_id'],
            'address'    => $address,
        ];
    }

    /**
     * The most recent send of this quote, read out of the audit log.
     *
     * @return array{at:string,by:string}|null
     */
    private function sentBy(int $quoteId): ?array
    {
        $row = DB::first(
            "SELECT a.created_at, u.name
               FROM {audit_log} a
               LEFT JOIN {users} u ON u.id = a.user_id
              WHERE a.entity_type = 'quotes' AND a.entity_id = :id AND a.action = 'quote.sent'
              ORDER BY a.id DESC LIMIT 1",
            ['id' => $quoteId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'at' => (string) $row['created_at'],
            'by' => (string) ($row['name'] ?? 'a deleted account'),
        ];
    }

    /**
     * Deals this user may attach a quote to.
     *
     * @return list<array<string,mixed>>
     */
    private function selectableDeals(): array
    {
        [$scope, $params] = Acl::scopeCondition('deals_manage', 'd.owner_id');

        return DB::all(
            'SELECT d.id, d.title, d.event_date, c.first_name, c.last_name
               FROM {deals} d
               LEFT JOIN {contacts} c ON c.id = d.contact_id
              WHERE d.deleted_at IS NULL AND (' . $scope . ')
              ORDER BY d.id DESC
              LIMIT 200',
            $params
        );
    }

    /** @param array<string,mixed> $quote */
    private function frozenMessage(array $quote): string
    {
        return 'Quote ' . $quote['number'] . ' has been ' . $quote['status']
            . ', so the guest is holding a copy of these figures. Put it back to draft to re-price it.';
    }

    /**
     * The quote, rendered inline for email.
     *
     * Rendered through the view layer so there is one description of a quote
     * rather than two that can drift, and escaped by the same e() the pages
     * use. Mailer derives the plain-text part itself.
     *
     * @param array<string,mixed>        $quote
     * @param list<array<string,mixed>>  $lines
     */
    private function emailBody(array $quote, array $lines): string
    {
        return View::renderPartial('quotes/email', [
            'calendarDate' => self::calendarDate(...),
            'quote'     => $quote,
            'lines'     => $lines,
            'venue'     => $this->venue(),
            'recipient' => $this->recipient($quote),
        ]);
    }

    /** Integer hundredths back to the exact two-place decimal MySQL stores. */
    private function qtyString(int $hundredths): string
    {
        return intdiv($hundredths, 100) . '.' . str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Format a DATE column for a reader.
     *
     * NOT local_date(), which is for timestamps: it reads its argument as a UTC
     * instant and converts it into the venue's timezone. `valid_until` and
     * `event_date` are DATE columns — a calendar day with no time and no zone —
     * so that conversion moves them. MEASURED: valid_until 2030-06-01 through
     * local_date() on an America/New_York install renders "May 31, 2030",
     * because midnight UTC is 8pm the previous evening in New York. A quote
     * telling a family it expired the day before it does is exactly the kind of
     * figure this module must not get wrong.
     *
     * Passed into the views as a callable so there is one implementation of it
     * rather than one per template.
     */
    public static function calendarDate(?string $date): string
    {
        if ($date === null || $date === '' || str_starts_with($date, '0000')) {
            return '—';
        }

        try {
            // Date part only: a DATETIME handed in here is still a day.
            return (new DateTimeImmutable(substr($date, 0, 10)))->format('M j, Y');
        } catch (Exception) {
            return '—';
        }
    }
}
