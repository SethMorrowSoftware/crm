<?php

declare(strict_types=1);

/**
 * Seeds the two feedback surveys.
 *
 * NOT OPTIONAL, for the same reason as the email templates: the reputation
 * module reads a survey row to know what to ask and when, and
 * `survey_responses.survey_id` is a non-null foreign key — so with no survey
 * the module has nowhere to record an answer, and the page can only say so.
 * Two rows cost nothing and make the feature configurable rather than absent.
 *
 * BOTH SHIP SWITCHED OFF. The whole application installs in a state where
 * nothing reaches a guest until somebody decides it should (Safe Mode, every
 * playbook off, every automation off), and a survey that started emailing the
 * last fortnight's parties the moment the installer finished would break that
 * promise in the most visible way available. The Reputation settings page is
 * the switch, and it is one click.
 *
 * Re-seedable: an existing survey is left exactly as the venue configured it.
 *
 * @return array{surveys:int,skipped:int}
 */

use App\Core\DB;

return static function (): array {
    $now = gmdate('Y-m-d H:i:s');

    $surveys = [
        [
            'name'  => 'After a party',
            'type'  => 'nps',
            // The question that predicts a review. Asked the morning after
            // rather than the same evening: a parent clearing up after a
            // party is not going to answer, and the score they would give at
            // that moment is not the one they will hold tomorrow.
            'trigger_event' => 'booking.completed',
            'delay_hours'   => 20,
            'questions'     => [
                [
                    'type'  => 'nps',
                    'label' => 'How likely are you to recommend us to a friend?',
                ],
                [
                    'type'  => 'comment',
                    'label' => 'Anything you would like us to know?',
                ],
            ],
        ],
        [
            'name'  => 'After any visit',
            'type'  => 'csat',
            // Stars rather than 0-10, because an ordinary visit does not
            // warrant the recommendation question, and a five-point scale is
            // answered more often.
            //
            // Off AND unscheduled by default, deliberately: a season-pass
            // family visits weekly, and a venue that switches this on without
            // thinking about frequency is the venue everybody mutes. The
            // module caps it at one ask per guest per fortnight whatever this
            // says, but the venue should still choose it.
            'trigger_event' => null,
            'delay_hours'   => 20,
            'questions'     => [
                [
                    'type'  => 'csat',
                    'label' => 'How was your visit?',
                ],
                [
                    'type'  => 'comment',
                    'label' => 'What would have made it better?',
                ],
            ],
        ],
    ];

    $created = 0;
    $skipped = 0;

    foreach ($surveys as $survey) {
        if (DB::exists('SELECT id FROM {surveys} WHERE name = :name', ['name' => $survey['name']])) {
            $skipped++;
            continue;
        }

        DB::insert('surveys', [
            'name'          => $survey['name'],
            'type'          => $survey['type'],
            'questions'     => json_encode($survey['questions'], JSON_THROW_ON_ERROR),
            'trigger_event' => $survey['trigger_event'],
            'delay_hours'   => $survey['delay_hours'],
            'is_active'     => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $created++;
    }

    return ['surveys' => $created, 'skipped' => $skipped];
};
