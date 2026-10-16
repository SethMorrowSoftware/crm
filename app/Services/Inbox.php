<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The parts of the inbox that can be decided without reading a row.
 *
 * Mostly this is the SNOOZE ARITHMETIC, which is the one piece of the inbox
 * that is genuinely easy to get wrong and impossible to notice: "remind me
 * tomorrow morning" means nine o'clock in the venue's own wall clock, and the
 * gap between now and then is 23, 24 or 25 hours depending on the date. Adding
 * 86400 seconds is right for 363 days a year, and on the other two it buries a
 * guest's message for an extra hour or surfaces it an hour early — on a Sunday
 * morning, when nobody is watching the queue.
 *
 * The filters and the first-response rule live here for the same reason the
 * pricing rules live in QuoteCalculator: they decide what a number means, so
 * they belong somewhere a test can reach without a database.
 */
final class Inbox
{
    /**
     * The queue tabs, in the order they are shown.
     *
     * `mine` first and `all` last, because the question a rep opens the inbox
     * to answer is "what is waiting for ME", and the question a manager opens
     * it to answer is everything else.
     */
    public const FILTERS = [
        'mine'       => 'Assigned to me',
        'unassigned' => 'Unassigned',
        'unread'     => 'Unread',
        'open'       => 'Open',
        'snoozed'    => 'Snoozed',
        'closed'     => 'Closed',
        'all'        => 'All',
    ];

    public const CHANNELS = ['email', 'sms', 'webchat', 'phone', 'social', 'internal'];

    /** The channels a member of staff can actually send on from here. */
    public const REPLY_CHANNELS = ['email', 'sms', 'internal'];

    /**
     * Snooze choices, as labels. The arithmetic is in `snoozeUntil()`.
     *
     * Every one of these lands at a time somebody is at a desk. "In 3 hours"
     * from nine in the evening would be midnight, so the evening options wrap
     * to the next working morning rather than producing a reminder nobody sees.
     */
    public const SNOOZE_OPTIONS = [
        '1h'       => 'In an hour',
        '3h'       => 'In three hours',
        'tomorrow' => 'Tomorrow morning',
        'monday'   => 'Monday morning',
        'week'     => 'In a week',
    ];

    /** The hour a "morning" snooze lands on, venue-local. */
    public const MORNING_HOUR = 9;

    /**
     * After this hour, a relative snooze rolls to the next morning instead of
     * landing in the middle of the night.
     */
    public const LAST_USEFUL_HOUR = 20;

    // =================================================================
    // Snoozing
    // =================================================================

    /**
     * When a snooze should surface, as a UTC timestamp.
     *
     * $localNow is the venue's own clock; $timezone is its zone. Both are
     * passed in rather than read, which is what makes every boundary below
     * testable on a fixed date instead of only ever at whatever hour the
     * suite happens to run.
     *
     * Returns null for an option this does not recognise, so a caller refuses
     * rather than snoozing a guest's message to an arbitrary moment.
     */
    public static function snoozeUntil(
        string $option,
        DateTimeImmutable $localNow,
        string $timezone
    ): ?string {
        $zone  = new DateTimeZone($timezone);
        $local = $localNow->setTimezone($zone);

        $target = match ($option) {
            // RELATIVE OPTIONS ARE ARITHMETIC ON AN INSTANT, so they are
            // immune to DST by construction: "in an hour" is sixty minutes
            // later whatever the clocks do. They only need the roll-forward
            // below, for the case where sixty minutes later is one in the
            // morning.
            '1h'       => self::afterHours($local, 1),
            '3h'       => self::afterHours($local, 3),

            // ABSOLUTE OPTIONS ARE A WALL-CLOCK TIME ON A CALENDAR DAY, which
            // is where DST actually bites. Built by naming the local date and
            // hour and letting the zone resolve it, never by adding seconds.
            'tomorrow' => self::morningOn($local->modify('+1 day'), $zone),
            'monday'   => self::morningOn(self::nextMonday($local), $zone),
            'week'     => self::morningOn($local->modify('+7 days'), $zone),

            default    => null,
        };

        if ($target === null) {
            return null;
        }

        return $target->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * A custom snooze from a typed local date and time.
     *
     * Refuses the past: a snooze that has already elapsed surfaces instantly,
     * which looks exactly like the button not working.
     */
    public static function snoozeUntilCustom(
        string $localDateTime,
        DateTimeImmutable $localNow,
        string $timezone
    ): ?string {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/', trim($localDateTime), $m) !== 1) {
            return null;
        }

        $hour   = (int) $m[2];
        $minute = (int) $m[3];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        $zone  = new DateTimeZone($timezone);
        $local = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $m[1] . ' ' . $m[2] . ':' . $m[3] . ':00',
            $zone
        );

        if ($local === false) {
            return null;
        }

        if ($local <= $localNow->setTimezone($zone)) {
            return null;
        }

        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * $hours from now — rolled to the next morning if that lands at night.
     *
     * "In three hours" pressed at nine in the evening is midnight, and a
     * reminder at midnight is a reminder nobody sees. The guest's message
     * deserves to resurface when somebody is at a desk.
     */
    private static function afterHours(DateTimeImmutable $local, int $hours): DateTimeImmutable
    {
        $target = $local->modify('+' . $hours . ' hours');

        if ((int) $target->format('G') >= self::LAST_USEFUL_HOUR
            || (int) $target->format('G') < self::MORNING_HOUR) {
            return self::morningOn(
                // Past midnight already, so the next morning is TODAY in the
                // target's own terms; before midnight, it is tomorrow.
                (int) $target->format('G') < self::MORNING_HOUR
                    ? $target
                    : $target->modify('+1 day'),
                $target->getTimezone()
            );
        }

        return $target;
    }

    /**
     * Nine in the morning on this date, in this zone.
     *
     * `setTime` on a zoned object, so the zone resolves the wall clock — which
     * is the whole point. On a spring-forward date this is still nine o'clock
     * local; computed as an offset from midnight it would be ten.
     */
    private static function morningOn(DateTimeImmutable $date, DateTimeZone $zone): DateTimeImmutable
    {
        return $date->setTimezone($zone)->setTime(self::MORNING_HOUR, 0, 0);
    }

    /** The next Monday strictly after this date. */
    private static function nextMonday(DateTimeImmutable $local): DateTimeImmutable
    {
        // `modify('next monday')` already means "strictly after", so a
        // conversation snoozed on a Monday surfaces the FOLLOWING Monday
        // rather than nine o'clock this morning, which is in the past.
        return $local->modify('next monday');
    }

    // =================================================================
    // First response
    // =================================================================

    /**
     * Does this outbound message count as the venue's first response?
     *
     * The number it feeds is reported per rep, so it has to mean "a person
     * answered a guest". Three things are deliberately NOT a response:
     *
     *   * an INBOUND message — obviously, but the predicate is called on
     *     whatever just landed, so it has to say so;
     *   * a CAMPAIGN send that happened to go to this contact while their
     *     question sat unanswered. A newsletter is not an answer, and counting
     *     it would make the metric read best for the reps who answer least;
     *   * an AUTOMATION. An auto-acknowledgement is worth sending and is not a
     *     person replying — the whole point of the measure is how long a guest
     *     waited for one.
     *
     * An INTERNAL note is also not a response: it is staff talking to each
     * other, and the guest has still heard nothing.
     *
     * @param array<string,mixed> $message
     */
    public static function isFirstResponse(array $message): bool
    {
        if ((string) ($message['direction'] ?? '') !== 'outbound') {
            return false;
        }

        if ((string) ($message['channel'] ?? '') === 'internal') {
            return false;
        }

        if (($message['campaign_id'] ?? null) !== null) {
            return false;
        }

        if (($message['automation_run_id'] ?? null) !== null) {
            return false;
        }

        return ($message['user_id'] ?? null) !== null;
    }

    /**
     * How long a guest waited, in minutes, or null when nobody has replied.
     *
     * @param array<string,mixed> $conversation
     */
    public static function responseMinutes(array $conversation): ?int
    {
        $opened = $conversation['created_at'] ?? null;
        $replied = $conversation['first_response_at'] ?? null;

        if (!is_string($opened) || !is_string($replied) || $opened === '' || $replied === '') {
            return null;
        }

        $from = strtotime($opened . ' UTC');
        $to   = strtotime($replied . ' UTC');

        if ($from === false || $to === false || $to < $from) {
            return null;
        }

        return (int) round(($to - $from) / 60);
    }

    /** "4 minutes", "2 hours", "3 days" — or null when nobody has replied. */
    public static function responseLabel(array $conversation): ?string
    {
        $minutes = self::responseMinutes($conversation);

        if ($minutes === null) {
            return null;
        }

        if ($minutes < 60) {
            return $minutes . ($minutes === 1 ? ' minute' : ' minutes');
        }

        if ($minutes < 1440) {
            $hours = (int) round($minutes / 60);

            return $hours . ($hours === 1 ? ' hour' : ' hours');
        }

        $days = (int) round($minutes / 1440);

        return $days . ($days === 1 ? ' day' : ' days');
    }

    // =================================================================
    // Composing
    // =================================================================

    /**
     * Which channel a reply should go out on.
     *
     * The conversation's own channel when a reply can be sent on it, and
     * email otherwise — a webchat or social thread has no outbound route in
     * this application yet, and silently composing into one would produce a
     * reply the guest never receives.
     */
    public static function replyChannel(string $conversationChannel): string
    {
        return in_array($conversationChannel, self::REPLY_CHANNELS, true)
            ? $conversationChannel
            : 'email';
    }

    /**
     * Merge values for a canned reply.
     *
     * Deliberately small, and every value a plain string. A canned reply is
     * typed by staff and rendered into a message to a guest, so what it can
     * reach is an allowlist rather than "the contact row": adding a tag is a
     * decision, and `{{notes}}` or `{{lifetime_value}}` in a reply somebody
     * pasted in a hurry is a different kind of mistake.
     *
     * @param array<string,mixed> $contact
     * @param array<string,mixed> $venue
     * @return array<string,string>
     */
    public static function mergeTags(array $contact, array $venue): array
    {
        $first = trim((string) ($contact['first_name'] ?? ''));

        return [
            // "there" rather than an empty space, so an unnamed guest gets
            // "Hi there" instead of "Hi ,".
            'first_name' => $first === '' ? 'there' : $first,
            'last_name'  => trim((string) ($contact['last_name'] ?? '')),
            'full_name'  => trim(
                $first . ' ' . trim((string) ($contact['last_name'] ?? ''))
            ),
            'email'      => trim((string) ($contact['email'] ?? '')),
            'phone'      => trim((string) ($contact['phone'] ?? '')),
            'venue_name' => trim((string) ($venue['name'] ?? '')),
            'venue_phone' => trim((string) ($venue['phone'] ?? '')),
        ];
    }

    /**
     * A one-line preview of a message for the queue list.
     *
     * Tags are stripped before truncating, not after: cutting an email at 120
     * characters of HTML can land inside a tag, and the fragment that
     * survives would be rendered as markup by the page that shows it.
     */
    public static function preview(string $body, int $length = 120): string
    {
        $text = html_entity_decode(
            strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $body) ?? $body),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($text === '') {
            return '';
        }

        return mb_strlen($text) <= $length
            ? $text
            : rtrim(mb_substr($text, 0, $length - 1)) . '…';
    }
}
