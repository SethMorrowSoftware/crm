<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The rules behind feedback and reviews — the pure half.
 *
 * No database, no clock, no mail: every method here answers from its
 * arguments. The reason is the same one `StationRevenue` and `CardWatch` have
 * in the CEplay codebase: the decision that matters on this page is WHICH
 * GUEST GETS ASKED FOR A PUBLIC REVIEW, and a rule that can only be exercised
 * against a live database at nine o'clock on a Saturday night is a rule
 * nobody checks. Everything that decides a figure or a sentence lives here so
 * `tests/unit/ReputationTest.php` can pin it; `ReputationController` and
 * `PublicFeedbackController` do the reading, writing and sending, and decide
 * nothing.
 *
 * The gate is the whole point of the module:
 *
 *   promoter  → thank them, and ask for a public review
 *   passive   → ask what would have made it a ten. No review ask.
 *   detractor → no review ask at all. A manager hears about it instead.
 *
 * `askForReview()` is the one function that answers that, and it is called
 * from exactly one place in the controller, so there is no second path to a
 * review request.
 */
final class Reputation
{
    // ------------------------------------------------------------------
    // Scales
    // ------------------------------------------------------------------

    /** The lowest NPS score that counts as a promoter. */
    public const NPS_PROMOTER = 9;

    /** The lowest NPS score that counts as a passive. Below this: detractor. */
    public const NPS_PASSIVE = 7;

    /** The lowest CSAT star rating that counts as a promoter. */
    public const CSAT_PROMOTER = 4;

    /** The lowest CSAT star rating that counts as a passive. */
    public const CSAT_PASSIVE = 3;

    /** A review of this many stars or fewer is negative. */
    public const REVIEW_NEGATIVE = 2;

    /** A review of this many stars or more is positive. */
    public const REVIEW_POSITIVE = 4;

    public const SENTIMENTS = ['promoter', 'passive', 'detractor'];

    public const PLATFORMS = ['google', 'yelp', 'facebook', 'tripadvisor'];

    /**
     * The range each survey type is answered on.
     *
     * NPS is 0-10 by definition and must not be rescaled — a "1-10 NPS" is a
     * different measure that cannot be compared with anybody else's number.
     *
     * @return array{min:int,max:int,step:int}
     */
    public static function scale(string $type): array
    {
        return match ($type) {
            'csat'  => ['min' => 1, 'max' => 5, 'step' => 1],
            default => ['min' => 0, 'max' => 10, 'step' => 1],
        };
    }

    /** Is this a score the given survey type can actually take? */
    public static function isValidScore(string $type, int $score): bool
    {
        $scale = self::scale($type);

        return $score >= $scale['min'] && $score <= $scale['max'];
    }

    /**
     * Which band a score falls in.
     *
     * Returns null for a score outside the scale rather than guessing, so a
     * tampered form field cannot land somebody in a band by accident. The
     * caller treats null as "do not record this".
     */
    public static function sentiment(string $type, int $score): ?string
    {
        if (!self::isValidScore($type, $score)) {
            return null;
        }

        if ($type === 'csat') {
            return match (true) {
                $score >= self::CSAT_PROMOTER => 'promoter',
                $score >= self::CSAT_PASSIVE  => 'passive',
                default                       => 'detractor',
            };
        }

        return match (true) {
            $score >= self::NPS_PROMOTER => 'promoter',
            $score >= self::NPS_PASSIVE  => 'passive',
            default                      => 'detractor',
        };
    }

    /**
     * Does this guest get asked for a public review?
     *
     * The single gate. Only a promoter is asked, which is spec §8.9 and the
     * behaviour the venue asked for.
     *
     * `$askEveryone` is the venue's own override, off by default. It exists
     * because selectively soliciting only happy guests is against Google's
     * review policy, and a venue that would rather stay on the right side of
     * that can switch it on and ask everybody. Even then a DETRACTOR is never
     * asked: handing somebody a review link in the same breath as "sorry
     * about your visit" is asking for a one-star review, and the recovery
     * conversation has to come first.
     */
    public static function askForReview(string $sentiment, bool $askEveryone = false): bool
    {
        if ($sentiment === 'promoter') {
            return true;
        }

        return $askEveryone && $sentiment === 'passive';
    }

    /** Does this score mean a manager needs to hear about it today? */
    public static function needsEscalation(string $sentiment): bool
    {
        return $sentiment === 'detractor';
    }

    /** The sentiment band for a star rating on a public review. */
    public static function ratingSentiment(int $rating): string
    {
        return match (true) {
            $rating >= self::REVIEW_POSITIVE => 'positive',
            $rating <= self::REVIEW_NEGATIVE => 'negative',
            default                          => 'neutral',
        };
    }

    // ------------------------------------------------------------------
    // What the guest is asked, and what they see next
    // ------------------------------------------------------------------

    /** The one question. */
    public static function question(string $type, string $venueName): string
    {
        $venue = $venueName === '' ? 'us' : $venueName;

        return match ($type) {
            'csat'  => 'How was your visit to ' . $venue . '?',
            default => 'How likely are you to recommend ' . $venue . ' to a friend?',
        };
    }

    /** The labels under the ends of the scale. */
    public static function scaleLabels(string $type): array
    {
        return match ($type) {
            'csat'  => ['low' => 'Poor', 'high' => 'Excellent'],
            default => ['low' => 'Not at all likely', 'high' => 'Extremely likely'],
        };
    }

    /**
     * What the page says once they have answered.
     *
     * Three different pages, and the differences are deliberate. A promoter
     * is thanked and shown one link. A passive is asked an open question. A
     * detractor is told a person will read this and get in touch — which is a
     * promise the module keeps by raising a task, not a platitude.
     *
     * @return array{heading:string,body:string,ask_comment:bool,comment_prompt:?string}
     */
    public static function followUp(string $sentiment, string $venueName): array
    {
        $venue = $venueName === '' ? 'us' : $venueName;

        return match ($sentiment) {
            'promoter' => [
                'heading'        => 'Thank you — that means a lot.',
                'body'           => 'Would you tell other families? It takes a minute and it is'
                    . ' the single most useful thing you could do for ' . $venue . '.',
                'ask_comment'    => true,
                'comment_prompt' => 'Anything you would like us to pass on to the team?',
            ],
            'passive' => [
                'heading'        => 'Thank you — what would have made it a ten?',
                'body'           => 'We would rather hear it than guess. Nobody but the'
                    . ' management team will read this.',
                'ask_comment'    => true,
                'comment_prompt' => 'What would have made it better?',
            ],
            default => [
                'heading'        => 'Thank you for telling us. We are sorry.',
                'body'           => 'A manager will read this today and get in touch. Please tell'
                    . ' us what went wrong so we can put it right.',
                'ask_comment'    => true,
                'comment_prompt' => 'What went wrong?',
            ],
        };
    }

    // ------------------------------------------------------------------
    // Which platform to send a promoter to
    // ------------------------------------------------------------------

    /**
     * Normalise the venue's platform weights.
     *
     * Drops anything not a known platform, anything with no link configured,
     * and anything weighted zero or less. An empty result means "nowhere to
     * send them", which the caller must treat as "do not create a review
     * request" rather than defaulting to Google: a link to a page the venue
     * does not have is worse than no ask at all.
     *
     * @param array<string,mixed> $weights
     * @param array<string,string> $links
     * @return array<string,int>
     */
    public static function platformWeights(array $weights, array $links): array
    {
        $clean = [];

        foreach (self::PLATFORMS as $platform) {
            $weight = (int) ($weights[$platform] ?? 0);
            $link   = trim((string) ($links[$platform] ?? ''));

            if ($weight > 0 && $link !== '') {
                $clean[$platform] = $weight;
            }
        }

        return $clean;
    }

    /**
     * The rotation, as one entry per unit of weight, spread out.
     *
     * Smoothed rather than expanded: weights of google 3, yelp 1 give
     * google, yelp, google, google — not google, google, google, yelp. The
     * difference matters because a venue reads the last few requests to check
     * the rotation is working, and three Googles in a row looks broken even
     * when the proportions over a cycle are exactly right.
     *
     * This is the "smooth weighted round robin" nginx uses: at each position
     * take the platform with the largest shortfall against its share. It is
     * deterministic, so a test can pin the whole cycle, and exact over each
     * cycle rather than approximate like a random draw.
     *
     * @param array<string,int> $weights
     * @return list<string>
     */
    public static function rotation(array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $total  = array_sum($weights);
        $served = array_fill_keys(array_keys($weights), 0);
        $cycle  = [];

        for ($position = 0; $position < $total; $position++) {
            $best      = null;
            $bestShare = null;

            foreach ($weights as $platform => $weight) {
                // How far behind its share this platform is, as a rational
                // compared without division: served/weight, smallest first.
                $share = $served[$platform] / $weight;

                if ($bestShare === null || $share < $bestShare) {
                    $best      = $platform;
                    $bestShare = $share;
                }
            }

            /** @var string $best */
            $cycle[] = $best;
            $served[$best]++;
        }

        return $cycle;
    }

    /**
     * Which platform the next request goes to.
     *
     * `$sequence` is how many review requests the venue has already made, so
     * the rotation survives a restart and does not depend on anything held in
     * memory. A negative sequence (a miscount) is treated as zero rather than
     * throwing a modulo error.
     *
     * @param array<string,int> $weights
     */
    public static function platformFor(array $weights, int $sequence): ?string
    {
        $cycle = self::rotation($weights);

        if ($cycle === []) {
            return null;
        }

        return $cycle[max(0, $sequence) % count($cycle)];
    }

    /** The venue-facing name of a platform. */
    public static function platformName(string $platform): string
    {
        return match ($platform) {
            'google'      => 'Google',
            'yelp'        => 'Yelp',
            'facebook'    => 'Facebook',
            'tripadvisor' => 'Tripadvisor',
            'internal'    => 'Direct feedback',
            default       => ucfirst($platform),
        };
    }

    // ------------------------------------------------------------------
    // Reading the numbers
    // ------------------------------------------------------------------

    /**
     * Net Promoter Score: the percentage of promoters minus the percentage of
     * detractors, on a -100..100 scale.
     *
     * Null when nobody has answered. Deliberately NOT zero: zero is a real
     * NPS (as many detractors as promoters) and a venue with no responses
     * must not be shown one.
     */
    public static function nps(int $promoters, int $passives, int $detractors): ?float
    {
        $total = $promoters + $passives + $detractors;

        if ($total <= 0) {
            return null;
        }

        return round((($promoters - $detractors) / $total) * 100, 1);
    }

    /**
     * Average score, to one decimal. Null for an empty set.
     *
     * @param list<int|float|string|null> $scores
     */
    public static function average(array $scores): ?float
    {
        $values = [];

        foreach ($scores as $score) {
            if ($score !== null && $score !== '') {
                $values[] = (float) $score;
            }
        }

        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 1);
    }

    /**
     * One figure as a percentage of another, to one decimal.
     *
     * Null when the denominator is zero — NOT zero percent. "0% of nobody
     * answered" and "0% of 200 answered" are different facts, and only one of
     * them is a problem.
     */
    public static function rate(int $part, int $whole): ?float
    {
        if ($whole <= 0) {
            return null;
        }

        return round(($part / $whole) * 100, 1);
    }

    /**
     * Summarise a set of answered responses.
     *
     * Takes rows already read from the database — `['score' => int|null,
     * 'sentiment' => string|null]` — so this stays pure and the caller can
     * hand it a query result, a CSV, or a fixture.
     *
     * `answered` counts rows with a sentiment, which is what the NPS is
     * computed over; `invited` is passed in, because the rows that were never
     * answered are usually excluded from the query that produced them and
     * guessing the denominator would understate or overstate the response
     * rate depending on which query the caller used.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{answered:int,promoters:int,passives:int,detractors:int,nps:?float,average:?float,response_rate:?float}
     */
    public static function summarise(array $rows, ?int $invited = null): array
    {
        $counts = ['promoter' => 0, 'passive' => 0, 'detractor' => 0];
        $scores = [];

        foreach ($rows as $row) {
            $sentiment = (string) ($row['sentiment'] ?? '');

            if (!isset($counts[$sentiment])) {
                continue;
            }

            $counts[$sentiment]++;

            if (($row['score'] ?? null) !== null) {
                $scores[] = $row['score'];
            }
        }

        $answered = array_sum($counts);

        return [
            'answered'      => $answered,
            'promoters'     => $counts['promoter'],
            'passives'      => $counts['passive'],
            'detractors'    => $counts['detractor'],
            'nps'           => self::nps($counts['promoter'], $counts['passive'], $counts['detractor']),
            'average'       => self::average($scores),
            'response_rate' => $invited === null ? null : self::rate($answered, $invited),
        ];
    }

    /**
     * The conversion rate of a set of review requests.
     *
     * Three figures, because they answer three different questions and a
     * single "conversion" number hides which half is broken: whether the ask
     * is being seen (clicked), whether it turns into a review (reviewed), and
     * the clicked-to-reviewed step in between.
     *
     * @param array<string,int> $byStatus status => count
     * @return array{sent:int,clicked:int,reviewed:int,click_rate:?float,review_rate:?float,of_clicks:?float}
     */
    public static function requestFunnel(array $byStatus): array
    {
        // Every status past "pending" was sent, so each later stage counts
        // toward the earlier ones. Counting only the rows PARKED at a status
        // would report a request that already turned into a review as never
        // having been clicked.
        $reviewed = (int) ($byStatus['reviewed'] ?? 0);
        $clicked  = (int) ($byStatus['clicked'] ?? 0) + $reviewed;
        $sent     = (int) ($byStatus['sent'] ?? 0) + (int) ($byStatus['declined'] ?? 0) + $clicked;

        return [
            'sent'        => $sent,
            'clicked'     => $clicked,
            'reviewed'    => $reviewed,
            'click_rate'  => self::rate($clicked, $sent),
            'review_rate' => self::rate($reviewed, $sent),
            'of_clicks'   => self::rate($reviewed, $clicked),
        ];
    }

    // ------------------------------------------------------------------
    // Timing
    // ------------------------------------------------------------------

    /**
     * When a survey invitation becomes due, given the moment it is about.
     *
     * Plain hour arithmetic on a UTC stamp, which is correct here BECAUSE it
     * is UTC: adding seconds to a wall-clock time is what breaks across a DST
     * change, and nothing in this calculation touches a local clock. The
     * quiet-hours window that decides whether the message actually goes at
     * that hour is enforced by `Sms::send()` / `Mailer::send()`, in the
     * venue's own zone, where it belongs.
     */
    public static function dueAt(string $momentUtc, int $delayHours): ?string
    {
        $time = strtotime($momentUtc . ' UTC');

        if ($time === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $time + max(0, $delayHours) * 3600);
    }

    /** When a detractor must have been dealt with by. */
    public static function slaDueAt(string $createdUtc, int $slaHours): ?string
    {
        return self::dueAt($createdUtc, max(1, $slaHours));
    }

    /**
     * Is this piece of feedback past its deadline?
     *
     * Resolved feedback is never overdue, however long it took — the question
     * this answers is "what needs attention now", and the time it TOOK is
     * `resolutionHours()` below.
     *
     * @param array<string,mixed> $response
     */
    public static function isOverdue(array $response, int $slaHours, string $nowUtc): bool
    {
        if (($response['resolved_at'] ?? null) !== null) {
            return false;
        }

        if ((string) ($response['sentiment'] ?? '') !== 'detractor') {
            return false;
        }

        $due = self::slaDueAt((string) ($response['created_at'] ?? ''), $slaHours);

        return $due !== null && $due < $nowUtc;
    }

    /** How long a resolved piece of feedback took, in hours. Null if open. */
    public static function resolutionHours(?string $createdUtc, ?string $resolvedUtc): ?float
    {
        if ($createdUtc === null || $resolvedUtc === null) {
            return null;
        }

        $from = strtotime($createdUtc . ' UTC');
        $to   = strtotime($resolvedUtc . ' UTC');

        if ($from === false || $to === false || $to < $from) {
            return null;
        }

        return round(($to - $from) / 3600, 1);
    }

    // ------------------------------------------------------------------
    // Importing reviews
    // ------------------------------------------------------------------

    /**
     * Map a CSV of reviews onto review rows.
     *
     * The CSV fallback exists because the Google Business Profile API needs
     * OAuth and a verified listing, and Yelp does not expose reviews at all —
     * so for most venues, most of the time, a CSV export IS the integration.
     * Phase 7 adds the API; this is not a placeholder for it.
     *
     * Header names are matched loosely (case and punctuation ignored) because
     * every platform exports a different spelling of the same six columns.
     * Every row that cannot be used is REPORTED rather than dropped: an
     * import that silently skipped half a file would leave a venue reading a
     * rating trend computed from whatever happened to parse.
     *
     * @param list<list<string>> $rows the raw CSV, header row first
     * @return array{reviews:list<array<string,mixed>>,problems:list<string>,columns:array<string,int>}
     */
    public static function parseReviewCsv(array $rows, string $defaultPlatform = 'google'): array
    {
        if ($rows === []) {
            return ['reviews' => [], 'problems' => ['The file is empty.'], 'columns' => []];
        }

        $header  = array_shift($rows);
        $columns = self::mapColumns($header);

        if (!isset($columns['rating'])) {
            return [
                'reviews'  => [],
                'problems' => ['No rating column. The file needs a column called "rating" or "stars".'],
                'columns'  => $columns,
            ];
        }

        $reviews  = [];
        $problems = [];
        $line     = 1;

        foreach ($rows as $row) {
            $line++;

            // A trailing blank line is not a problem worth reporting.
            if ($row === [] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }

            $get = static function (string $key) use ($row, $columns): string {
                $index = $columns[$key] ?? null;

                return $index === null ? '' : trim((string) ($row[$index] ?? ''));
            };

            $ratingRaw = $get('rating');

            if ($ratingRaw === '' || !is_numeric($ratingRaw)) {
                $problems[] = 'Line ' . $line . ': the rating "' . mb_substr($ratingRaw, 0, 20)
                    . '" is not a number.';
                continue;
            }

            $rating = (int) round((float) $ratingRaw);

            if ($rating < 1 || $rating > 5) {
                $problems[] = 'Line ' . $line . ': a rating of ' . $rating . ' is not between 1 and 5.';
                continue;
            }

            $platform = strtolower($get('platform'));

            if (!in_array($platform, self::PLATFORMS, true)) {
                $platform = $defaultPlatform;
            }

            $posted = self::parseDate($get('posted_at'));

            $reviews[] = [
                'platform'    => $platform,
                'external_id' => $get('external_id') === '' ? null : mb_substr($get('external_id'), 0, 191),
                'author_name' => $get('author') === '' ? null : mb_substr($get('author'), 0, 120),
                'rating'      => $rating,
                'body'        => $get('body') === '' ? null : $get('body'),
                'review_url'  => self::safeUrl($get('url')),
                'sentiment'   => self::ratingSentiment($rating),
                'posted_at'   => $posted,
            ];
        }

        if ($reviews === [] && $problems === []) {
            $problems[] = 'The file has a header but no rows.';
        }

        return ['reviews' => $reviews, 'problems' => $problems, 'columns' => $columns];
    }

    /**
     * Which column holds what.
     *
     * @param list<string> $header
     * @return array<string,int>
     */
    private static function mapColumns(array $header): array
    {
        $aliases = [
            'platform'    => ['platform', 'source', 'site', 'network'],
            'author'      => ['author', 'authorname', 'name', 'reviewer', 'reviewername', 'customer'],
            'rating'      => ['rating', 'stars', 'starrating', 'score', 'reviewrating'],
            'body'        => ['body', 'review', 'reviewtext', 'comment', 'text', 'content'],
            'url'         => ['url', 'reviewurl', 'link', 'permalink'],
            'posted_at'   => ['postedat', 'date', 'reviewdate', 'created', 'createdat', 'time', 'datetime'],
            'external_id' => ['externalid', 'id', 'reviewid'],
        ];

        $columns = [];

        foreach ($header as $index => $label) {
            // Fold case and drop everything that is not a letter or a digit,
            // so "Star Rating", "star_rating" and "STARRATING" all match.
            $key = preg_replace('/[^a-z0-9]/', '', strtolower((string) $label)) ?? '';

            if ($key === '') {
                continue;
            }

            foreach ($aliases as $field => $names) {
                if (isset($columns[$field])) {
                    continue;
                }

                if (in_array($key, $names, true)) {
                    $columns[$field] = (int) $index;
                    break;
                }
            }
        }

        return $columns;
    }

    /**
     * A date from a CSV, as a UTC stamp, or null.
     *
     * Deliberately strict about the result and forgiving about the input: an
     * unparseable date means "we do not know when this was posted", which is
     * honest, where guessing today would put an old review at the top of the
     * trend.
     */
    private static function parseDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $time = strtotime($value);

        if ($time === false) {
            return null;
        }

        // A review from the future is a parse that went wrong (two-digit
        // years are the usual cause), not a real date.
        if ($time > time() + 86400) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $time);
    }

    /** An http(s) URL, or null. Anything else is not a link we will render. */
    private static function safeUrl(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        return mb_substr($value, 0, 500);
    }
}
