<?php

declare(strict_types=1);

/**
 * The feedback rules, with no database, no network and no clock.
 *
 * THE GATE IS THE WHOLE TEST. One function decides whether a guest is handed
 * a link to a public review site, and getting it wrong is not a cosmetic bug
 * in either direction:
 *
 *   - ask an unhappy guest, and the venue has invited a one-star review and
 *     paid for the privilege;
 *   - fail to ask a happy one, and the feature does nothing at all while
 *     appearing to work.
 *
 * The band boundaries are where it goes wrong, so every boundary is pinned:
 * 9 is a promoter and 8 is not; 7 is a passive and 6 is not; 4 stars are a
 * promoter and 3 are not. Those numbers are the standard NPS definition and
 * a venue comparing its score with anybody else's depends on them.
 *
 * The ROTATION is the other half. A venue reads the last few asks to check it
 * is working, so three Googles in a row looks broken even when the
 * proportions over a cycle are right — which is why it is smoothed, and why
 * the exact cycle is asserted here rather than just the proportions.
 */

use App\Services\Reputation as R;
use Tests\Assert;
use Tests\Harness;

Harness::suite('unit/reputation');

// ---------------------------------------------------------------- the scales

Harness::test('NPS is 0 to 10 and CSAT is 1 to 5', function (): void {
    Assert::same(['min' => 0, 'max' => 10, 'step' => 1], R::scale('nps'));
    Assert::same(['min' => 1, 'max' => 5, 'step' => 1], R::scale('csat'));

    // An unknown type falls back to NPS rather than to an empty scale, so a
    // survey row with a type nobody recognises still renders a question.
    Assert::same(['min' => 0, 'max' => 10, 'step' => 1], R::scale('something-else'));
});

Harness::test('a score outside the scale is not a score', function (): void {
    Assert::true(R::isValidScore('nps', 0), 'zero is a real NPS answer');
    Assert::true(R::isValidScore('nps', 10));
    Assert::false(R::isValidScore('nps', 11));
    Assert::false(R::isValidScore('nps', -1));

    // One off each end of the CSAT scale, and ZERO in particular: it is a
    // valid NPS answer and not a valid star rating, so a form posting 0 to a
    // CSAT survey must not land in a band.
    Assert::false(R::isValidScore('csat', 0));
    Assert::true(R::isValidScore('csat', 1));
    Assert::true(R::isValidScore('csat', 5));
    Assert::false(R::isValidScore('csat', 6));

    Assert::null(R::sentiment('csat', 0), 'and it gets no sentiment either');
    Assert::null(R::sentiment('nps', 11));
});

// ----------------------------------------------------------------- the bands

Harness::test('THE NPS BANDS, AT EVERY BOUNDARY', function (): void {
    $expected = [
        0  => 'detractor',
        1  => 'detractor',
        5  => 'detractor',
        6  => 'detractor',
        7  => 'passive',
        8  => 'passive',
        9  => 'promoter',
        10 => 'promoter',
    ];

    foreach ($expected as $score => $band) {
        Assert::same($band, R::sentiment('nps', $score), $score . ' should be a ' . $band);
    }
});

Harness::test('the CSAT bands, at every boundary', function (): void {
    Assert::same('detractor', R::sentiment('csat', 1));
    Assert::same('detractor', R::sentiment('csat', 2));
    Assert::same('passive', R::sentiment('csat', 3));
    Assert::same('promoter', R::sentiment('csat', 4));
    Assert::same('promoter', R::sentiment('csat', 5));
});

// ------------------------------------------------------------------ the gate

Harness::test('ONLY A PROMOTER IS ASKED FOR A PUBLIC REVIEW', function (): void {
    Assert::true(R::askForReview('promoter'));
    Assert::false(R::askForReview('passive'));
    Assert::false(R::askForReview('detractor'));
});

Harness::test('the ask-everyone override reaches passives and NEVER detractors', function (): void {
    /*
     * The override exists because soliciting reviews only from happy
     * customers is against Google's review policy, so a venue may choose to
     * widen it. It must not widen to an unhappy guest: handing somebody a
     * review link in the same breath as "sorry about your visit" invites the
     * review nobody wants, and the recovery conversation has to come first.
     */
    Assert::true(R::askForReview('promoter', true));
    Assert::true(R::askForReview('passive', true));
    Assert::false(R::askForReview('detractor', true), 'not even with the override on');
});

Harness::test('only a low score escalates', function (): void {
    Assert::true(R::needsEscalation('detractor'));
    Assert::false(R::needsEscalation('passive'));
    Assert::false(R::needsEscalation('promoter'));
});

Harness::test('a review rating has its own three bands', function (): void {
    Assert::same('negative', R::ratingSentiment(1));
    Assert::same('negative', R::ratingSentiment(2));
    Assert::same('neutral', R::ratingSentiment(3));
    Assert::same('positive', R::ratingSentiment(4));
    Assert::same('positive', R::ratingSentiment(5));
});

// -------------------------------------------------------------- the rotation

Harness::test('a platform with no link configured is never in the rotation', function (): void {
    $weights = ['google' => 3, 'yelp' => 2, 'facebook' => 1];
    $links   = ['google' => 'https://g.example', 'yelp' => '', 'facebook' => 'https://f.example'];

    // Yelp is weighted but has no link, so it is dropped: a link to a listing
    // the venue does not have is worse than no ask at all.
    Assert::same(['google' => 3, 'facebook' => 1], R::platformWeights($weights, $links));
});

Harness::test('a zero weight is not in the rotation either', function (): void {
    $links = ['google' => 'https://g.example', 'tripadvisor' => 'https://t.example'];

    Assert::same(
        ['google' => 1],
        R::platformWeights(['google' => 1, 'tripadvisor' => 0], $links)
    );
});

Harness::test('nothing configured means nobody is asked', function (): void {
    Assert::same([], R::platformWeights(['google' => 3], ['google' => '']));
    Assert::same([], R::rotation([]));
    Assert::null(R::platformFor([], 0), 'and no platform is picked');
});

Harness::test('THE ROTATION IS SMOOTHED, NOT EXPANDED', function (): void {
    /*
     * Three Googles for every Yelp, spread out. An expansion would give
     * google, google, google, yelp — which is proportionally correct and
     * looks broken to anybody checking the last few asks.
     */
    Assert::same(
        ['google', 'yelp', 'google', 'google'],
        R::rotation(['google' => 3, 'yelp' => 1])
    );

    Assert::same(
        ['google', 'yelp', 'google', 'yelp'],
        R::rotation(['google' => 2, 'yelp' => 2])
    );

    Assert::same(['google'], R::rotation(['google' => 1]));
});

Harness::test('the rotation is exact over a cycle', function (): void {
    $weights = ['google' => 5, 'yelp' => 3, 'facebook' => 2];
    $cycle   = R::rotation($weights);

    Assert::count(10, $cycle, 'one entry per unit of weight');

    $counts = array_count_values($cycle);

    Assert::same(5, $counts['google']);
    Assert::same(3, $counts['yelp']);
    Assert::same(2, $counts['facebook']);
});

Harness::test('the sequence number wraps, and a bad one does not throw', function (): void {
    $weights = ['google' => 3, 'yelp' => 1];

    Assert::same('google', R::platformFor($weights, 0));
    Assert::same('yelp', R::platformFor($weights, 1));
    Assert::same('google', R::platformFor($weights, 4), 'position 4 is position 0 again');

    // A miscounted sequence is treated as the start rather than producing a
    // negative modulo, which in PHP is a negative index and an undefined one.
    Assert::same('google', R::platformFor($weights, -7));
});

// ------------------------------------------------------------------ the maths

Harness::test('NPS is promoters minus detractors, as percentages', function (): void {
    Assert::same(100.0, R::nps(10, 0, 0));
    Assert::same(-100.0, R::nps(0, 0, 10));
    Assert::same(0.0, R::nps(5, 0, 5), 'as many unhappy as happy really is zero');
    Assert::same(0.0, R::nps(0, 10, 0), 'all passives is also zero');
    Assert::same(40.0, R::nps(6, 2, 2), '60% promoters less 20% detractors');
    Assert::same(50.0, R::nps(6, 3, 1));
    Assert::same(25.0, R::nps(2, 1, 1));
    // Rounded to one decimal, because thirds happen: 2 of 3 promoters and
    // one detractor is 33.333…
    Assert::same(33.3, R::nps(2, 0, 1));
});

Harness::test('NPS WITH NO ANSWERS IS NULL, NOT ZERO', function (): void {
    // Zero is a real score — as many unhappy guests as happy ones. A venue
    // nobody has answered must not be shown one.
    Assert::null(R::nps(0, 0, 0));
    Assert::notSame(0.0, R::nps(0, 0, 0));
});

Harness::test('a rate with no denominator is null, not zero per cent', function (): void {
    Assert::null(R::rate(0, 0));
    Assert::same(0.0, R::rate(0, 200), 'nobody answered out of 200 IS zero per cent');
    Assert::same(50.0, R::rate(1, 2));
    Assert::same(33.3, R::rate(1, 3));
});

Harness::test('an average ignores the gaps and an empty set has none', function (): void {
    Assert::same(8.0, R::average([9, 7, 8]));
    Assert::same(8.0, R::average([9, null, 7, '', 8]));
    Assert::null(R::average([]));
    Assert::null(R::average([null, '']));
});

Harness::test('summarising counts the bands and ignores unanswered rows', function (): void {
    $rows = [
        ['sentiment' => 'promoter', 'score' => 10],
        ['sentiment' => 'promoter', 'score' => 9],
        ['sentiment' => 'passive', 'score' => 7],
        ['sentiment' => 'detractor', 'score' => 2],
        // Invited and never answered: present in the table, not an answer.
        ['sentiment' => null, 'score' => null],
        ['sentiment' => 'nonsense', 'score' => 4],
    ];

    $summary = R::summarise($rows, 20);

    Assert::same(4, $summary['answered']);
    Assert::same(2, $summary['promoters']);
    Assert::same(1, $summary['passives']);
    Assert::same(1, $summary['detractors']);
    Assert::same(25.0, $summary['nps']);
    Assert::same(7.0, $summary['average']);
    Assert::same(20.0, $summary['response_rate'], '4 of 20');
});

Harness::test('the response rate is unknown rather than zero when nobody was invited', function (): void {
    $summary = R::summarise([['sentiment' => 'promoter', 'score' => 10]]);

    Assert::null($summary['response_rate']);
    Assert::same(1, $summary['answered']);
});

Harness::test('THE REQUEST FUNNEL COUNTS LATER STAGES TOWARD EARLIER ONES', function (): void {
    /*
     * A request that turned into a review was also clicked and also sent.
     * Counting only the rows PARKED at each status would report the same
     * request as never having been clicked, and a venue whose asks all
     * convert would read 0% click-through.
     */
    $funnel = R::requestFunnel(['sent' => 10, 'clicked' => 4, 'reviewed' => 2, 'declined' => 1]);

    Assert::same(17, $funnel['sent'], '10 + 1 declined + 4 clicked + 2 reviewed');
    Assert::same(6, $funnel['clicked'], '4 + the 2 that became reviews');
    Assert::same(2, $funnel['reviewed']);
    Assert::same(35.3, $funnel['click_rate']);
    Assert::same(33.3, $funnel['of_clicks'], '2 of the 6 who clicked');
});

Harness::test('an empty funnel reports nothing rather than nought per cent', function (): void {
    $funnel = R::requestFunnel([]);

    Assert::same(0, $funnel['sent']);
    Assert::null($funnel['click_rate']);
    Assert::null($funnel['review_rate']);
    Assert::null($funnel['of_clicks']);
});

// ------------------------------------------------------------------- the time

Harness::test('the invitation is due a set number of hours after the visit', function (): void {
    Assert::same('2026-07-16 10:00:00', R::dueAt('2026-07-15 14:00:00', 20));
    Assert::same('2026-07-15 14:00:00', R::dueAt('2026-07-15 14:00:00', 0));

    // A negative delay is treated as none rather than scheduling the question
    // before the party.
    Assert::same('2026-07-15 14:00:00', R::dueAt('2026-07-15 14:00:00', -5));

    Assert::null(R::dueAt('not a date', 20));
});

Harness::test('a complaint has a deadline of at least an hour', function (): void {
    Assert::same('2026-07-15 18:00:00', R::slaDueAt('2026-07-15 14:00:00', 4));

    // Zero hours would mean every complaint is overdue the moment it arrives,
    // which is a way of saying nothing.
    Assert::same('2026-07-15 15:00:00', R::slaDueAt('2026-07-15 14:00:00', 0));
});

Harness::test('only an unresolved complaint can be overdue', function (): void {
    $row = [
        'sentiment'   => 'detractor',
        'created_at'  => '2026-07-15 14:00:00',
        'resolved_at' => null,
    ];

    Assert::true(R::isOverdue($row, 4, '2026-07-15 18:00:01'));
    Assert::false(R::isOverdue($row, 4, '2026-07-15 17:59:00'));

    // Resolved is never overdue, however long it took: this answers "what
    // needs attention now".
    Assert::false(R::isOverdue(
        array_merge($row, ['resolved_at' => '2026-07-20 00:00:00']),
        4,
        '2026-08-01 00:00:00'
    ));

    // A happy guest has no deadline to miss.
    Assert::false(R::isOverdue(
        array_merge($row, ['sentiment' => 'promoter']),
        4,
        '2026-08-01 00:00:00'
    ));
});

Harness::test('resolution time is hours, and unknown while it is open', function (): void {
    Assert::same(4.0, R::resolutionHours('2026-07-15 14:00:00', '2026-07-15 18:00:00'));
    Assert::same(0.5, R::resolutionHours('2026-07-15 14:00:00', '2026-07-15 14:30:00'));
    Assert::null(R::resolutionHours('2026-07-15 14:00:00', null));
    Assert::null(R::resolutionHours(null, '2026-07-15 18:00:00'));

    // Resolved before it was answered is a clock problem, not a negative
    // duration to average into the figure.
    Assert::null(R::resolutionHours('2026-07-15 18:00:00', '2026-07-15 14:00:00'));
});

// ------------------------------------------------------------- the CSV import

Harness::test('a review CSV is mapped however the platform spelled its headers', function (): void {
    $csv = [
        ['Reviewer Name', 'Star Rating', 'Review Text', 'Date', 'Permalink'],
        ['Dana P.', '5', 'Best party we have had', '2026-09-14', 'https://g.example/r/1'],
        ['Sam T.', '2', 'Waited an hour for the karts', '2026-09-15', ''],
    ];

    $parsed = R::parseReviewCsv($csv, 'google');

    Assert::count(2, $parsed['reviews']);
    Assert::same([], $parsed['problems']);

    Assert::same('Dana P.', $parsed['reviews'][0]['author_name']);
    Assert::same(5, $parsed['reviews'][0]['rating']);
    Assert::same('positive', $parsed['reviews'][0]['sentiment']);
    Assert::same('google', $parsed['reviews'][0]['platform']);
    Assert::same('2026-09-14 00:00:00', $parsed['reviews'][0]['posted_at']);
    Assert::same('https://g.example/r/1', $parsed['reviews'][0]['review_url']);

    Assert::same('negative', $parsed['reviews'][1]['sentiment']);
    Assert::null($parsed['reviews'][1]['review_url'], 'an empty cell is not a link');
});

Harness::test('EVERY UNUSABLE ROW IS REPORTED, NOT DROPPED', function (): void {
    /*
     * An import that silently skipped half a file would leave a venue reading
     * a rating trend computed from whatever happened to parse, with nothing
     * on the page to say so.
     */
    $csv = [
        ['author', 'rating', 'body'],
        ['Fine', '4', 'ok'],
        ['No rating', '', 'nothing to go on'],
        ['Words', 'five stars', 'not a number'],
        ['Out of range', '9', 'nine stars'],
        [''],
    ];

    $parsed = R::parseReviewCsv($csv);

    Assert::count(1, $parsed['reviews']);
    Assert::count(3, $parsed['problems']);
    Assert::contains('Line 3', $parsed['problems'][0]);
    Assert::contains('Line 4', $parsed['problems'][1]);
    Assert::contains('not between 1 and 5', $parsed['problems'][2]);
});

Harness::test('a file with no rating column is refused with the reason', function (): void {
    $parsed = R::parseReviewCsv([['author', 'body'], ['Dana', 'lovely']]);

    Assert::same([], $parsed['reviews']);
    Assert::contains('No rating column', $parsed['problems'][0]);
});

Harness::test('an empty file and a header-only file say which they are', function (): void {
    Assert::contains('empty', R::parseReviewCsv([])['problems'][0]);
    Assert::contains('no rows', R::parseReviewCsv([['author', 'rating']])['problems'][0]);
});

Harness::test('an unparseable date is unknown rather than today', function (): void {
    $parsed = R::parseReviewCsv([
        ['author', 'rating', 'date'],
        ['Dana', '5', 'last Tuesday-ish'],
        ['Sam', '5', '2099-01-01'],
    ]);

    // Guessing today would put an old review at the top of the trend; a
    // future date is a parse that went wrong, not a real posting date.
    Assert::null($parsed['reviews'][0]['posted_at']);
    Assert::null($parsed['reviews'][1]['posted_at']);
});

Harness::test('a non-http link is not a link', function (): void {
    $parsed = R::parseReviewCsv([
        ['author', 'rating', 'url'],
        ['Dana', '5', 'javascript:alert(1)'],
        ['Sam', '5', 'file:///etc/passwd'],
        ['Kit', '5', 'https://ok.example'],
    ]);

    Assert::null($parsed['reviews'][0]['review_url']);
    Assert::null($parsed['reviews'][1]['review_url']);
    Assert::same('https://ok.example', $parsed['reviews'][2]['review_url']);
});

Harness::test("a row's own platform wins, and an unknown one falls back", function (): void {
    $parsed = R::parseReviewCsv([
        ['author', 'rating', 'platform'],
        ['Dana', '5', 'yelp'],
        ['Sam', '5', 'MySpace'],
    ], 'facebook');

    Assert::same('yelp', $parsed['reviews'][0]['platform']);
    Assert::same('facebook', $parsed['reviews'][1]['platform']);
});

// ------------------------------------------------------------------ the words

Harness::test('the question names the venue, and copes when it has no name', function (): void {
    Assert::contains('The Castle Fun Center', R::question('nps', 'The Castle Fun Center'));
    Assert::contains('recommend', R::question('nps', 'X'));
    Assert::contains('How was your visit', R::question('csat', 'X'));

    // An install that has not set a venue name must not ask "recommend  to a
    // friend" with a hole in it.
    Assert::contains('recommend us', R::question('nps', ''));
});

Harness::test('A DETRACTOR IS NEVER SHOWN A REVIEW ASK IN THE WORDING EITHER', function (): void {
    $detractor = R::followUp('detractor', 'The Castle');

    Assert::contains('sorry', strtolower($detractor['heading'] . ' ' . $detractor['body']));
    Assert::notContains('review', strtolower($detractor['heading'] . ' ' . $detractor['body']));
    Assert::true($detractor['ask_comment'], 'they are asked what went wrong');

    $passive = R::followUp('passive', 'The Castle');
    Assert::notContains('review', strtolower($passive['heading'] . ' ' . $passive['body']));

    $promoter = R::followUp('promoter', 'The Castle');
    Assert::contains('The Castle', $promoter['body']);
});

Harness::test('platform names are the ones guests recognise', function (): void {
    Assert::same('Google', R::platformName('google'));
    Assert::same('Tripadvisor', R::platformName('tripadvisor'));
    Assert::same('Direct feedback', R::platformName('internal'));
    Assert::same('Somewhere', R::platformName('somewhere'), 'an unknown one is still printable');
});
