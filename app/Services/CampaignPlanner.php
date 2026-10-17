<?php

declare(strict_types=1);

namespace App\Services;

/**
 * What a campaign will cost, who it will reach, and when it may go.
 *
 * Everything decidable without a database or a clock. The numbers this
 * produces are the ones somebody reads immediately before pressing a button
 * that cannot be taken back, so three of them are worth being careful about.
 *
 * ## The cost estimate
 *
 * An email costs whatever the provider charges per message. A TEXT costs per
 * SEGMENT, and the segment count depends on the characters in the body — one
 * curly apostrophe pasted in from a word processor re-encodes a 300-character
 * offer from two segments to five. On a forty-thousand-recipient Text Club
 * blast that is the difference between $600 and $1,500, decided by a character
 * nobody can see. So the estimate is computed from the ACTUAL body, the
 * segment count is shown, and anything above a threshold asks for
 * confirmation.
 *
 * ## Who it will reach
 *
 * A segment selects people; it does not grant permission to contact them.
 * `audience()` splits a segment's members into those a message can actually
 * go to and those it cannot, WITH A REASON EACH. That gap is not an error to
 * hide: "400 in the segment, 180 will get this, 220 have not opted in" is the
 * single most useful sentence on the page, and a venue that only ever saw 400
 * would conclude the system was broken when 180 arrived.
 *
 * ## When it may go
 *
 * A send window, in the venue's own wall clock. Outside it the campaign waits
 * rather than failing — the same rule as a single text, for the same reason.
 */
final class CampaignPlanner
{
    /** How many recipients one cron tick will attempt. */
    public const CHUNK = 50;

    /**
     * Above this, sending asks for a typed confirmation.
     *
     * Twenty dollars. Low enough that the first real blast asks, high enough
     * that testing on a handful of people does not.
     */
    public const CONFIRM_ABOVE_CENTS = 2000;

    /** The channels a campaign can go out on. */
    public const CHANNELS = ['email', 'sms'];

    /**
     * Why a member of a segment will not get this campaign.
     *
     * Every one of these is a REASON, stored per recipient, so the venue can
     * answer "why didn't Dana get it" months later. A skipped recipient is
     * recorded, never silently dropped: a campaign that reached 180 of 400
     * with no explanation looks like a bug in the sending.
     */
    public const SKIP_REASONS = [
        'no_email'       => 'No email address on file',
        'no_mobile'      => 'No mobile number on file',
        'no_consent'     => 'Has not opted in to this channel',
        'do_not_contact' => 'Asked not to be contacted at all',
        'suppressed'     => 'Unsubscribed or bounced previously',
        'frequency_cap'  => 'Already had this week\'s maximum',
        'already_sent'   => 'Already sent this campaign',
    ];

    // =================================================================
    // The cost
    // =================================================================

    /**
     * What one message on this channel costs, in integer cents.
     *
     * $unitMillicents is thousandths of a cent, because carriers quote
     * fractions and a campaign of forty thousand multiplies the rounding
     * error by forty thousand.
     */
    public static function unitCost(string $channel, string $body, int $unitMillicents): int
    {
        if ($channel === 'sms') {
            return SmsPolicy::costCents(SmsPolicy::segments($body), $unitMillicents);
        }

        return SmsPolicy::costCents(1, $unitMillicents);
    }

    /**
     * The whole estimate.
     *
     * @return array{
     *     recipients:int, segments:int, encoding:string,
     *     unit_cost_cents:int, total_cents:int,
     *     needs_confirmation:bool, over_by_cents:int
     * }
     */
    public static function estimate(
        string $channel,
        string $body,
        int $recipients,
        int $unitMillicents,
        int $confirmAbove = self::CONFIRM_ABOVE_CENTS
    ): array {
        $recipients = max(0, $recipients);
        $segments   = $channel === 'sms' ? SmsPolicy::segments($body) : 1;

        // Multiplied BEFORE rounding, so forty thousand messages at three
        // quarters of a cent is $300 rather than $400. Rounding each message
        // up and then multiplying is the obvious way to do this and it
        // over-states the bill by a third at that price.
        $total = SmsPolicy::costCents($segments, $unitMillicents, $recipients);

        return [
            'recipients'         => $recipients,
            'segments'           => $segments,
            'encoding'           => $channel === 'sms' ? SmsPolicy::encoding($body) : 'n/a',
            'unit_cost_cents'    => self::unitCost($channel, $body, $unitMillicents),
            'total_cents'        => $total,
            'needs_confirmation' => $confirmAbove > 0 && $total > $confirmAbove,
            'over_by_cents'      => max(0, $total - $confirmAbove),
        ];
    }

    // =================================================================
    // The audience
    // =================================================================

    /**
     * Can this contact be sent this campaign, and if not, why not?
     *
     * A PRE-FLIGHT, not the decision. The real decision is made inside
     * `Core\Mailer::send()` / `Core\Sms::send()` for every single recipient,
     * every time — this cannot grant anything it refuses and cannot bypass
     * anything it allows. What it is for is telling somebody BEFORE they send
     * that half their segment has not opted in, which is a question the send
     * path can only answer afterwards.
     *
     * The reasons are checked in the order a person would explain them:
     * the absolute instruction first, then the channel-specific ones.
     *
     * @param array<string,mixed> $contact
     * @return string|null the skip reason, or null when it can go
     */
    public static function skipReason(array $contact, string $channel): ?string
    {
        if ((int) ($contact['do_not_contact'] ?? 0) === 1) {
            return 'do_not_contact';
        }

        if ($channel === 'sms') {
            if (trim((string) ($contact['phone_normalized'] ?? '')) === '') {
                return 'no_mobile';
            }

            if ((int) ($contact['sms_consent'] ?? 0) !== 1) {
                return 'no_consent';
            }

            return null;
        }

        if (trim((string) ($contact['email'] ?? '')) === '') {
            return 'no_email';
        }

        if ((int) ($contact['email_consent'] ?? 0) !== 1) {
            return 'no_consent';
        }

        return null;
    }

    /**
     * Split an audience into those it can go to and those it cannot.
     *
     * @param list<array<string,mixed>> $contacts
     * @return array{
     *     sendable:list<int>, skipped:array<int,string>,
     *     counts:array<string,int>, total:int, reach:int
     * }
     */
    public static function audience(array $contacts, string $channel): array
    {
        $sendable = [];
        $skipped  = [];
        $counts   = [];

        foreach ($contacts as $contact) {
            $id = (int) ($contact['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $reason = self::skipReason($contact, $channel);

            if ($reason === null) {
                $sendable[] = $id;

                continue;
            }

            $skipped[$id]     = $reason;
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        return [
            'sendable' => $sendable,
            'skipped'  => $skipped,
            'counts'   => $counts,
            'total'    => count($sendable) + count($skipped),
            'reach'    => count($sendable),
        ];
    }

    /**
     * The audience in one sentence.
     *
     * "400 in the segment · 180 will get this · 220 have not opted in". The
     * most useful line on the page, and the reason a venue does not conclude
     * the system is broken when 180 arrive out of 400.
     *
     * @param array{total:int,reach:int,counts:array<string,int>} $audience
     */
    public static function describeAudience(array $audience): string
    {
        $total = $audience['total'];
        $reach = $audience['reach'];

        if ($total === 0) {
            return 'Nobody is in this segment yet.';
        }

        $parts = [
            number_format($total) . ' in the segment',
            number_format($reach) . ' will get this',
        ];

        // Ordered biggest first, because the largest reason is the one worth
        // acting on.
        $counts = $audience['counts'];
        arsort($counts);

        foreach ($counts as $reason => $count) {
            $parts[] = number_format($count) . ' ' . self::reasonPhrase((string) $reason, $count);
        }

        return implode(' · ', $parts);
    }

    /** The plural-aware phrase for a skip reason. */
    private static function reasonPhrase(string $reason, int $count): string
    {
        $one = $count === 1;

        return match ($reason) {
            'no_email'       => $one ? 'has no email address' : 'have no email address',
            'no_mobile'      => $one ? 'has no mobile number' : 'have no mobile number',
            'no_consent'     => $one ? 'has not opted in' : 'have not opted in',
            'do_not_contact' => $one ? 'asked not to be contacted' : 'asked not to be contacted',
            'suppressed'     => $one ? 'unsubscribed previously' : 'unsubscribed previously',
            'frequency_cap'  => $one ? 'had this week\'s maximum' : 'had this week\'s maximum',
            'already_sent'   => $one ? 'already had it' : 'already had it',
            default          => $one ? 'cannot be reached' : 'cannot be reached',
        };
    }

    // =================================================================
    // The send window
    // =================================================================

    /**
     * May a campaign send at this local minute?
     *
     * A null window means no restriction. Both halves must be set for one to
     * apply — half a window is almost certainly a half-filled form, and
     * guessing the other end would either stop all sending or allow all of
     * it, both silently.
     */
    public static function windowAllows(int $localMinutes, ?int $start, ?int $end): bool
    {
        if ($start === null || $end === null) {
            return true;
        }

        return SmsPolicy::withinWindow($localMinutes, $start, $end);
    }

    /** Minutes until the window opens, or 0 if it is open now. */
    public static function minutesUntilWindow(int $localMinutes, ?int $start, ?int $end): int
    {
        if ($start === null || $end === null) {
            return 0;
        }

        return SmsPolicy::minutesUntilWindow($localMinutes, $start, $end);
    }

    // =================================================================
    // Readiness
    // =================================================================

    /**
     * Is this campaign fit to send? Every reason it is not, in one list.
     *
     * ALL of them, not the first — somebody fixing a campaign should not have
     * to press send four times to discover four problems. Each string is a
     * whole sentence naming what to do.
     *
     * @param array<string,mixed> $campaign
     * @param array{total:int,reach:int} $audience
     * @return list<string>
     */
    public static function blockers(array $campaign, array $audience): array
    {
        $blockers = [];
        $channel  = (string) ($campaign['channel'] ?? '');
        $body     = trim((string) ($campaign['body'] ?? ''));

        if (!in_array($channel, self::CHANNELS, true)) {
            $blockers[] = 'Choose whether this goes out by email or as a text message.';
        }

        if (($campaign['segment_id'] ?? null) === null) {
            $blockers[] = 'Choose a segment, so there is somebody to send it to.';
        }

        if ($body === '') {
            $blockers[] = 'Write the message.';
        }

        if ($channel === 'email' && trim((string) ($campaign['subject'] ?? '')) === '') {
            // A bulk email with no subject is filtered as spam by most
            // providers, so this is a deliverability rule rather than a
            // tidiness one.
            $blockers[] = 'Give the email a subject line.';
        }

        if ($channel === 'sms' && $body !== '' && !SmsPolicy::hasOptOut($body)) {
            $blockers[] = 'A bulk text must tell the reader how to stop. Add "Reply STOP to opt out".';
        }

        if ($channel === 'sms' && $body !== '' && SmsPolicy::segments($body) > SmsPolicy::MAX_SEGMENTS) {
            $blockers[] = 'That message is too long to arrive in one piece. Shorten it to '
                . SmsPolicy::MAX_SEGMENTS . ' texts or fewer.';
        }

        if ($audience['reach'] === 0) {
            $blockers[] = $audience['total'] === 0
                ? 'Nobody is in that segment at the moment.'
                : 'Nobody in that segment can be reached on this channel.';
        }

        return $blockers;
    }

    /**
     * How many ticks a send will take, for the page to say so.
     *
     * A venue pressing send on forty thousand recipients should be told it
     * will take a while, rather than watching a progress bar they assume is
     * stuck.
     */
    public static function chunksFor(int $recipients, int $chunk = self::CHUNK): int
    {
        $chunk = max(1, $chunk);

        return (int) ceil(max(0, $recipients) / $chunk);
    }

    /**
     * "About four minutes", for a cron that runs every minute.
     */
    public static function durationLabel(int $recipients, int $chunk = self::CHUNK): string
    {
        $ticks = self::chunksFor($recipients, $chunk);

        if ($ticks <= 1) {
            return 'under a minute';
        }

        if ($ticks < 60) {
            return 'about ' . $ticks . ' minutes';
        }

        $hours = (int) round($ticks / 60);

        return 'about ' . $hours . ($hours === 1 ? ' hour' : ' hours');
    }
}
