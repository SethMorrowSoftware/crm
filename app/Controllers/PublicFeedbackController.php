<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Event;
use App\Core\Log;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\Feedback;
use App\Services\Reputation;

/**
 * The guest's side of feedback: one question, no account, no app.
 *
 * Four URLs, all public:
 *
 *   GET  /s/{token}   the question
 *   POST /s/{token}   their answer, and whatever comes next
 *   GET  /r/{token}   a review ask: record the click, then send them on
 *   GET  /reviews     the review wall, for embedding on the venue's site
 *
 * What this class does NOT do is decide anything. The score becomes a
 * sentiment in `Services\Reputation`, and the sentiment becomes an escalation
 * or a review ask in `Feedback::record()`. Both are tested
 * without a browser; this is HTTP, a token lookup and a view.
 *
 * Every page here is `noindex` and uncached, for the reason
 * `PublicController::unindexed()` states: the token in the path is a bearer
 * credential, and a shared proxy that cached one guest's page would serve it
 * to the next visitor.
 */
final class PublicFeedbackController extends Controller
{
    /** A comment longer than this is almost certainly a paste or a bot. */
    private const MAX_COMMENT = 4000;

    /**
     * The one question.
     */
    public function survey(Request $request): Response
    {
        $row = $this->findByToken((string) $request->route('token', ''));

        if ($row === null) {
            return $this->dead('That feedback link has expired or was mistyped.');
        }

        $type      = (string) ($row['survey_type'] ?? 'nps');
        $sentiment = $row['sentiment'] === null ? null : (string) $row['sentiment'];

        // Already answered: show them what comes after their score rather
        // than the question again. A parent who clicks the link in their
        // email a second time should see the thank-you, not a blank form
        // that implies their answer was lost.
        if ($sentiment !== null) {
            return $this->thanks($row, $sentiment, null);
        }

        return $this->page('public/survey', [
            'pageTitle' => 'How did we do?',
            'token'     => (string) $row['public_token'],
            'question'  => Reputation::question($type, Settings::venueName()),
            'scale'     => Reputation::scale($type),
            'labels'    => Reputation::scaleLabels($type),
            'type'      => $type,
            'firstName' => trim((string) ($row['first_name'] ?? '')),
            'venueName' => Settings::venueName(),
        ]);
    }

    /**
     * Their answer.
     *
     * The score may arrive on its own (they tapped a number) or with a
     * comment (they came back and wrote something on the follow-up page), and
     * both go through `record()`, which handles the second submission by
     * keeping the first score and taking the new comment.
     */
    public function submit(Request $request): Response
    {
        $row = $this->findByToken((string) $request->route('token', ''));

        if ($row === null) {
            return $this->dead('That feedback link has expired or was mistyped.');
        }

        $type    = (string) ($row['survey_type'] ?? 'nps');
        $comment = trim($request->str('comment'));
        $comment = $comment === '' ? null : mb_substr($comment, 0, self::MAX_COMMENT);

        // A comment-only submission from the follow-up page: there is no score
        // field to read, and the score is already recorded.
        if (!$request->has('score') && $row['sentiment'] !== null) {
            Feedback::record($row, (int) $row['score'], $comment);

            return $this->thanks($row, (string) $row['sentiment'], $comment, true);
        }

        $score = $request->int('score', -1);

        if (!Reputation::isValidScore($type, $score)) {
            flash('error', 'Please choose a number.');

            return $this->redirect('/s/' . (string) $row['public_token']);
        }

        $outcome = Feedback::record($row, $score, $comment);

        if ($outcome['sentiment'] === null) {
            // Only reachable if the scale and the validation disagree, which
            // would be a bug rather than a guest's doing — so it says so
            // plainly instead of blaming them.
            Log::warning('A survey score passed validation and then failed the gate', [
                'response' => (int) $row['id'],
                'score'    => $score,
            ]);

            return $this->dead('Something went wrong recording that. Please tell us at the front desk.');
        }

        return $this->thanks($row, $outcome['sentiment'], $comment, false, $outcome['review']);
    }

    /**
     * A promoter clicking through to leave a public review.
     *
     * Records the click, then redirects. The redirect target is the venue's
     * OWN configured link for that platform — never anything from the
     * request — so this cannot be turned into an open redirect by editing the
     * URL, which is the one thing a tokenised redirect must not allow.
     */
    public function reviewClick(Request $request): Response
    {
        $token = (string) $request->route('token', '');

        $ask = DB::first(
            'SELECT * FROM {review_requests} WHERE token = :t',
            ['t' => $token]
        );

        if ($ask === null) {
            return $this->dead('That link has expired or was mistyped.');
        }

        $platform = (string) $ask['platform'];
        $target   = trim((string) Settings::get('reputation.link_' . $platform, ''));

        // First click only, so the figures are "how many guests clicked", not
        // "how many times a link was followed" — which a mail client
        // prefetching would inflate on its own.
        if ($ask['clicked_at'] === null) {
            DB::update('review_requests', [
                'status'     => 'clicked',
                'clicked_at' => now_utc(),
            ], 'id = :id AND clicked_at IS NULL', ['id' => (int) $ask['id']]);

            /*
             * NO EVENT. There is no catalogue entry for "clicked through to
             * leave a review", and adding one to fire here would mean
             * offering a trigger nobody has a use for — a venue cannot
             * usefully message somebody in the two seconds between this
             * redirect and the review form. The click is recorded on the
             * request, which is where the funnel reads it.
             */
        }

        if ($target === '' || preg_match('~^https?://~i', $target) !== 1) {
            // The venue removed the link after the ask went out. Say so
            // rather than redirecting nowhere.
            return $this->page('public/review-gone', [
                'pageTitle' => 'Thank you',
                'platform'  => Reputation::platformName($platform),
                'venueName' => Settings::venueName(),
            ]);
        }

        return $this->noindex(Response::redirect($target));
    }

    /**
     * The public review wall.
     *
     * Two forms from one route: the page itself, and `?embed=1`, which drops
     * the surrounding card so the venue's site can put it in an iframe. Same
     * data, same markup, no second template to keep in step.
     */
    public function wall(Request $request): Response
    {
        $reviews = Feedback::wallReviews($request->int('limit', 12));
        $embed   = $request->bool('embed');

        $ratings = array_map(static fn (array $r): int => (int) $r['rating'], $reviews);

        $response = $this->view('public/review-wall', [
            'pageTitle' => 'What our guests say',
            'reviews'   => $reviews,
            'average'   => Reputation::average($ratings),
            'count'     => count($reviews),
            'embed'     => $embed,
            'venueName' => Settings::venueName(),
        ], $embed ? 'layouts/embed' : 'layouts/bare');

        /*
         * The wall is the one page here that SHOULD be indexed and cached:
         * it carries no token, it is the same for every visitor, and the
         * venue wants it found. Framing is allowed deliberately — the whole
         * point is to embed it — so this page and only this page sets a
         * permissive frame-ancestors.
         */
        return $response
            ->header('Cache-Control', 'public, max-age=600')
            ->header('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "font-src 'self'",
                'frame-ancestors *',
                "base-uri 'self'",
                "form-action 'self'",
                "object-src 'none'",
            ]))
            // Absent, not relaxed: there is no X-Frame-Options value that
            // permits framing by another site. See Response::removeHeader().
            ->removeHeader('X-Frame-Options');
    }

    // =================================================================
    // Plumbing
    // =================================================================

    /**
     * The thank-you page, which is a different page per band.
     *
     * @param array<string,mixed> $row
     * @param array{platform:string,url:string}|null $review
     */
    private function thanks(
        array $row,
        string $sentiment,
        ?string $comment,
        bool $commentJustLeft = false,
        ?array $review = null
    ): Response {
        $venue    = Settings::venueName();
        $followUp = Reputation::followUp($sentiment, $venue);

        // A returning visitor's pending ask, so a promoter who closed the tab
        // and came back still gets their link rather than a bare thank-you.
        if ($review === null && $sentiment === 'promoter') {
            $existing = DB::first(
                "SELECT platform, token FROM {review_requests}
                  WHERE survey_response_id = :id AND status IN ('pending','sent','clicked')
                  ORDER BY id DESC LIMIT 1",
                ['id' => (int) $row['id']]
            );

            if ($existing !== null) {
                $review = [
                    'platform' => (string) $existing['platform'],
                    'url'      => url('/r/' . (string) $existing['token']),
                ];
            }
        }

        return $this->page('public/survey-thanks', [
            'pageTitle'       => 'Thank you',
            'sentiment'       => $sentiment,
            'followUp'        => $followUp,
            'review'          => $review,
            'platformName'   => $review === null ? null : Reputation::platformName($review['platform']),
            'token'           => (string) $row['public_token'],
            'firstName'       => trim((string) ($row['first_name'] ?? '')),
            'venueName'       => $venue,
            'comment'         => $comment ?? ($row['comment'] === null ? null : (string) $row['comment']),
            'commentJustLeft' => $commentJustLeft,
        ]);
    }

    /** @return array<string,mixed>|null */
    private function findByToken(string $token): ?array
    {
        // A token is 32 hex characters. Checking the shape before the query
        // keeps a long junk path out of the database entirely.
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }

        return DB::first(
            'SELECT r.*, s.type AS survey_type, s.name AS survey_name,
                    c.first_name, c.last_name
               FROM {survey_responses} r
               JOIN {surveys} s ON s.id = r.survey_id
               LEFT JOIN {contacts} c ON c.id = r.contact_id
              WHERE r.public_token = :t',
            ['t' => $token]
        );
    }

    /** @param array<string,mixed> $data */
    private function page(string $view, array $data): Response
    {
        return $this->noindex($this->view($view, $data, 'layouts/bare'));
    }

    private function dead(string $message): Response
    {
        return $this->noindex($this->view('public/survey-dead', [
            'pageTitle' => 'That link did not work',
            'message'   => $message,
            'venueName' => Settings::venueName(),
        ], 'layouts/bare'), 404);
    }

    private function noindex(Response $response, int $status = 200): Response
    {
        if ($status !== 200) {
            $response = $response->status($status);
        }

        return $response
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->noCache();
    }
}
