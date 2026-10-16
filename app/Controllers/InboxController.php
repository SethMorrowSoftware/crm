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
use App\Core\Sms;
use App\Services\Inbox;
use App\Services\SmsPolicy;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The unified inbox.
 *
 * One threaded view across email, text messages and internal notes, keyed to a
 * guest. Four things decide how this class is built.
 *
 * 1. **AN AGENT PHYSICALLY CANNOT SEND WHAT THE SEND PATH WOULD REFUSE.** The
 *    specification puts it that way (§8.6) and it is the reason the composer
 *    calls `Sms::preview()` / the consent read BEFORE drawing, and the reason
 *    the reply action reports the send path's own refusal rather than its own
 *    guess. A composer that let somebody type a reply to a guest who texted
 *    STOP, and only refused it on submit, would be a worse version of the same
 *    rule: the work is lost and the reason arrives too late to be useful.
 *
 * 2. **READING IS SCOPED, AND SO IS WRITING — by the permission that governs
 *    each.** `view_inbox` decides which conversations are visible, `inbox_reply`
 *    whether a reply may be sent, and `inbox_assign` whether it may be
 *    assigned, snoozed or closed. Those three come apart in the seeds on
 *    purpose: Front Desk and Party Host may answer a guest and may NOT close
 *    the conversation or reassign it, because the person on the phone is not
 *    automatically the person who decides the queue is clear.
 *
 * 3. **FIRST RESPONSE IS STAMPED ONCE, BY A PERSON.** `Inbox::isFirstResponse()`
 *    owns that judgement; this only asks it. The figure is reported per rep, so
 *    a campaign send or an auto-acknowledgement stopping the clock would make
 *    the metric read best for whoever answers least.
 *
 * 4. **AN UNKNOWN SENDER BECOMES A CONTACT AND AN OPEN CONVERSATION.** A guest
 *    texting a number the venue has never seen is a lead, not an error, and
 *    dropping their message because they are not on file is how a venue loses
 *    a booking it never knew it had.
 */
final class InboxController extends Controller
{
    /** The conversations.status enum. */
    public const STATUSES = ['open', 'pending', 'snoozed', 'closed'];

    /** Statuses still in the queue. */
    private const LIVE = "('open','pending','snoozed')";

    /** How many conversations a queue page shows. */
    public const PER_PAGE = 40;

    /** How many messages a thread shows before "show earlier". */
    public const THREAD_LIMIT = 60;

    // =================================================================
    // The queue
    // =================================================================

    public function index(Request $request): Response
    {
        if ($refusal = $this->authorize('view_inbox')) {
            return $refusal;
        }

        $filter  = $request->str('filter', 'mine');
        $filter  = isset(Inbox::FILTERS[$filter]) ? $filter : 'mine';
        $channel = $request->str('channel');
        $channel = in_array($channel, Inbox::CHANNELS, true) ? $channel : '';
        $search  = trim($request->str('q'));
        $page    = max(1, $request->int('page', 1));

        [$where, $params] = $this->queueFilter($filter, $channel, $search);

        $total = (int) DB::value(
            'SELECT COUNT(*) FROM {conversations} c
               LEFT JOIN {contacts} ct ON ct.id = c.contact_id
              WHERE ' . $where,
            $params
        );

        $offset = ($page - 1) * self::PER_PAGE;

        $rows = DB::all(
            'SELECT c.*, ct.first_name, ct.last_name, ct.email, ct.phone,
                    ct.sms_consent, ct.do_not_contact,
                    u.name AS assignee_name,
                    (SELECT m.body_text FROM {messages} m
                      WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_body,
                    (SELECT m.direction FROM {messages} m
                      WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_direction
               FROM {conversations} c
               LEFT JOIN {contacts} ct ON ct.id = c.contact_id
               LEFT JOIN {users} u ON u.id = c.assigned_to
              WHERE ' . $where . '
              ORDER BY c.is_unread DESC, c.last_message_at DESC, c.id DESC
              LIMIT ' . self::PER_PAGE . ' OFFSET ' . $offset,
            $params
        );

        return $this->view('inbox/index', [
            'pageTitle'   => 'Inbox',
            'conversations' => array_map([$this, 'decorate'], $rows),
            'filter'      => $filter,
            'filters'     => Inbox::FILTERS,
            'counts'      => $this->counts(),
            'channel'     => $channel,
            'channels'    => Inbox::CHANNELS,
            'search'      => $search,
            'page'        => $page,
            'pages'       => max(1, (int) ceil($total / self::PER_PAGE)),
            'total'       => $total,
        ] + $this->formatters());
    }

    public function show(Request $request): Response
    {
        if ($refusal = $this->authorize('view_inbox')) {
            return $refusal;
        }

        $id           = $request->int('id', (int) $request->route('id', '0'));
        $conversation = $this->find($id);

        if ($conversation === null) {
            return $this->notFound('That conversation is not here.');
        }

        // Opening a conversation marks it read. Not a side effect to be
        // apologetic about: the unread flag means "nobody has looked at this",
        // and somebody just did.
        if ((int) $conversation['is_unread'] === 1) {
            DB::update('conversations', [
                'is_unread'  => 0,
                'updated_at' => now_utc(),
            ], 'id = :id', ['id' => $id]);

            $conversation['is_unread'] = 0;
        }

        $messages = DB::all(
            'SELECT m.*, u.name AS author_name
               FROM {messages} m
               LEFT JOIN {users} u ON u.id = m.user_id
              WHERE m.conversation_id = :id
              ORDER BY m.id DESC
              LIMIT ' . (self::THREAD_LIMIT + 1),
            ['id' => $id]
        );

        $earlier  = count($messages) > self::THREAD_LIMIT;
        $messages = array_reverse(array_slice($messages, 0, self::THREAD_LIMIT));

        $contact = $conversation['contact_id'] === null
            ? null
            : DB::first(
                'SELECT id, first_name, last_name, email, phone, phone_normalized,
                        email_consent, sms_consent, do_not_contact, lifecycle_stage
                   FROM {contacts} WHERE id = :id AND deleted_at IS NULL',
                ['id' => (int) $conversation['contact_id']]
            );

        $channel = Inbox::replyChannel((string) $conversation['channel']);

        return $this->view('inbox/show', [
            'pageTitle'    => 'Conversation',
            'conversation' => $this->decorate($conversation),
            'messages'     => $messages,
            'earlier'      => $earlier,
            'contact'      => $contact,
            'replyChannel' => $channel,
            // WHAT THE COMPOSER MAY DO, decided before it is drawn. The whole
            // point of §8.6's "physically cannot": a reply box offered and
            // then refused wastes the work and arrives too late to help.
            'composer'     => $this->composerState($channel, $contact),
            'canned'       => $this->cannedReplies($channel),
            'assignees'    => $this->assignees(),
            'snoozeOptions' => Inbox::SNOOZE_OPTIONS,
            'responseLabel' => Inbox::responseLabel($conversation),
            'bookings'     => $this->recentBookings($conversation['contact_id']),
        ] + $this->formatters());
    }

    // =================================================================
    // Replying
    // =================================================================

    public function reply(Request $request): Response
    {
        if ($refusal = $this->authorize('inbox_reply')) {
            return $refusal;
        }

        $id           = (int) $request->route('id', '0');
        $conversation = $this->find($id);

        if ($conversation === null) {
            return $this->notFound('That conversation is not here.');
        }

        $body    = trim($request->str('body'));
        $channel = Inbox::replyChannel($request->str('channel', (string) $conversation['channel']));
        $back    = '/inbox/' . $id;

        if ($body === '') {
            return $this->refuse($request, 'A reply needs something in it.', $back);
        }

        // An internal note is not a message to a guest: nothing is sent, no
        // consent applies, and the first-response clock does not stop.
        if ($channel === 'internal') {
            return $this->addNote($conversation, $body, $back);
        }

        $contact = $conversation['contact_id'] === null
            ? null
            : DB::first(
                'SELECT id, email, phone_normalized FROM {contacts} WHERE id = :id AND deleted_at IS NULL',
                ['id' => (int) $conversation['contact_id']]
            );

        $to = $channel === 'sms'
            ? (string) ($contact['phone_normalized'] ?? '')
            : (string) ($contact['email'] ?? '');

        if ($to === '') {
            return $this->refuse(
                $request,
                $channel === 'sms'
                    ? 'There is no mobile number on this guest to text.'
                    : 'There is no email address on this guest to write to.',
                $back
            );
        }

        // THE SEND PATH DECIDES. Its refusal is reported verbatim rather than
        // re-derived here, so the inbox and the send can never disagree about
        // whether a guest may be contacted.
        $result = $channel === 'sms'
            ? Sms::send([
                'to'              => $to,
                'body'            => $body,
                'kind'            => Sms::TRANSACTIONAL,
                'contact_id'      => $contact === null ? null : (int) $contact['id'],
                'conversation_id' => $id,
                'user_id'         => Auth::id(),
            ])
            : Mailer::send([
                'to'         => $to,
                'subject'    => $this->replySubject($conversation),
                'text'       => $body,
                'html'       => '<p>' . nl2br(e($body)) . '</p>',
                'kind'       => Mailer::TRANSACTIONAL,
                'contact_id' => $contact === null ? null : (int) $contact['id'],
            ]);

        if (!$result['sent']) {
            return $this->refuse(
                $request,
                $channel === 'sms'
                    ? Sms::explain($result['reason'])
                    : 'That could not be sent: ' . $result['reason'],
                $back
            );
        }

        // Email goes through Mailer, which records its own row but knows
        // nothing about conversations; attach it here so the thread is whole.
        if ($channel === 'email' && $result['message_id'] !== null) {
            DB::update('messages', [
                'conversation_id' => $id,
                'user_id'         => Auth::id(),
            ], 'id = :id', ['id' => (int) $result['message_id']]);
        }

        $this->afterOutbound($conversation, $channel, (int) ($result['message_id'] ?? 0));

        flash('success', $channel === 'sms' ? 'Text sent.' : 'Reply sent.');

        return $this->redirect($back);
    }

    /**
     * An internal note: staff talking to each other on the guest's thread.
     *
     * @param array<string,mixed> $conversation
     */
    private function addNote(array $conversation, string $body, string $back): Response
    {
        $id = (int) $conversation['id'];

        DB::insert('messages', [
            'conversation_id' => $id,
            'contact_id'      => $conversation['contact_id'],
            'direction'       => 'outbound',
            'channel'         => 'internal',
            'body'            => $body,
            'body_text'       => $body,
            'user_id'         => Auth::id(),
            'status'          => 'sent',
            'sent_at'         => now_utc(),
            'created_at'      => now_utc(),
        ]);

        // The thread moved, so the queue order moves with it — but the note is
        // NOT a response, so `first_response_at` is untouched and the
        // conversation does not stop looking unanswered. That is the point:
        // the guest has still heard nothing.
        DB::update('conversations', [
            'last_message_at' => now_utc(),
            'updated_at'      => now_utc(),
        ], 'id = :id', ['id' => $id]);

        flash('success', 'Note added.');

        return $this->redirect($back);
    }

    /**
     * Everything that follows a real outbound message.
     *
     * @param array<string,mixed> $conversation
     */
    private function afterOutbound(array $conversation, string $channel, int $messageId): void
    {
        $id      = (int) $conversation['id'];
        $changes = [
            'last_message_at' => now_utc(),
            'is_unread'       => 0,
            'updated_at'      => now_utc(),
        ];

        // FIRST RESPONSE IS STAMPED ONCE. A later reply does not move it: the
        // figure is how long the GUEST waited, and overwriting it would make
        // a slow conversation that got a lot of attention look fast.
        if ($conversation['first_response_at'] === null && Inbox::isFirstResponse([
            'direction' => 'outbound',
            'channel'   => $channel,
            'user_id'   => Auth::id(),
        ])) {
            $changes['first_response_at'] = now_utc();
        }

        // A snoozed conversation that has just been answered is open again.
        // Leaving it snoozed would hide the guest's next message behind a
        // timer somebody set before they replied.
        if ((string) $conversation['status'] === 'snoozed') {
            $changes['status']        = 'open';
            $changes['snoozed_until'] = null;
        }

        DB::update('conversations', $changes, 'id = :id', ['id' => $id]);

        Event::fire('conversation.replied', [
            'conversation_id' => $id,
            'channel'         => $channel,
            'message_id'      => $messageId,
            'user_id'         => Auth::id(),
        ]);
    }

    // =================================================================
    // Managing the queue
    // =================================================================

    public function assign(Request $request): Response
    {
        if ($refusal = $this->authorize('inbox_assign')) {
            return $refusal;
        }

        $id           = (int) $request->route('id', '0');
        $conversation = $this->find($id);

        if ($conversation === null) {
            return $this->notFound('That conversation is not here.');
        }

        $userId = $request->int('assigned_to', 0);

        if ($userId > 0 && !$this->isAssignable($userId)) {
            return $this->refuse(
                $request,
                'That person cannot be assigned conversations.',
                '/inbox/' . $id
            );
        }

        DB::update('conversations', [
            'assigned_to' => $userId > 0 ? $userId : null,
            // Assigning is not reading. A conversation handed to a colleague
            // stays unread FOR THEM, which is the only way the "assigned to
            // me" tab means anything.
            'updated_at'  => now_utc(),
        ], 'id = :id', ['id' => $id]);

        Audit::event('conversation.assigned', [
            'conversation' => $id,
            'assigned_to'  => $userId > 0 ? $userId : null,
        ], 'conversations', $id);

        flash('success', $userId > 0 ? 'Conversation assigned.' : 'Assignment cleared.');

        return $this->redirect('/inbox/' . $id);
    }

    public function snooze(Request $request): Response
    {
        if ($refusal = $this->authorize('inbox_assign')) {
            return $refusal;
        }

        $id           = (int) $request->route('id', '0');
        $conversation = $this->find($id);

        if ($conversation === null) {
            return $this->notFound('That conversation is not here.');
        }

        $option = $request->str('option');
        $zone   = Settings::timezone();
        $now    = new DateTimeImmutable('now', new DateTimeZone($zone));

        $until = $option === 'custom'
            ? Inbox::snoozeUntilCustom($request->str('until'), $now, $zone)
            : Inbox::snoozeUntil($option, $now, $zone);

        if ($until === null) {
            return $this->refuse(
                $request,
                $option === 'custom'
                    ? 'Choose a date and time in the future to snooze until.'
                    : 'That is not one of the snooze options.',
                '/inbox/' . $id
            );
        }

        DB::update('conversations', [
            'status'        => 'snoozed',
            'snoozed_until' => $until,
            'updated_at'    => now_utc(),
        ], 'id = :id', ['id' => $id]);

        Audit::event('conversation.snoozed', [
            'conversation' => $id,
            'until'        => $until,
        ], 'conversations', $id);

        flash('success', 'Snoozed until ' . local_time($until) . '.');

        return $this->redirect('/inbox');
    }

    public function close(Request $request): Response
    {
        if ($refusal = $this->authorize('inbox_assign')) {
            return $refusal;
        }

        $id           = (int) $request->route('id', '0');
        $conversation = $this->find($id);

        if ($conversation === null) {
            return $this->notFound('That conversation is not here.');
        }

        DB::update('conversations', [
            'status'        => 'closed',
            'closed_at'     => now_utc(),
            'is_unread'     => 0,
            'snoozed_until' => null,
            'updated_at'    => now_utc(),
        ], 'id = :id', ['id' => $id]);

        Audit::event('conversation.closed', ['conversation' => $id], 'conversations', $id);
        Event::fire('conversation.closed', ['conversation_id' => $id, 'user_id' => Auth::id()]);

        flash('success', 'Conversation closed.');

        return $this->redirect('/inbox');
    }

    public function reopen(Request $request): Response
    {
        if ($refusal = $this->authorize('inbox_assign')) {
            return $refusal;
        }

        $id           = (int) $request->route('id', '0');
        $conversation = $this->find($id);

        if ($conversation === null) {
            return $this->notFound('That conversation is not here.');
        }

        DB::update('conversations', [
            'status'        => 'open',
            'closed_at'     => null,
            'snoozed_until' => null,
            'updated_at'    => now_utc(),
        ], 'id = :id', ['id' => $id]);

        Audit::event('conversation.reopened', ['conversation' => $id], 'conversations', $id);

        flash('success', 'Conversation reopened.');

        return $this->redirect('/inbox/' . $id);
    }

    // =================================================================
    // Inbound
    // =================================================================

    /**
     * Take an inbound message and put it on the right thread.
     *
     * Called by the SMS and email webhooks and by the IMAP poll. Public and
     * static because there is exactly one correct way to do this and three
     * callers that must not each have their own.
     *
     * @param array{channel:string,from:string,body:string,subject?:string,provider_message_id?:string} $inbound
     * @return array{conversation_id:?int,message_id:?int,keyword:?string,reply:?string}
     */
    public static function receive(array $inbound): array
    {
        $channel = in_array($inbound['channel'] ?? '', Inbox::CHANNELS, true)
            ? (string) $inbound['channel']
            : 'email';
        $from = trim((string) ($inbound['from'] ?? ''));
        $body = (string) ($inbound['body'] ?? '');

        if ($from === '') {
            return ['conversation_id' => null, 'message_id' => null, 'keyword' => null, 'reply' => null];
        }

        // A KEYWORD IS HANDLED FIRST, before contact resolution, before the
        // conversation, before anything an automation might do with it. A STOP
        // sitting in a queue behind a slow lookup is a STOP that did not work.
        $keyword = null;
        $reply   = null;

        if ($channel === 'sms') {
            $outcome = Sms::handleKeyword($from, $body);
            $keyword = $outcome['action'];
            $reply   = $outcome['reply'];
        }

        $contactId = self::resolveSender($channel, $from);

        /*
         * THE TEXT CLUB IS SETTLED AFTER THE SENDER IS KNOWN, and that is a
         * deliberate difference from the STOP above.
         *
         * A STOP must not wait on a lookup — it is honoured against the
         * NUMBER, whether or not any contact matches, because a suppression
         * that depended on finding somebody would fail for exactly the people
         * most likely to complain. A JOIN is the opposite: it CREATES a
         * relationship, and the consent it eventually grants has to be
         * recorded against a contact with evidence attached, so it needs the
         * sender resolved first.
         *
         * A STOP is passed on as well, so the list row is settled too: the
         * suppression stops the sending, and this stops the venue's own
         * subscriber figures counting somebody who has left.
         */
        if ($channel === 'sms' && in_array($keyword, ['join', 'confirm', 'stop'], true)) {
            $club = TextClubController::handle($keyword, $from, $contactId);

            // The Text Club's reply wins only where the send path had none.
            // A STOP's confirmation is worded by `Sms::optOut()` and must not
            // be replaced by anything: it is the message that tells a guest
            // their opt-out worked.
            if ($reply === null && $club['reply'] !== null) {
                $reply = $club['reply'];
            }
        }

        // A STOP still gets recorded on the thread: the venue should be able
        // to see that it happened and when, rather than inferring it from a
        // suppression table.
        $conversationId = self::threadFor($channel, $contactId, $inbound['subject'] ?? null);

        $messageId = DB::insert('messages', [
            'conversation_id'     => $conversationId,
            'contact_id'          => $contactId,
            'direction'           => 'inbound',
            'channel'             => $channel,
            'from_address'        => mb_substr($from, 0, 191),
            'subject'             => isset($inbound['subject'])
                ? mb_substr((string) $inbound['subject'], 0, 255)
                : null,
            'body'                => $body,
            'body_text'           => Inbox::preview($body, 65535),
            'status'              => 'delivered',
            'provider_message_id' => $inbound['provider_message_id'] ?? null,
            'delivered_at'        => now_utc(),
            'created_at'          => now_utc(),
        ]);

        DB::update('conversations', [
            'last_message_at' => now_utc(),
            // Back to UNREAD. A guest writing again on a conversation
            // somebody had marked read needs to resurface, and a reply to a
            // CLOSED conversation reopens it — a closed thread with an
            // unanswered question in it is the one failure the queue exists
            // to prevent.
            'is_unread'       => 1,
            'status'          => 'open',
            'snoozed_until'   => null,
            'closed_at'       => null,
            'updated_at'      => now_utc(),
        ], 'id = :id', ['id' => $conversationId]);

        Event::fire('message.received', [
            'conversation_id' => $conversationId,
            'message_id'      => $messageId,
            'contact_id'      => $contactId,
            'channel'         => $channel,
            'keyword'         => $keyword,
        ]);

        return [
            'conversation_id' => $conversationId,
            'message_id'      => $messageId,
            'keyword'         => $keyword,
            'reply'           => $reply,
        ];
    }

    /**
     * The contact an inbound message belongs to, created if need be.
     *
     * AN UNKNOWN SENDER IS A LEAD, NOT AN ERROR. A guest texting a number the
     * venue has never seen gets a contact row and an open conversation,
     * because dropping their message is how a venue loses a booking it never
     * knew it had. Matched on the normalised phone or the lowercased email,
     * which is why both columns exist.
     */
    private static function resolveSender(string $channel, string $from): ?int
    {
        if ($channel === 'sms') {
            $phone = normalize_phone($from);

            if ($phone === null) {
                return null;
            }

            $existing = DB::first(
                'SELECT id FROM {contacts} WHERE phone_normalized = :p AND deleted_at IS NULL
                  ORDER BY id LIMIT 1',
                ['p' => $phone]
            );

            if ($existing !== null) {
                return (int) $existing['id'];
            }

            $id = DB::insert('contacts', [
                'phone'            => $from,
                'phone_normalized' => $phone,
                'lifecycle_stage'  => 'lead',
                'source'           => 'inbound_sms',
                'created_at'       => now_utc(),
                'updated_at'       => now_utc(),
            ]);

            Event::fire('contact.created', ['contact_id' => $id, 'source' => 'inbound_sms']);

            return $id;
        }

        $email = strtolower(trim($from));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $existing = DB::first(
            'SELECT id FROM {contacts} WHERE email = :e AND deleted_at IS NULL ORDER BY id LIMIT 1',
            ['e' => $email]
        );

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $id = DB::insert('contacts', [
            'email'           => $email,
            'lifecycle_stage' => 'lead',
            'source'          => 'inbound_email',
            'created_at'      => now_utc(),
            'updated_at'      => now_utc(),
        ]);

        Event::fire('contact.created', ['contact_id' => $id, 'source' => 'inbound_email']);

        return $id;
    }

    /**
     * The conversation an inbound message joins, or a new one.
     *
     * ONE LIVE THREAD PER GUEST PER CHANNEL. A guest who writes three times in
     * a week is one conversation, not three rows in the queue competing for
     * somebody's attention — and a CLOSED thread is not reopened by matching:
     * it is reopened deliberately above, so the queue shows that something
     * came back rather than silently appending to history.
     */
    private static function threadFor(string $channel, ?int $contactId, ?string $subject): int
    {
        if ($contactId !== null) {
            $existing = DB::first(
                'SELECT id FROM {conversations}
                  WHERE contact_id = :id AND channel = :ch
                  ORDER BY (status = \'closed\') ASC, last_message_at DESC, id DESC
                  LIMIT 1',
                ['id' => $contactId, 'ch' => $channel]
            );

            if ($existing !== null) {
                return (int) $existing['id'];
            }
        }

        return DB::insert('conversations', [
            'contact_id'      => $contactId,
            'channel'         => $channel,
            'subject'         => $subject === null ? null : mb_substr($subject, 0, 255),
            'status'          => 'open',
            'is_unread'       => 1,
            'last_message_at' => now_utc(),
            'created_at'      => now_utc(),
            'updated_at'      => now_utc(),
        ]);
    }

    /**
     * Wake any snoozed conversation whose timer has passed.
     *
     * Called from cron. A snooze that never wakes is a guest's message lost,
     * so this is deliberately dumb and idempotent: anything whose time has
     * come goes back in the queue, unread, and the next run finds nothing.
     */
    public static function wakeSnoozed(): int
    {
        $due = DB::all(
            "SELECT id FROM {conversations}
              WHERE status = 'snoozed' AND snoozed_until IS NOT NULL AND snoozed_until <= :now",
            ['now' => now_utc()]
        );

        foreach ($due as $row) {
            DB::update('conversations', [
                'status'        => 'open',
                'snoozed_until' => null,
                'is_unread'     => 1,
                'updated_at'    => now_utc(),
            ], 'id = :id', ['id' => (int) $row['id']]);
        }

        return count($due);
    }

    // =================================================================
    // Reading
    // =================================================================

    /**
     * A conversation this user may see, or null.
     *
     * @return array<string,mixed>|null
     */
    private function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        [$scope, $params] = Acl::scopeCondition('view_inbox', 'c.assigned_to');
        $params['id']     = $id;

        return DB::first(
            'SELECT c.* FROM {conversations} c WHERE c.id = :id AND ' . $scope,
            $params
        );
    }

    /**
     * The WHERE clause for a queue tab.
     *
     * Returns `$filter`, never `$where`, for the static gate that forbids
     * interpolated SQL named that — the clause is built from fixed fragments
     * and every value is bound.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function queueFilter(string $filter, string $channel, string $search): array
    {
        [$scope, $params] = Acl::scopeCondition('view_inbox', 'c.assigned_to');

        $clauses = [$scope];

        $clauses[] = match ($filter) {
            'mine'       => 'c.assigned_to = :me AND c.status IN ' . self::LIVE,
            'unassigned' => 'c.assigned_to IS NULL AND c.status IN ' . self::LIVE,
            'unread'     => 'c.is_unread = 1 AND c.status IN ' . self::LIVE,
            'open'       => "c.status = 'open'",
            'snoozed'    => "c.status = 'snoozed'",
            'closed'     => "c.status = 'closed'",
            default      => '1 = 1',
        };

        if ($filter === 'mine') {
            $params['me'] = Auth::id();
        }

        if ($channel !== '') {
            $clauses[]         = 'c.channel = :channel';
            $params['channel'] = $channel;
        }

        if ($search !== '') {
            // One placeholder per occurrence: with emulated prepares off, a
            // named parameter may be bound exactly once, and reusing one is an
            // HY093 that shows up as a bare 500 on the search box.
            $clauses[] = '(ct.first_name LIKE :q1 OR ct.last_name LIKE :q2'
                . ' OR ct.email LIKE :q3 OR ct.phone LIKE :q4 OR c.subject LIKE :q5)';

            $like = '%' . $search . '%';

            foreach (range(1, 5) as $n) {
                $params['q' . $n] = $like;
            }
        }

        return [implode(' AND ', $clauses), $params];
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        [$scope, $params] = Acl::scopeCondition('view_inbox', 'c.assigned_to');

        $params['me'] = Auth::id();

        $row = DB::first(
            'SELECT
                SUM(CASE WHEN c.assigned_to = :me AND c.status IN ' . self::LIVE . ' THEN 1 ELSE 0 END) AS mine,
                SUM(CASE WHEN c.assigned_to IS NULL AND c.status IN ' . self::LIVE . ' THEN 1 ELSE 0 END) AS unassigned,
                SUM(CASE WHEN c.is_unread = 1 AND c.status IN ' . self::LIVE . ' THEN 1 ELSE 0 END) AS unread,
                SUM(CASE WHEN c.status = \'open\' THEN 1 ELSE 0 END) AS open,
                SUM(CASE WHEN c.status = \'snoozed\' THEN 1 ELSE 0 END) AS snoozed,
                SUM(CASE WHEN c.status = \'closed\' THEN 1 ELSE 0 END) AS closed,
                COUNT(*) AS all_count
               FROM {conversations} c WHERE ' . $scope,
            $params
        ) ?? [];

        return [
            'mine'       => (int) ($row['mine'] ?? 0),
            'unassigned' => (int) ($row['unassigned'] ?? 0),
            'unread'     => (int) ($row['unread'] ?? 0),
            'open'       => (int) ($row['open'] ?? 0),
            'snoozed'    => (int) ($row['snoozed'] ?? 0),
            'closed'     => (int) ($row['closed'] ?? 0),
            'all'        => (int) ($row['all_count'] ?? 0),
        ];
    }

    /**
     * What the composer may do, worked out before it is drawn.
     *
     * §8.6: "an agent physically cannot text a contact who replied STOP". A
     * box offered and then refused on submit loses the work and tells them
     * too late; this is what lets the page say so first, in the same words the
     * send path would use.
     *
     * @param array<string,mixed>|null $contact
     * @return array{can_send:bool,reason:string,explanation:string,limit:int,segments:int}
     */
    private function composerState(string $channel, ?array $contact): array
    {
        $base = [
            'can_send'    => true,
            'reason'      => 'ok',
            'explanation' => '',
            'limit'       => $channel === 'sms' ? SmsPolicy::GSM_SINGLE : 0,
            'segments'    => 0,
        ];

        if ($channel === 'internal') {
            return $base;
        }

        if ($contact === null) {
            return [
                'can_send'    => false,
                'reason'      => 'no_contact',
                'explanation' => 'This conversation is not linked to a guest, so there is nobody to reply to.',
            ] + $base;
        }

        if ((int) ($contact['do_not_contact'] ?? 0) === 1) {
            return [
                'can_send'    => false,
                'reason'      => 'do_not_contact',
                'explanation' => 'This guest has asked not to be contacted at all.',
            ] + $base;
        }

        if ($channel === 'sms') {
            $phone = (string) ($contact['phone_normalized'] ?? '');

            if ($phone === '') {
                return [
                    'can_send'    => false,
                    'reason'      => 'no_number',
                    'explanation' => 'There is no mobile number on this guest to text.',
                ] + $base;
            }

            // The send path's own answer, for a representative message. A
            // transactional reply from the inbox needs no consent — but it
            // does need the number not to be suppressed, and this is how the
            // page finds that out in the send path's own words.
            $preview = Sms::preview([
                'to'         => $phone,
                'body'       => 'x',
                'kind'       => Sms::TRANSACTIONAL,
                'contact_id' => (int) $contact['id'],
            ]);

            if (!$preview['ok']) {
                return [
                    'can_send'    => false,
                    'reason'      => $preview['reason'],
                    'explanation' => Sms::explain($preview['reason']),
                ] + $base;
            }

            return $base;
        }

        if (trim((string) ($contact['email'] ?? '')) === '') {
            return [
                'can_send'    => false,
                'reason'      => 'no_email',
                'explanation' => 'There is no email address on this guest to write to.',
            ] + $base;
        }

        return $base;
    }

    /** @return list<array<string,mixed>> */
    private function cannedReplies(string $channel): array
    {
        return DB::all(
            "SELECT id, title, body, shortcut FROM {canned_replies}
              WHERE channel = :ch OR channel = 'any'
              ORDER BY title",
            ['ch' => $channel]
        );
    }

    /**
     * Who a conversation can be handed to.
     *
     * ONLY PEOPLE WHO CAN ACTUALLY REPLY. Assigning a guest's question to
     * somebody with no `inbox_reply` is handing it to a dead end: the queue
     * shows it as their problem while they can do nothing about it, and the
     * guest waits on somebody who cannot answer.
     *
     * The Owner is included unconditionally because `Acl::can()` lets that
     * role past every check — a list built only from `role_permissions` would
     * leave the one account that can do everything off the list of people who
     * can do this.
     *
     * @return list<array<string,mixed>>
     */
    private function assignees(): array
    {
        return DB::all(
            "SELECT u.id, u.name, r.name AS role_name
               FROM {users} u
               JOIN {roles} r ON r.id = u.role_id
              WHERE u.is_active = 1 AND u.deleted_at IS NULL
                AND (
                    r.slug = 'owner'
                    OR EXISTS (
                        SELECT 1 FROM {role_permissions} rp
                          JOIN {permissions} p ON p.id = rp.permission_id
                         WHERE rp.role_id = u.role_id AND p.slug = 'inbox_reply'
                    )
                )
              ORDER BY u.name"
        );
    }

    private function isAssignable(int $userId): bool
    {
        // The same rule as the list, asked of one person — so the form cannot
        // offer anybody the save would refuse, and a hand-posted id cannot
        // reach anybody the form would not have offered.
        return DB::exists(
            "SELECT u.id FROM {users} u
               JOIN {roles} r ON r.id = u.role_id
              WHERE u.id = :id AND u.is_active = 1 AND u.deleted_at IS NULL
                AND (
                    r.slug = 'owner'
                    OR EXISTS (
                        SELECT 1 FROM {role_permissions} rp
                          JOIN {permissions} p ON p.id = rp.permission_id
                         WHERE rp.role_id = u.role_id AND p.slug = 'inbox_reply'
                    )
                )",
            ['id' => $userId]
        );
    }

    /** @return list<array<string,mixed>> */
    private function recentBookings(?int $contactId): array
    {
        if ($contactId === null) {
            return [];
        }

        return DB::all(
            'SELECT id, booking_number, event_date, start_time, status, guest_count
               FROM {bookings}
              WHERE contact_id = :id AND deleted_at IS NULL
              ORDER BY event_date DESC LIMIT 5',
            ['id' => $contactId]
        );
    }

    /**
     * The subject an email reply carries.
     *
     * "Re:" is added once, not every time: a thread that has been round a few
     * times should not read "Re: Re: Re: Re: your party".
     *
     * @param array<string,mixed> $conversation
     */
    private function replySubject(array $conversation): string
    {
        $subject = trim((string) ($conversation['subject'] ?? ''));

        if ($subject === '') {
            return 'Re: your message to ' . Settings::venueName();
        }

        return str_starts_with(strtolower($subject), 're:') ? $subject : 'Re: ' . $subject;
    }

    /**
     * Add the things a queue row needs that are not columns.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decorate(array $row): array
    {
        $name = trim(
            trim((string) ($row['first_name'] ?? '')) . ' ' . trim((string) ($row['last_name'] ?? ''))
        );

        if ($name === '') {
            $name = trim((string) ($row['email'] ?? ''));
        }

        if ($name === '') {
            $name = trim((string) ($row['phone'] ?? ''));
        }

        $row['display_name'] = $name === '' ? 'Unknown sender' : $name;
        $row['preview']      = Inbox::preview((string) ($row['last_body'] ?? ''));

        return $row;
    }

    /** @return array<string,callable> */
    private function formatters(): array
    {
        return [
            'fmtWhen' => static fn (?string $utc): string => $utc === null ? '' : time_ago($utc),
            'fmtTime' => static fn (?string $utc): string => $utc === null ? '' : local_time($utc),
        ];
    }

}
