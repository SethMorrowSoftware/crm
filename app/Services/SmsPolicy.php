<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Everything about a text message that can be decided without a database,
 * a network or a clock.
 *
 * Three questions live here, and all three are the kind that look trivial and
 * are not:
 *
 *   1. **HOW MANY MESSAGES IS THIS?** A text is not "160 characters". It is
 *      160 characters *in GSM-7*, 70 in UCS-2, and one curly quote pasted in
 *      from a word processor moves a 300-character offer from two segments to
 *      five — tripling what the venue pays and doing it silently. The
 *      composer shows the count before sending, and it has to be the count
 *      the carrier will charge for.
 *   2. **MAY WE SEND IT NOW?** Quiet hours are a window in the venue's own
 *      wall clock, and a message outside it is DEFERRED, not dropped.
 *   3. **DOES A BULK MESSAGE CARRY ITS OPT-OUT?** The template validator
 *      blocks a save without one, because a bulk text with no way out is the
 *      kind of mistake that ends in a regulator's letter rather than a bug
 *      report.
 *
 * The timezone conversion itself is the CALLER's job: this takes a local
 * minute-of-day and gives back a local minute-of-day, exactly as
 * AvailabilityEngine takes minutes and knows nothing about DST. That is what
 * makes every boundary here testable without a database or a fixed date.
 */
final class SmsPolicy
{
    /**
     * The GSM 03.38 basic alphabet — 128 characters, seven bits each.
     *
     * Written out rather than computed because the order IS the standard and
     * a "tidier" derivation would be a different alphabet. Note what is NOT
     * here and is routinely assumed to be: the curly quotes and em dash a
     * word processor produces, every emoji, and the degree sign.
     */
    public const GSM_BASIC = '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r" . 'Åå'
        . 'Δ_ΦΓΛΩΠΨΣΘΞ'
        . 'ÆæßÉ'
        . ' !"#¤%&\'()*+,-./0123456789:;<=>?'
        . '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§'
        . '¿abcdefghijklmnopqrstuvwxyzäöñüà';

    /**
     * The GSM 03.38 extension table. Each of these is sent as an escape
     * followed by the character, so it occupies TWO of the seven-bit slots —
     * which is why a message of 160 square brackets is two segments.
     */
    public const GSM_EXTENDED = '^{}\\[~]|€';

    /** A single GSM-7 message. */
    public const GSM_SINGLE = 160;

    /**
     * A GSM-7 message in a concatenated set. The user-data header that says
     * "part 2 of 3" costs six bits short of seven characters, so every
     * segment of a long message holds 153, not 160.
     */
    public const GSM_MULTI = 153;

    /** A single UCS-2 message: 70 sixteen-bit units. */
    public const UCS2_SINGLE = 70;

    /** And 67 per segment once the header is in. */
    public const UCS2_MULTI = 67;

    /** Carriers stop reassembling somewhere around here; refuse rather than truncate. */
    public const MAX_SEGMENTS = 10;

    /** Default permitted window: 9am to 8pm, venue-local. */
    public const DEFAULT_QUIET_START = 9 * 60;
    public const DEFAULT_QUIET_END   = 20 * 60;

    /**
     * Words a guest can text to stop, and what each means.
     *
     * Handled on inbound BEFORE anything else — before contact resolution,
     * before the conversation, before any automation. A STOP that lands in a
     * queue behind a slow webhook is a STOP that did not work.
     */
    public const KEYWORDS = [
        'STOP'        => 'stop',
        'STOPALL'     => 'stop',
        'UNSUBSCRIBE' => 'stop',
        'CANCEL'      => 'stop',
        'END'         => 'stop',
        'QUIT'        => 'stop',
        'START'       => 'start',
        'UNSTOP'      => 'start',
        'YES'         => 'confirm',
        'HELP'        => 'help',
        'INFO'        => 'help',
        'JOIN'        => 'join',
    ];

    // =================================================================
    // How many messages is this?
    // =================================================================

    /**
     * 'gsm7' when every character is in the GSM alphabet, 'ucs2' otherwise.
     *
     * One character decides it for the WHOLE message: there is no mixing, so a
     * single emoji in a 300-character text re-encodes all 300 and takes it
     * from two segments to five.
     */
    public static function encoding(string $body): string
    {
        foreach (self::characters($body) as $char) {
            if (!self::isGsm($char)) {
                return 'ucs2';
            }
        }

        return 'gsm7';
    }

    /**
     * The billable length: seven-bit slots for GSM-7, sixteen-bit units for
     * UCS-2.
     *
     * NOT `mb_strlen()` in either case. In GSM-7 the nine extension
     * characters take two slots each; in UCS-2 anything outside the basic
     * multilingual plane — which is every emoji anybody actually uses — is a
     * surrogate PAIR and takes two units. Counting code points gets both
     * wrong in the direction that under-reports the bill.
     */
    public static function length(string $body): int
    {
        $ucs2  = self::encoding($body) === 'ucs2';
        $count = 0;

        foreach (self::characters($body) as $char) {
            if ($ucs2) {
                // Two units for anything above U+FFFF, one otherwise.
                $count += self::codepoint($char) > 0xFFFF ? 2 : 1;

                continue;
            }

            $count += str_contains(self::GSM_EXTENDED, $char) ? 2 : 1;
        }

        return $count;
    }

    /**
     * How many messages the carrier will send, and bill for.
     *
     * An empty body is one message, not zero: the provider would accept it and
     * charge for it. Refusing an empty body is the send path's job, and it
     * does; reporting it as free would be a different kind of wrong.
     */
    public static function segments(string $body): int
    {
        $length = self::length($body);
        $ucs2   = self::encoding($body) === 'ucs2';

        $single = $ucs2 ? self::UCS2_SINGLE : self::GSM_SINGLE;
        $multi  = $ucs2 ? self::UCS2_MULTI : self::GSM_MULTI;

        if ($length <= $single) {
            return 1;
        }

        return (int) ceil($length / $multi);
    }

    /**
     * What is left before this message costs another segment.
     *
     * The number the composer puts under the box. Zero means the next
     * character adds a segment — and because crossing from one segment to two
     * re-measures the whole message against the smaller limit, this is NOT
     * simply "limit minus length" once a message is long.
     */
    public static function remaining(string $body): int
    {
        $length   = self::length($body);
        $ucs2     = self::encoding($body) === 'ucs2';
        $segments = self::segments($body);

        $capacity = $segments === 1
            ? ($ucs2 ? self::UCS2_SINGLE : self::GSM_SINGLE)
            : $segments * ($ucs2 ? self::UCS2_MULTI : self::GSM_MULTI);

        return max(0, $capacity - $length);
    }

    /**
     * The cost of one send, in cents, as an integer.
     *
     * Integer cents like every other money path in this application, and the
     * same half-away-from-zero rounding: a unit price is often a fraction of
     * a cent, and truncating it under-reports what a 40,000-recipient blast
     * will cost — which is exactly the figure somebody is about to approve.
     */
    public static function costCents(int $segments, int $unitMillicents, int $recipients = 1): int
    {
        $total = max(0, $segments) * max(0, $unitMillicents) * max(0, $recipients);

        return intdiv($total + 500, 1000);
    }

    /**
     * A one-line summary for the composer: "2 messages · GSM-7 · 47 left".
     */
    public static function describe(string $body): string
    {
        $segments = self::segments($body);
        $encoding = self::encoding($body) === 'ucs2' ? 'Unicode' : 'GSM-7';

        return $segments . ' ' . ($segments === 1 ? 'message' : 'messages')
            . ' · ' . $encoding
            . ' · ' . self::remaining($body) . ' characters left';
    }

    /**
     * The characters that forced this message into Unicode, de-duplicated.
     *
     * So the composer can say WHICH ones rather than only that it happened —
     * a curly apostrophe is invisible at a glance and is the usual culprit,
     * and "replace ’ with '" is actionable where "your message is Unicode" is
     * not.
     *
     * @return list<string>
     */
    public static function offendingCharacters(string $body, int $limit = 8): array
    {
        $found = [];

        foreach (self::characters($body) as $char) {
            if (!self::isGsm($char) && !in_array($char, $found, true)) {
                $found[] = $char;

                if (count($found) >= $limit) {
                    break;
                }
            }
        }

        return $found;
    }

    // =================================================================
    // May we send it now?
    // =================================================================

    /**
     * Is this local minute inside the permitted window?
     *
     * A window whose start equals its end means NO RESTRICTION rather than no
     * sending. That is the kinder reading of an unset pair of settings, and
     * the alternative — a venue that saves 0 and 0 and silently never sends
     * another text — is a failure nobody would diagnose from the UI.
     */
    public static function withinWindow(int $localMinutes, int $start, int $end): bool
    {
        $localMinutes = self::clampDay($localMinutes);
        $start        = self::clampDay($start);
        $end          = self::clampDay($end);

        if ($start === $end) {
            return true;
        }

        // A window that wraps midnight (22:00 to 06:00) is a legitimate thing
        // to configure even if a venue is unlikely to; defining it is cheaper
        // than leaving it to behave arbitrarily.
        if ($start < $end) {
            return $localMinutes >= $start && $localMinutes < $end;
        }

        return $localMinutes >= $start || $localMinutes < $end;
    }

    /**
     * Minutes to wait before this message may be sent.
     *
     * 0 when it may go now. Otherwise the gap to the next time the window
     * opens — which is TOMORROW's opening when the day's window has already
     * closed, and that is the case worth getting right: a nine-o'clock
     * evening blast must not go out at one minute past midnight.
     */
    public static function minutesUntilWindow(int $localMinutes, int $start, int $end): int
    {
        if (self::withinWindow($localMinutes, $start, $end)) {
            return 0;
        }

        $localMinutes = self::clampDay($localMinutes);
        $start        = self::clampDay($start);

        return $localMinutes < $start
            ? $start - $localMinutes
            : 1440 - $localMinutes + $start;
    }

    // =================================================================
    // Does a bulk message carry its opt-out?
    // =================================================================

    /**
     * Does this body tell the reader how to stop?
     *
     * Deliberately generous about the wording and strict about the word: any
     * of the stop keywords, as a WHOLE WORD, satisfies it. A substring test
     * would accept "STOPWATCH"; requiring one exact phrase would reject every
     * sensible way of writing it ("Txt STOP to opt out", "Reply STOP to
     * unsubscribe", "STOP to quit").
     */
    public static function hasOptOut(string $body): bool
    {
        $stops = array_keys(array_filter(
            self::KEYWORDS,
            static fn (string $action): bool => $action === 'stop'
        ));

        foreach ($stops as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/iu', $body) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a guest's inbound text means, or null if it is an ordinary message.
     *
     * The whole message must be the keyword, give or take punctuation and
     * case. "STOP" stops; "please stop sending me these" is a sentence a
     * human needs to read, and treating it as a keyword would close the
     * conversation and lose the complaint. (The venue should honour it too —
     * but by a person reading it, not by a regex guessing.)
     */
    public static function keywordFor(string $body): ?string
    {
        $word = strtoupper(trim(preg_replace('/[^\p{L}\p{N}\s]+/u', '', $body) ?? ''));
        $word = preg_replace('/\s+/', ' ', $word) ?? '';

        return self::KEYWORDS[$word] ?? null;
    }

    // =================================================================
    // Small helpers
    // =================================================================

    /**
     * The message as a list of whole characters.
     *
     * `mb_str_split` and not `str_split`: this has to count characters, and a
     * byte-wise split would read one accented letter as two unknown bytes and
     * declare a perfectly ordinary GSM-7 message Unicode.
     *
     * @return list<string>
     */
    private static function characters(string $body): array
    {
        $chars = mb_str_split($body, 1, 'UTF-8');

        return $chars === false ? [] : $chars;
    }

    private static function isGsm(string $char): bool
    {
        return mb_strpos(self::GSM_BASIC, $char, 0, 'UTF-8') !== false
            || mb_strpos(self::GSM_EXTENDED, $char, 0, 'UTF-8') !== false;
    }

    private static function codepoint(string $char): int
    {
        $converted = mb_convert_encoding($char, 'UTF-32BE', 'UTF-8');

        if ($converted === false || strlen($converted) < 4) {
            return 0;
        }

        /** @var array{1:int} $unpacked */
        $unpacked = unpack('N', substr($converted, 0, 4)) ?: [1 => 0];

        return (int) $unpacked[1];
    }

    private static function clampDay(int $minutes): int
    {
        $minutes %= 1440;

        return $minutes < 0 ? $minutes + 1440 : $minutes;
    }
}
