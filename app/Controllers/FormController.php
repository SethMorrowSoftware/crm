<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Event;
use App\Core\Log;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use DateTimeImmutable;

/**
 * Web forms: the builder, the public page a guest fills in, and the
 * submissions it produces.
 *
 * Two halves live in one class because they are two ends of one thing, but
 * they are deliberately not symmetrical:
 *
 *   - the ADMIN half (index/create/store/edit/update/destroy/submissions)
 *     needs `view_marketing` to look and `forms_manage` to build;
 *   - the PUBLIC half (show/submit) has no login at all, because a parent
 *     filling in a party enquiry from the venue's website does not have one.
 *
 * Everything the public half reads is named column by column, and it renders a
 * view that is handed nothing else. That is the only guard that holds: a
 * `SELECT *` here would put the notify list, the pipeline wiring and the
 * submission counter one careless `print` away from the open internet, and a
 * join to submissions would put other guests' answers there.
 *
 * Three rules this module exists to keep, each written where it is enforced:
 *
 *   1. A submission never overwrites what a contact already has. It fills
 *      blanks and nothing else — see resolveContact().
 *   2. Consent is recorded only when a guest ticked a consent box, with the
 *      wording they saw stored as evidence. An enquiry is not permission to
 *      market. See recordConsent() and docs/PRIVACY.md §2-3.
 *   3. A form with submissions cannot be deleted, because the cascade would
 *      take the guests' enquiries with it. See destroy().
 *
 * On scoping: `Acl::scopeCondition()` is not applied here. Neither `forms` nor
 * `form_submissions` carries an owner column — `forms.created_by` records who
 * built a form, not who owns the leads — so there is nothing for a team/own
 * scope to restrict, and inventing one would hide the venue's own party
 * enquiry form from the marketing user who needs it. Every seeded role that
 * holds `view_marketing` holds it at 'all'.
 */
final class FormController extends Controller
{
    /**
     * Field types this module renders AND validates.
     *
     * Deliberately shorter than the schema's enum. `file`, `child_repeater`,
     * `hidden`, `radio`, `number` and `time` are all storable, but a type that
     * is offered in the builder and then not really checked on submission is
     * worse than one that is absent: it reads as working.
     *
     * @var array<string,string>
     */
    public const TYPES = [
        'text'     => 'Single line of text',
        'email'    => 'Email address',
        'tel'      => 'Phone number',
        'textarea' => 'Paragraph of text',
        'select'   => 'Choose one from a list',
        'date'     => 'Date',
        'checkbox' => 'Tick box',
        'consent'  => 'Marketing opt-in (records consent)',
    ];

    /**
     * Contact columns a non-consent field may write to.
     *
     * @var array<string,string>
     */
    public const CONTACT_COLUMNS = [
        'first_name'  => 'Contact: first name',
        'last_name'   => 'Contact: last name',
        'email'       => 'Contact: email address',
        'phone'       => 'Contact: phone number',
        'city'        => 'Contact: city',
        'postal_code' => 'Contact: ZIP code',
    ];

    /**
     * What a consent field may grant. The value is the consent_log channel.
     *
     * @var array<string,string>
     */
    public const CONSENT_CHANNELS = [
        'email_consent' => 'email',
        'sms_consent'   => 'sms',
    ];

    /**
     * Field keys that would collide with something the POST body already
     * means. `_token` is the CSRF field, `website_url` is the default
     * honeypot, and the utm_* names are the campaign attribution carried as
     * hidden inputs.
     *
     * @var list<string>
     */
    public const RESERVED_KEYS = [
        '_token', '_method', 'website_url',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
    ];

    /** Blank field rows offered in the builder, so no JavaScript is needed. */
    private const BLANK_ROWS = 5;

    /** The most fields one form may carry. */
    private const MAX_FIELDS = 40;

    /**
     * Said out loud on save rather than refused.
     *
     * A form with no email or phone question still works — an anonymous
     * post-visit comment card is a real thing a venue wants — but it cannot
     * produce a lead, and that is not visible from the builder. Refusing it
     * would be the wrong trade: it would make the honest anonymous form
     * impossible in order to prevent a mistake a sentence can prevent.
     */
    private const UNREACHABLE_WARNING = 'Saved — but no question maps to an email address or a phone '
        . 'number, so submissions will be stored without creating a contact. Nobody will be able to '
        . 'reply to one.';

    // =================================================================
    // Admin — the builder
    // =================================================================

    public function index(Request $request): Response
    {
        if ($denied = $this->authorize('view_marketing')) {
            return $denied;
        }

        $forms = DB::all(
            'SELECT f.id, f.name, f.slug, f.title, f.is_active, f.created_at,
                    (SELECT COUNT(*) FROM {form_fields} ff WHERE ff.form_id = f.id) AS field_count,
                    (SELECT COUNT(*) FROM {form_submissions} s
                      WHERE s.form_id = f.id AND s.is_spam = 0) AS real_count,
                    (SELECT COUNT(*) FROM {form_submissions} s
                      WHERE s.form_id = f.id AND s.is_spam = 1) AS spam_count
               FROM {forms} f
              ORDER BY f.is_active DESC, f.name'
        );

        return $this->view('forms/index', [
            'pageTitle' => 'Web forms',
            'forms'     => $forms,
            'mayManage' => can('forms_manage'),
        ]);
    }

    public function create(Request $request): Response
    {
        if ($denied = $this->authorize('forms_manage')) {
            return $denied;
        }

        return $this->view('forms/form', [
            'pageTitle'  => 'Build a form',
            'form'       => null,
            'fields'     => [],
            'blankRows'  => self::BLANK_ROWS,
            'types'      => self::TYPES,
            'mapsTo'     => self::CONTACT_COLUMNS,
            'consentMap' => self::CONSENT_CHANNELS,
        ]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->authorize('forms_manage')) {
            return $denied;
        }

        $data = $this->validate($request, $this->formRules(null), $this->formLabels());

        if ($data instanceof Response) {
            return $data;
        }

        $read = $this->readFieldDefinitions($request);

        if (is_string($read)) {
            return $this->refuse($request, $read, '/forms/new');
        }

        if (!$this->mapsReachable($read)) {
            flash('warning', self::UNREACHABLE_WARNING);
        }

        $id = DB::transaction(static function () use ($data, $read, $request): int {
            $formId = DB::insert('forms', [
                // honeypot_field, owner_strategy and the spam/assignment
                // columns keep their schema defaults: the subsystems that read
                // them (pipelines, automations, autoresponders) are not built
                // yet, and a value written now would be a guess.
                'name'            => $data['name'],
                'slug'            => $data['slug'],
                'title'           => $data['title'] ?? null,
                'description'     => $data['description'] ?? null,
                'submit_label'    => ($data['submit_label'] ?? '') !== '' ? $data['submit_label'] : 'Send',
                'success_message' => $data['success_message'] ?? null,
                'redirect_url'    => $data['redirect_url'] ?? null,
                // An unticked checkbox is simply ABSENT from a POST, so the
                // default here has to be off. Defaulting to on meant the Live
                // switch could be turned on and never turned off again — and
                // the only way to take a form off the website is through it.
                'is_active'       => $request->bool('is_active') ? 1 : 0,
                'created_by'      => Auth::id(),
                'created_at'      => now_utc(),
                'updated_at'      => now_utc(),
            ]);

            foreach ($read as $row) {
                DB::insert('form_fields', array_merge($row, ['form_id' => $formId]));
            }

            Audit::record('form.created', 'forms', $formId, null, [
                'name'   => $data['name'],
                'slug'   => $data['slug'],
                'fields' => count($read),
            ]);

            return $formId;
        });

        return $this->success(
            $request,
            'The form is live at /f/' . $data['slug'] . '.',
            '/forms/' . (int) $id
        );
    }

    public function edit(Request $request): Response
    {
        if ($denied = $this->authorize('forms_manage')) {
            return $denied;
        }

        $form = DB::first('SELECT * FROM {forms} WHERE id = :id', ['id' => $request->routeInt('id')]);

        if ($form === null) {
            return $this->notFound('That form does not exist.');
        }

        return $this->view('forms/form', [
            'pageTitle'  => 'Edit ' . $form['name'],
            'form'       => $form,
            'fields'     => $this->fieldsFor((int) $form['id']),
            'blankRows'  => self::BLANK_ROWS,
            'types'      => self::TYPES,
            'mapsTo'     => self::CONTACT_COLUMNS,
            'consentMap' => self::CONSENT_CHANNELS,
            'submissions' => (int) DB::value(
                'SELECT COUNT(*) FROM {form_submissions} WHERE form_id = :id',
                ['id' => (int) $form['id']]
            ),
        ]);
    }

    public function update(Request $request): Response
    {
        if ($denied = $this->authorize('forms_manage')) {
            return $denied;
        }

        $id   = $request->routeInt('id');
        $form = DB::first('SELECT * FROM {forms} WHERE id = :id', ['id' => $id]);

        if ($form === null) {
            return $this->notFound('That form does not exist.');
        }

        $data = $this->validate($request, $this->formRules($id), $this->formLabels());

        if ($data instanceof Response) {
            return $data;
        }

        $read = $this->readFieldDefinitions($request);

        if (is_string($read)) {
            return $this->refuse($request, $read, '/forms/' . $id);
        }

        if (!$this->mapsReachable($read)) {
            flash('warning', self::UNREACHABLE_WARNING);
        }

        DB::transaction(static function () use ($id, $form, $data, $read, $request): void {
            $update = [
                'name'            => $data['name'],
                'slug'            => $data['slug'],
                'title'           => $data['title'] ?? null,
                'description'     => $data['description'] ?? null,
                'submit_label'    => ($data['submit_label'] ?? '') !== '' ? $data['submit_label'] : 'Send',
                'success_message' => $data['success_message'] ?? null,
                'redirect_url'    => $data['redirect_url'] ?? null,
                // Absent means unticked; see the note in store().
                'is_active'       => $request->bool('is_active') ? 1 : 0,
                'updated_at'      => now_utc(),
            ];

            DB::update('forms', $update, 'id = :id', ['id' => $id]);

            // Fields are replaced wholesale rather than diffed. Each stored
            // submission carries its own copy of the label and type it was
            // answered under (see storeSubmission), so rewriting the
            // definitions cannot make an old submission unreadable — which is
            // what a diff would otherwise have to protect.
            DB::delete('form_fields', 'form_id = :id', ['id' => $id]);

            foreach ($read as $row) {
                DB::insert('form_fields', array_merge($row, ['form_id' => $id]));
            }

            Audit::record('form.updated', 'forms', $id, $form, $update);
        });

        return $this->success($request, 'Form saved.', '/forms');
    }

    public function destroy(Request $request): Response
    {
        if ($denied = $this->authorize('forms_manage')) {
            return $denied;
        }

        $id   = $request->routeInt('id');
        $form = DB::first('SELECT id, name, slug FROM {forms} WHERE id = :id', ['id' => $id]);

        if ($form === null) {
            return $this->notFound('That form does not exist.');
        }

        // form_submissions cascades on this delete, so removing a form that
        // has been used destroys the enquiries guests sent through it. Those
        // are the venue's leads and the evidence behind any consent recorded
        // with them; deactivating takes the form off the website and keeps
        // them.
        $submissions = (int) DB::value(
            'SELECT COUNT(*) FROM {form_submissions} WHERE form_id = :id',
            ['id' => $id]
        );

        if ($submissions > 0) {
            return $this->refuse(
                $request,
                'This form holds ' . $submissions . ' submission'
                    . ($submissions === 1 ? '' : 's') . '. Deleting it would delete '
                    . 'them too. Take it off the website by unticking Live instead.',
                '/forms/' . $id
            );
        }

        DB::transaction(static function () use ($id, $form): void {
            DB::delete('forms', 'id = :id', ['id' => $id]);

            Audit::record('form.deleted', 'forms', $id, $form, null);
        });

        return $this->success($request, $form['name'] . ' has been deleted.', '/forms');
    }

    // =================================================================
    // Admin — submissions
    // =================================================================

    public function submissions(Request $request): Response
    {
        if ($denied = $this->authorize('view_marketing')) {
            return $denied;
        }

        $id   = $request->routeInt('id');
        $form = DB::first('SELECT id, name, slug, is_active FROM {forms} WHERE id = :id', ['id' => $id]);

        if ($form === null) {
            return $this->notFound('That form does not exist.');
        }

        ['page' => $page, 'perPage' => $perPage, 'offset' => $offset] = $this->pagination($request, 25, 100);

        $total = (int) DB::value(
            'SELECT COUNT(*) FROM {form_submissions} WHERE form_id = :id',
            ['id' => $id]
        );

        // Spam is listed inline and flagged rather than filtered away: the
        // schema keeps it precisely so a false positive can be rescued, and a
        // page that hides it cannot do that.
        $rows = DB::all(
            'SELECT s.id, s.contact_id, s.data, s.is_spam, s.created_at,
                    c.first_name, c.last_name, c.email
               FROM {form_submissions} s
               LEFT JOIN {contacts} c ON c.id = s.contact_id
              WHERE s.form_id = :id
              ORDER BY s.id DESC
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            ['id' => $id]
        );

        return $this->view('forms/submissions', [
            'pageTitle' => $form['name'] . ' — submissions',
            'form'      => $form,
            'rows'      => array_map(fn (array $r): array => $this->decorateRow($r), $rows),
            'total'     => $total,
            'page'      => $page,
            'perPage'   => $perPage,
            'lastPage'  => max(1, (int) ceil($total / max(1, $perPage))),
        ]);
    }

    public function submission(Request $request): Response
    {
        if ($denied = $this->authorize('view_marketing')) {
            return $denied;
        }

        $formId = $request->routeInt('id');

        $row = DB::first(
            'SELECT s.*, c.first_name, c.last_name, c.email AS contact_email
               FROM {form_submissions} s
               LEFT JOIN {contacts} c ON c.id = s.contact_id
              WHERE s.id = :sid AND s.form_id = :fid',
            ['sid' => $request->routeInt('submission'), 'fid' => $formId]
        );

        if ($row === null) {
            return $this->notFound('That submission does not exist.');
        }

        $form = DB::first('SELECT id, name, slug FROM {forms} WHERE id = :id', ['id' => $formId]);

        if ($form === null) {
            return $this->notFound('That form does not exist.');
        }

        $consent = [];

        if ($row['contact_id'] !== null) {
            $consent = DB::all(
                'SELECT channel, action, source, created_at, evidence
                   FROM {consent_log}
                  WHERE contact_id = :cid
                  ORDER BY id DESC
                  LIMIT 20',
                ['cid' => (int) $row['contact_id']]
            );
        }

        return $this->view('forms/submission', [
            'pageTitle'  => 'Submission #' . (int) $row['id'],
            'form'       => $form,
            'row'        => $this->decorateRow($row),
            'consentLog' => $consent,
        ]);
    }

    // =================================================================
    // Public — no login, no session required to look
    // =================================================================

    /**
     * The hosted form page at /f/{slug}.
     *
     * Every column read here is named, and the view is handed the form's own
     * presentation plus its field definitions and nothing else. In particular
     * it is never joined to form_submissions: a public page that can reach
     * those is a page that can show one guest the enquiry the last one sent.
     */
    public function show(Request $request): Response
    {
        $slug = (string) $request->route('slug', '');
        $form = $this->publicForm($slug);

        if ($form === null) {
            return $this->formGone($slug, 'no such live form');
        }

        $fields = $this->fieldsFor((int) $form['id']);

        if ($fields === []) {
            // A form with nothing to answer is not ready to be shown.
            return $this->formGone($slug, 'no fields');
        }

        return $this->publicResponse($this->view('forms/public', [
            'pageTitle' => (string) ($form['title'] ?: $form['name']),
            'form'      => [
                // Re-listed rather than passed through, so adding a column to
                // the SELECT above can never quietly reach the public view.
                'slug'         => (string) $form['slug'],
                'name'         => (string) $form['name'],
                'title'        => (string) ($form['title'] ?? ''),
                'description'  => (string) ($form['description'] ?? ''),
                'submit_label' => (string) ($form['submit_label'] ?? 'Send'),
                'honeypot'     => (string) $form['honeypot_field'],
            ],
            'fields'    => array_map(fn (array $f): array => $this->publicField($f), $fields),
            'sent'      => $request->query('sent') !== null,
            'thanks'    => (string) ($form['success_message'] ?? '') !== ''
                ? (string) $form['success_message']
                : 'Thank you — we have your enquiry and someone will be in touch.',
            'venueName' => Settings::venueName(),
            'utm'       => $this->utmFrom($request),
        ], 'layouts/bare'));
    }

    /**
     * Handle a public submission.
     *
     * Still CSRF-protected: the page that renders the form mints a token into
     * the guest's session, so the POST can carry one. Dropping that because
     * "it is a public form" would turn every form on the venue's site into a
     * write endpoint any other site could aim at a visitor's browser.
     */
    public function submit(Request $request): Response
    {
        $slug = (string) $request->route('slug', '');
        $form = $this->publicForm($slug);

        if ($form === null) {
            return $this->formGone($slug, 'no such live form');
        }

        $fields = $this->fieldsFor((int) $form['id']);

        if ($fields === []) {
            return $this->formGone($slug, 'no fields');
        }

        // Spam check 1: the honeypot. A field no human sees, so anything in it
        // came from something filling in every input on the page. Rate
        // limiting is spam check 2 and lives on the route.
        $honeypot = (string) $form['honeypot_field'];
        $isSpam   = $honeypot !== '' && trim((string) $request->post($honeypot, '')) !== '';

        $read = $this->readAnswers($request, $fields);

        // A spam submission is stored unvalidated and told nothing. Showing it
        // the real errors teaches a bot how to pass.
        if (!$isSpam && $read['errors'] !== []) {
            return $this->publicRefusal($request, $slug, $read['errors'], $fields);
        }

        // An entirely blank submission on an all-optional form is somebody
        // leaning on the button, not an enquiry. Stored, it would be a lead
        // with nothing in it that staff still have to read and dismiss.
        if (!$isSpam && !$this->hasAnyAnswer($read['answers'])) {
            return $this->publicRefusal(
                $request,
                $slug,
                ['_form' => 'Please answer at least one question before sending.'],
                $fields
            );
        }

        $utm = $this->utmFrom($request);

        $outcome = DB::transaction(function () use ($form, $fields, $read, $isSpam, $utm, $request): array {
            $contact = ['id' => null, 'created' => false, 'withheld' => []];

            if (!$isSpam) {
                $contact = $this->resolveContact($form, $fields, $read['answers'], $utm);
            }

            $submissionId = $this->storeSubmission(
                $form,
                $read['answers'],
                $contact['id'],
                $isSpam,
                $utm,
                $request
            );

            $granted = [];

            if (!$isSpam) {
                $granted = $this->recordConsent(
                    $form,
                    $fields,
                    $read['answers'],
                    $contact['id'],
                    $submissionId,
                    $request
                );

                // Incremented in the database rather than read-modify-written
                // here: two families submitting in the same second would
                // otherwise both read the same number and store it back.
                DB::query(
                    'UPDATE {forms}
                        SET submission_count = submission_count + 1, updated_at = :now
                      WHERE id = :id',
                    ['now' => now_utc(), 'id' => (int) $form['id']]
                );
            }

            return [
                'submission_id' => $submissionId,
                'contact'       => $contact,
                'granted'       => $granted,
            ];
        });

        // Audit and events go out after the commit, so nothing downstream can
        // read a row that is still only a maybe.
        Audit::event('form.submitted', [
            'form'       => (string) $form['slug'],
            'submission' => $outcome['submission_id'],
            'contact_id' => $outcome['contact']['id'],
            'contact'    => $outcome['contact']['created'] ? 'created' : 'matched',
            // Named here because it is the one part of a submission a human
            // may need to reconcile by hand: the guest told us something that
            // disagrees with what the contact already says, and we kept theirs.
            'withheld'   => $outcome['contact']['withheld'],
            'consent'    => $outcome['granted'],
            'spam'       => $isSpam,
        ], 'forms', (int) $form['id']);

        if (!$isSpam) {
            Event::fire('form.submitted', [
                'form_id'       => (int) $form['id'],
                'form_slug'     => (string) $form['slug'],
                'submission_id' => $outcome['submission_id'],
                'contact_id'    => $outcome['contact']['id'],
            ]);

            foreach ($outcome['granted'] as $channel) {
                Event::fire('contact.consent_granted', [
                    'contact_id' => $outcome['contact']['id'],
                    'channel'    => $channel,
                    'source'     => 'form:' . $form['slug'],
                ]);
            }
        }

        $redirect = (string) ($form['redirect_url'] ?? '');

        if ($redirect !== '' && preg_match('#^https?://#i', $redirect) === 1) {
            // Operator-configured on save (validated as a URL there), not
            // taken from the request.
            return $this->publicResponse(Response::redirect($redirect));
        }

        return $this->publicResponse($this->redirect('/f/' . $slug . '?sent=1'));
    }

    // =================================================================
    // Writes behind a submission
    // =================================================================

    /**
     * Find or create the contact behind a submission.
     *
     * The rule that matters: an existing contact's details are never
     * overwritten. A blank column is filled, a populated one is left exactly
     * as it was and reported in `withheld` so somebody can look. A web form is
     * typed by whoever is at the keyboard — a parent booking for a friend, a
     * grandparent using a shared address — and letting it rewrite a record
     * staff have curated loses real information to a typo.
     *
     * @param array<string,mixed>                 $form
     * @param list<array<string,mixed>>           $fields
     * @param array<string,array<string,mixed>>   $answers
     * @param array<string,string>                $utm
     * @return array{id:int|null,created:bool,withheld:list<string>}
     */
    private function resolveContact(array $form, array $fields, array $answers, array $utm): array
    {
        $mapped = [];

        foreach ($fields as $field) {
            $column = (string) ($field['maps_to'] ?? '');

            if (!isset(self::CONTACT_COLUMNS[$column])) {
                continue;
            }

            $value = $answers[(string) $field['key']]['value'] ?? null;

            if (!is_string($value) || $value === '') {
                continue;
            }

            $mapped[$column] = $value;
        }

        $email = isset($mapped['email']) ? strtolower($mapped['email']) : null;
        $phone = isset($mapped['phone']) ? normalize_phone($mapped['phone']) : null;

        if ($email === null && $phone === null) {
            // Nothing to identify or reach them by. A contact row with neither
            // is not a lead, it is litter — the submission still keeps every
            // answer, so nothing the guest typed is lost.
            return ['id' => null, 'created' => false, 'withheld' => []];
        }

        $existing = null;

        if ($email !== null) {
            $existing = DB::first(
                'SELECT id, first_name, last_name, email, phone, phone_normalized, city, postal_code
                   FROM {contacts}
                  WHERE email = :email AND deleted_at IS NULL
                  ORDER BY id
                  LIMIT 1',
                ['email' => $email]
            );
        }

        // Phone is a fallback, never a tie-breaker. Two people share one
        // number often enough — a household landline, an office switchboard,
        // a parent's mobile on a child's form — that matching on it while an
        // email is present could attach an enquiry to the wrong person. With
        // no email at all it is the only key there is, and the schema already
        // treats phone_normalized as an identity column for inbound messages.
        if ($existing === null && $email === null && $phone !== null) {
            $existing = DB::first(
                'SELECT id, first_name, last_name, email, phone, phone_normalized, city, postal_code
                   FROM {contacts}
                  WHERE phone_normalized = :phone AND deleted_at IS NULL
                  ORDER BY id
                  LIMIT 1',
                ['phone' => $phone]
            );
        }

        if ($existing !== null) {
            $fill     = [];
            $withheld = [];

            foreach ($mapped as $column => $value) {
                $held = $existing[$column] ?? null;

                if (is_string($held) && trim($held) !== '') {
                    if (strcasecmp(trim($held), $value) !== 0) {
                        $withheld[] = $column;
                    }

                    continue;
                }

                $fill[$column] = $column === 'email' ? strtolower($value) : $value;

                if ($column === 'phone') {
                    $fill['phone_normalized'] = normalize_phone($value);
                }
            }

            if ($fill !== []) {
                $fill['updated_at'] = now_utc();

                DB::update('contacts', $fill, 'id = :id', ['id' => (int) $existing['id']]);
            }

            return [
                'id'       => (int) $existing['id'],
                'created'  => false,
                'withheld' => $withheld,
            ];
        }

        $contactId = DB::insert('contacts', [
            'first_name'      => $mapped['first_name'] ?? null,
            'last_name'       => $mapped['last_name'] ?? null,
            'email'           => $email,
            'phone'           => $mapped['phone'] ?? null,
            'phone_normalized' => $phone,
            'city'            => $mapped['city'] ?? null,
            'postal_code'     => $mapped['postal_code'] ?? null,
            'lifecycle_stage' => 'lead',
            'source'          => 'form',
            'source_detail'   => (string) $form['name'],
            'utm'             => $utm === [] ? null : json_encode($utm, JSON_UNESCAPED_SLASHES),
            // No consent columns are set here. A submitted enquiry is not
            // permission to market — only a ticked consent box is, and
            // recordConsent() is the only thing that writes one.
            'created_at'      => now_utc(),
            'updated_at'      => now_utc(),
        ]);

        Event::fire('contact.created', ['contact_id' => $contactId, 'source' => 'form']);

        return ['id' => $contactId, 'created' => true, 'withheld' => []];
    }

    /**
     * @param array<string,mixed>               $form
     * @param array<string,array<string,mixed>> $answers
     * @param array<string,string>              $utm
     */
    private function storeSubmission(
        array $form,
        array $answers,
        ?int $contactId,
        bool $isSpam,
        array $utm,
        Request $request
    ): int {
        return DB::insert('form_submissions', [
            'form_id'    => (int) $form['id'],
            'contact_id' => $contactId,
            // Each answer carries the label and type it was given under, so a
            // submission stays readable after the form is rewritten. Without
            // that, editing a form turns last season's enquiries into a list
            // of values with no questions.
            'data'       => json_encode($answers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ip'         => $request->ip(),
            'user_agent' => mb_substr($request->userAgent(), 0, 500),
            'referrer'   => mb_substr($request->referrer(), 0, 500),
            'utm'        => $utm === [] ? null : json_encode($utm, JSON_UNESCAPED_SLASHES),
            'spam_score' => $isSpam ? 100 : 0,
            'is_spam'    => $isSpam ? 1 : 0,
            'created_at' => now_utc(),
        ]);
    }

    /**
     * Record consent, and only consent that was actually given.
     *
     * docs/PRIVACY.md §3: consent is per channel, with the time, the source,
     * the IP and the wording the guest was shown. A tick with no wording
     * behind it is not a defence, so the label and help text AS DISPLAYED go
     * into the evidence.
     *
     * Three things this deliberately does not do:
     *   - it records nothing at all when the form has no consent field, or
     *     when the box was left unticked. An enquiry is not an opt-in;
     *   - an unticked box does not revoke anything. Not ticking a box is not
     *     a withdrawal, and treating it as one would silently cancel consent
     *     given at the till or by text;
     *   - it never clears `do_not_contact`. That is a hard block a member of
     *     staff set deliberately; a tick on a web form must not undo it. The
     *     consent_log row is still written, because the grant really happened
     *     and the evidence belongs in an append-only record either way.
     *
     * @param array<string,mixed>               $form
     * @param list<array<string,mixed>>         $fields
     * @param array<string,array<string,mixed>> $answers
     * @return list<string> The channels granted.
     */
    private function recordConsent(
        array $form,
        array $fields,
        array $answers,
        ?int $contactId,
        int $submissionId,
        Request $request
    ): array {
        if ($contactId === null) {
            // Consent has to attach to somebody. If they ticked the box but
            // gave neither an email nor a phone there is no contact to grant
            // it for, and inventing one would be a record of consent for a
            // channel we cannot reach.
            return [];
        }

        $granted = [];

        foreach ($fields as $field) {
            if ((string) $field['type'] !== 'consent') {
                continue;
            }

            $column  = (string) ($field['maps_to'] ?? '');
            $channel = self::CONSENT_CHANNELS[$column] ?? null;

            if ($channel === null) {
                continue;
            }

            $key = (string) $field['key'];

            if (($answers[$key]['value'] ?? false) !== true) {
                continue;
            }

            $stamp = $channel === 'email' ? 'email_consent_at' : 'sms_consent_at';

            $update = [
                $column      => 1,
                $stamp       => now_utc(),
                'updated_at' => now_utc(),
            ];

            if ($channel === 'sms') {
                $update['sms_consent_source'] = mb_substr('web form: ' . $form['name'], 0, 80);
            }

            DB::update('contacts', $update, 'id = :id', ['id' => $contactId]);

            DB::insert('consent_log', [
                'contact_id' => $contactId,
                'channel'    => $channel,
                'action'     => 'granted',
                'source'     => mb_substr('web form: ' . $form['name'], 0, 80),
                'ip'         => $request->ip(),
                'user_agent' => mb_substr($request->userAgent(), 0, 500),
                'evidence'   => json_encode([
                    'form_id'       => (int) $form['id'],
                    'form_slug'     => (string) $form['slug'],
                    'field_key'     => $key,
                    'submission_id' => $submissionId,
                    'disclosure'    => trim(
                        (string) $field['label'] . ' ' . (string) ($field['help_text'] ?? '')
                    ),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => now_utc(),
            ]);

            $granted[] = $channel;
        }

        return $granted;
    }

    // =================================================================
    // Reading a submission
    // =================================================================

    /**
     * Validate a submission against the form's own field definitions.
     *
     * @param list<array<string,mixed>> $fields
     * @return array{answers:array<string,array<string,mixed>>,errors:array<string,string>}
     */
    private function readAnswers(Request $request, array $fields): array
    {
        $answers = [];
        $errors  = [];

        foreach ($fields as $field) {
            $key      = (string) $field['key'];
            $type     = (string) $field['type'];
            $label    = (string) $field['label'];
            $required = (int) $field['is_required'] === 1;

            if ($type === 'checkbox' || $type === 'consent') {
                $raw    = $request->post($key, '');
                $ticked = is_scalar($raw)
                    && in_array(strtolower((string) $raw), ['1', 'on', 'true', 'yes'], true);

                if ($required && !$ticked) {
                    $errors[$key] = 'Please tick "' . $label . '" to continue.';

                    continue;
                }

                $answers[$key] = ['label' => $label, 'type' => $type, 'value' => $ticked];

                continue;
            }

            $raw   = $request->post($key, '');
            $value = is_scalar($raw) ? trim((string) $raw) : '';

            if ($value === '') {
                if ($required) {
                    $errors[$key] = $label . ' is required.';

                    continue;
                }

                $answers[$key] = ['label' => $label, 'type' => $type, 'value' => ''];

                continue;
            }

            // Length is checked before anything else, so a megabyte pasted
            // into a text box never reaches a regex or the database.
            $limit = $type === 'textarea' ? 2000 : 191;

            if (mb_strlen($value) > $limit) {
                $errors[$key] = $label . ' must be ' . $limit . ' characters or fewer.';

                continue;
            }

            switch ($type) {
                case 'email':
                    if (
                        filter_var($value, FILTER_VALIDATE_EMAIL) === false
                        || !str_contains(explode('@', $value)[1] ?? '', '.')
                    ) {
                        $errors[$key] = 'Enter a valid email address for ' . lcfirst($label) . '.';

                        continue 2;
                    }

                    $value = strtolower($value);
                    break;

                case 'tel':
                    // normalize_phone() returning null is exactly the case
                    // that would store a number nothing can ever dial.
                    if (normalize_phone($value) === null) {
                        $errors[$key] = 'Enter a valid phone number for ' . lcfirst($label) . '.';

                        continue 2;
                    }
                    break;

                case 'date':
                    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

                    if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
                        $errors[$key] = $label . ' must be a date like 2026-07-04.';

                        continue 2;
                    }
                    break;

                case 'select':
                    if (!in_array($value, $this->optionsOf($field), true)) {
                        $errors[$key] = 'Choose one of the listed options for ' . lcfirst($label) . '.';

                        continue 2;
                    }
                    break;
            }

            $answers[$key] = ['label' => $label, 'type' => $type, 'value' => $value];
        }

        return ['answers' => $answers, 'errors' => $errors];
    }

    /**
     * Did the guest actually say anything?
     *
     * @param array<string,array<string,mixed>> $answers
     */
    private function hasAnyAnswer(array $answers): bool
    {
        foreach ($answers as $answer) {
            $value = $answer['value'] ?? '';

            if ($value === true || (is_string($value) && $value !== '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send a guest back to the form with their answers and the reasons.
     *
     * Same mechanism the signed-in forms use (see Controller::validate): the
     * errors and the old input survive exactly one redirect, which keeps the
     * public page a plain GET and means a refresh does not resubmit.
     *
     * @param array<string,string>      $errors
     * @param list<array<string,mixed>> $fields
     */
    private function publicRefusal(Request $request, string $slug, array $errors, array $fields): Response
    {
        Session::put('_errors', array_map(static fn (string $m): array => [$m], $errors));

        $old = [];

        foreach ($fields as $field) {
            $key = (string) $field['key'];
            $raw = $request->post($key);

            if (is_scalar($raw)) {
                $old[$key] = (string) $raw;
            }
        }

        Session::put('_old_input_next', $old);

        flash('error', (string) reset($errors));

        return $this->publicResponse($this->redirect('/f/' . $slug));
    }

    // =================================================================
    // Reading the builder
    // =================================================================

    /**
     * Read and validate the field rows the builder submitted.
     *
     * @return list<array<string,mixed>>|string Rows ready to insert, or the
     *                                          first problem in words.
     */
    private function readFieldDefinitions(Request $request): array|string
    {
        $submitted = $request->input('fields');

        if (!is_array($submitted)) {
            return 'Add at least one question. A form with nothing to answer collects nothing.';
        }

        $rows = [];
        $keys = [];
        $sort = 0;

        foreach ($submitted as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = is_scalar($row['label'] ?? null) ? trim((string) $row['label']) : '';

            // A blank label is how the builder's spare rows are left unused.
            if ($label === '') {
                continue;
            }

            $position = (string) (count($rows) + 1);

            if (mb_strlen($label) > 191) {
                return 'Question ' . $position . ': the label must be 191 characters or fewer.';
            }

            if (count($rows) >= self::MAX_FIELDS) {
                return 'A form can carry at most ' . self::MAX_FIELDS . ' questions.';
            }

            $type = is_scalar($row['type'] ?? null) ? (string) $row['type'] : 'text';

            if (!isset(self::TYPES[$type])) {
                return 'Question "' . $label . '": "' . $type . '" is not a field type this form can render.';
            }

            $mapsTo = is_scalar($row['maps_to'] ?? null) ? (string) $row['maps_to'] : '';

            if ($type === 'consent') {
                // A consent box has to say which channel it grants, or the
                // tick records nothing and the form lies to the guest about
                // what they just agreed to.
                if (!isset(self::CONSENT_CHANNELS[$mapsTo])) {
                    return 'Question "' . $label . '": a marketing opt-in must say whether it '
                        . 'grants email or SMS consent.';
                }
            } elseif ($mapsTo !== '' && !isset(self::CONTACT_COLUMNS[$mapsTo])) {
                return 'Question "' . $label . '": "' . $mapsTo . '" is not a contact field.';
            } elseif ($mapsTo === '') {
                $mapsTo = null;
            }

            $options = null;

            if ($type === 'select') {
                $options = $this->splitOptions(is_scalar($row['options'] ?? null) ? (string) $row['options'] : '');

                if (count($options) < 2) {
                    return 'Question "' . $label . '": a list needs at least two options, one per line.';
                }

                if (count($options) > 50) {
                    return 'Question "' . $label . '": that is more than 50 options.';
                }
            }

            $help = is_scalar($row['help_text'] ?? null) ? trim((string) $row['help_text']) : '';

            if (mb_strlen($help) > 500) {
                return 'Question "' . $label . '": the hint must be 500 characters or fewer.';
            }

            $key    = $this->uniqueKey($label, $keys);
            $keys[] = $key;
            $sort++;

            $rows[] = [
                'key'         => $key,
                'label'       => $label,
                'type'        => $type,
                'placeholder' => null,
                'help_text'   => $help === '' ? null : $help,
                'options'     => $options === null ? null : json_encode($options, JSON_UNESCAPED_UNICODE),
                'is_required' => (is_scalar($row['is_required'] ?? null)
                    && in_array(strtolower((string) $row['is_required']), ['1', 'on', 'true', 'yes'], true)) ? 1 : 0,
                'validation'  => null,
                'maps_to'     => $mapsTo,
                'sort_order'  => $sort,
                'width'       => 'full',
            ];
        }

        if ($rows === []) {
            return 'Add at least one question. A form with nothing to answer collects nothing.';
        }

        return $rows;
    }

    /**
     * Can a submission to these fields ever become a contact?
     *
     * Only an email or a phone question can identify or reach a guest, so
     * without one resolveContact() will correctly decline to create a row —
     * see UNREACHABLE_WARNING.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function mapsReachable(array $rows): bool
    {
        foreach ($rows as $row) {
            if (in_array($row['maps_to'] ?? null, ['email', 'phone'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Derive a stable, collision-free POST name from a label.
     *
     * @param list<string> $taken
     */
    private function uniqueKey(string $label, array $taken): string
    {
        $base = slugify($label, '_');
        $base = (string) preg_replace('/[^a-z0-9_]/', '', $base);
        $base = trim($base, '_');

        if ($base === '' || preg_match('/^[a-z]/', $base) !== 1) {
            // A key has to be a usable POST name and must not start with a
            // digit or an underscore, where it could shadow a framework field.
            $base = 'q_' . ($base === '' ? 'field' : $base);
        }

        $base = mb_substr($base, 0, 56);

        $candidate = $base;
        $suffix    = 1;

        while (in_array($candidate, $taken, true) || in_array($candidate, self::RESERVED_KEYS, true)) {
            $suffix++;
            $candidate = mb_substr($base, 0, 56) . '_' . $suffix;
        }

        return $candidate;
    }

    /** @return list<string> */
    private function splitOptions(string $raw): array
    {
        $out = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || mb_strlen($line) > 191) {
                continue;
            }

            if (!in_array($line, $out, true)) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $field
     * @return list<string>
     */
    private function optionsOf(array $field): array
    {
        $decoded = json_decode((string) ($field['options'] ?? ''), true);

        if (!is_array($decoded)) {
            return [];
        }

        $out = [];

        foreach ($decoded as $option) {
            if (is_scalar($option)) {
                $out[] = (string) $option;
            }
        }

        return $out;
    }

    // =================================================================
    // Shared reads
    // =================================================================

    /**
     * @return array<string,string>
     */
    private function formRules(?int $ignoreId): array
    {
        $unique = 'unique:forms,slug' . ($ignoreId === null ? '' : ',' . $ignoreId);

        return [
            'name'            => 'required|string|min:2|max:120',
            'slug'            => 'required|slug|max:64|' . $unique,
            'title'           => 'nullable|string|max:191',
            'description'     => 'nullable|string|max:2000',
            'submit_label'    => 'nullable|string|max:60',
            'success_message' => 'nullable|string|max:2000',
            'redirect_url'    => 'nullable|url|max:255',
        ];
    }

    /** @return array<string,string> */
    private function formLabels(): array
    {
        return [
            'slug'            => 'Web address',
            'submit_label'    => 'Button label',
            'success_message' => 'Thank-you message',
            'redirect_url'    => 'Redirect address',
        ];
    }

    /**
     * The one query the public pages use to find a form.
     *
     * Named columns, `is_active = 1`, and nothing joined. An inactive form is
     * indistinguishable from one that never existed, which is what "take it
     * off the website" has to mean.
     *
     * @return array<string,mixed>|null
     */
    private function publicForm(string $slug): ?array
    {
        if ($slug === '' || preg_match('/^[a-z0-9-]{1,64}$/', $slug) !== 1) {
            return null;
        }

        return DB::first(
            'SELECT id, name, slug, title, description, submit_label, success_message,
                    redirect_url, honeypot_field
               FROM {forms}
              WHERE slug = :slug AND is_active = 1
              LIMIT 1',
            ['slug' => $slug]
        );
    }

    /** @return list<array<string,mixed>> */
    private function fieldsFor(int $formId): array
    {
        return DB::all(
            'SELECT id, `key`, label, type, placeholder, help_text, options,
                    is_required, maps_to, sort_order
               FROM {form_fields}
              WHERE form_id = :id
              ORDER BY sort_order, id',
            ['id' => $formId]
        );
    }

    /**
     * Reduce a field row to what a guest's browser is allowed to know.
     *
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    private function publicField(array $field): array
    {
        return [
            'key'         => (string) $field['key'],
            'label'       => (string) $field['label'],
            'type'        => (string) $field['type'],
            'placeholder' => (string) ($field['placeholder'] ?? ''),
            'help_text'   => (string) ($field['help_text'] ?? ''),
            'required'    => (int) $field['is_required'] === 1,
            'options'     => $this->optionsOf($field),
            // maps_to, sort_order and the row id stay behind: a guest needs to
            // answer the question, not know which column it lands in.
        ];
    }

    /**
     * Decode a stored submission for display.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decorateRow(array $row): array
    {
        $decoded = json_decode((string) ($row['data'] ?? ''), true);
        $answers = [];

        if (is_array($decoded)) {
            foreach ($decoded as $key => $answer) {
                if (!is_array($answer)) {
                    // A row written by something other than this module, or
                    // hand-edited. Show it rather than dropping it.
                    $answers[] = [
                        'label' => (string) $key,
                        'type'  => 'text',
                        'value' => is_scalar($answer) ? (string) $answer : '',
                    ];

                    continue;
                }

                $value = $answer['value'] ?? '';

                $answers[] = [
                    'label' => (string) ($answer['label'] ?? $key),
                    'type'  => (string) ($answer['type'] ?? 'text'),
                    'value' => is_bool($value) ? ($value ? 'Yes' : 'No') : (is_scalar($value) ? (string) $value : ''),
                ];
            }
        }

        $row['answers'] = $answers;

        $summary = [];

        foreach ($answers as $answer) {
            if ($answer['value'] !== '') {
                $summary[] = $answer['value'];
            }

            if (count($summary) === 3) {
                break;
            }
        }

        $row['summary'] = implode(' · ', $summary);

        return $row;
    }

    /**
     * UTM parameters, from the query string the form page was reached with.
     *
     * The public form carries them forward as hidden inputs, so a guest who
     * arrives from a campaign link and then submits is still attributed to it
     * without any JavaScript.
     *
     * @return array<string,string>
     */
    private function utmFrom(Request $request): array
    {
        $out = [];

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
            $value = $request->input($key);

            if (!is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $out[$key] = mb_substr($value, 0, 120);
        }

        return $out;
    }

    /**
     * A page a guest reaches with no session, kept out of search and caches.
     *
     * A shared proxy caching one guest's form page would serve the next
     * visitor that guest's CSRF token and whatever their failed submission
     * left on it.
     */
    private function publicResponse(Response $response): Response
    {
        return $response
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->noCache();
    }

    /**
     * The one answer the public half gives for every form it will not serve.
     *
     * Deliberately identical for "no such slug", "not live yet" and "has no
     * questions": a different message for each would let anybody enumerate
     * which forms the venue has in draft.
     */
    private function formGone(string $slug, string $because): Response
    {
        // Logged because "my form link is broken" is otherwise unanswerable:
        // the guest-facing message cannot say which of the three it was.
        Log::notice('Public form not served', ['slug' => $slug, 'reason' => $because]);

        return $this->publicResponse(
            $this->notFound('That form is not available. If you followed a link, it may have moved.')
        );
    }
}
