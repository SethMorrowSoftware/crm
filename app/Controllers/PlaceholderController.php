<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Honest placeholders for modules that land in later phases.
 *
 * A page here is deleted as its module ships — contacts, deals and tasks went
 * with Phase 2, bookings with Phase 3. A placeholder left behind after the
 * real thing exists is worse than a broken link: it tells the reader a feature
 * is still coming when it is already on the nav above it.
 *
 * The navigation shows the whole application from day one so the venue can see
 * the shape of what is coming, but a nav item that 404s is worse than one that
 * says "this arrives in Phase 3, here is exactly what it will do". Each page
 * states its phase, what it will contain, and what it depends on.
 */
final class PlaceholderController extends Controller
{
    public function marketing(Request $request): Response
    {
        return $this->placeholder('Marketing', 4, 'marketing', [
            'Email and SMS campaigns with a cost estimate before you send',
            'Rule-based segments over visits, spend, membership and birth month',
            'Text Club with double opt-in and a monthly offer calendar',
            'Embeddable forms for the Castle\'s website',
        ], 'SMS needs a registered 10DLC brand, which carries a 2–4 week carrier lead time.');
    }

    // Reputation was a placeholder until Phase 6. It is now
    // `ReputationController`, and its method is gone from here rather than
    // left behind pointing at a page that exists — an unreachable "coming
    // soon" for a module that shipped is the sort of thing that survives
    // three releases.

    // Memberships was a placeholder until Phase 6. It is now
    // `MembershipController` — the method is gone rather than left pointing
    // at a page that exists.


    public function reports(Request $request): Response
    {
        return $this->placeholder('Reports', 6, 'reports', [
            'Revenue by day, package and attraction, with year-over-year',
            'Funnel conversion, velocity and win/loss by reason',
            'Campaign attribution and cost per booking by channel',
            'Guest cohorts, RFM segmentation and lifetime value by source',
        ], 'Reports state their data grain: anything from the POS Sales table is day-grain only.');
    }

    /**
     * @param list<string> $features
     */
    private function placeholder(
        string $title,
        int $phase,
        string $icon,
        array $features,
        ?string $note = null
    ): Response {
        return $this->view('placeholder', [
            'pageTitle' => $title,
            'phase'     => $phase,
            'icon'      => $icon,
            'features'  => $features,
            'note'      => $note,
        ]);
    }
}
