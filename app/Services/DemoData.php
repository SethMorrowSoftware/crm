<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;
use Throwable;

/**
 * Finding and removing the example guests the installer can seed.
 *
 * The installer offers "Example guests and bookings" so a venue can judge the
 * reports on something that looks like a Saturday rather than on an empty
 * table. That offer is only honest if the examples come out again cleanly, in
 * one action, before the first real guest is imported — otherwise the venue is
 * left deciding row by row which Hudson family is fictional.
 *
 * Two things make that possible, and both are the seed's doing rather than
 * this class's: every row it creates carries the `demo-data` tag, and the demo
 * contacts also carry `source = 'demo'`. So this is a filter, not an
 * archaeology project.
 *
 * ORDER IS THE WHOLE DESIGN HERE. `fk_bookings_contact` is ON DELETE SET
 * NULL — every foreign key that reaches these tables is SET NULL or CASCADE,
 * none is RESTRICT — so "delete the demo contacts and let the database sort
 * out the rest" does not fail, it SUCCEEDS and leaves fake bookings behind
 * with a null guest. That is worse than failing: it looks finished. The seed
 * hit this already (its own comment records the re-run dying on
 * `booking_number`'s unique key afterwards), which is why everything the seed
 * tags is deleted EXPLICITLY, children before parents, rather than trusted to
 * cascade.
 *
 * What this will NOT do is guess. It removes what the seed tagged and the rows
 * the database cascades from those. A booking a person created by hand against
 * a demo contact is not tagged, so it survives with a null contact — and
 * `summary()` reports that count separately BEFORE anything is deleted, so the
 * decision is made with the number in view rather than discovered afterwards.
 */
final class DemoData
{
    /**
     * The entity types the seed tags, in the order they must be deleted:
     * children before parents, so nothing is left orphaned by a SET NULL.
     *
     * @var array<string,string> entity type => table
     */
    private const ENTITIES = [
        'booking'   => 'bookings',
        'deal'      => 'deals',
        'task'      => 'tasks',
        'activity'  => 'activities',
        'visit'     => 'visits',
        'child'     => 'children',
        'contact'   => 'contacts',
        'household' => 'households',
    ];

    /**
     * A second mark required before a row of this type is treated as the
     * seed's, beyond wearing the `demo-data` tag.
     *
     * THE TAG ALONE IS NOT PROOF, because `demo-data` is an ordinary tag with
     * an ordinary name. An automation's "Add a tag" step takes free text and
     * `AutomationRunner::tag()` creates the tag if it does not exist, so a
     * venue that names a tag `demo-data` for its own purposes gets REAL
     * guests wearing it — and this would then offer to delete them, on an
     * install where the seed had never run at all. Measured against the real
     * runner: that step tags `entity_type = 'contact'` and nothing else,
     * which is why contacts are the one type that needs a second mark and
     * the other seven do not.
     *
     * The mark is the list of ids the seed itself created, kept in the
     * `demo.seed_contact_ids` setting. It used to be `source = 'demo'`, but
     * `source` is an ordinary column: the contact form, the API and an
     * automation step can all write it, so a real guest could carry both the
     * tag and the source and be deleted. No controller writes that setting;
     * only the seed does.
     */
    private const SEED_IDS_KEY = 'demo.seed_contact_ids';

    /**
     * Rows a venue writes ITSELF that the database destroys along with the
     * seed's, because their foreign key cascades.
     *
     * These are not orphans — an orphan survives and is merely detached, and
     * `untaggedLinked()` counts those. These are DELETED, and the venue's own
     * work is in them: the runbook tells a trial user to walk "the enquiry in
     * the pipeline → quote → deposit" on the example data, so a quote written
     * on an example enquiry is the expected case rather than a corner of one.
     *
     * Verified against `information_schema` on the real schema: `quotes.deal_id`,
     * `contact_notes.contact_id`, `conversations.contact_id` and
     * `campaign_recipients.contact_id` are all ON DELETE CASCADE.
     *
     * @var array<string,array{0:string,1:string,2:string,3:string}>
     *      key => [table, foreign key column, the tagged type it hangs off, plural label]
     */
    private const CASCADE_LOSSES = [
        'quotes'        => ['quotes', 'deal_id', 'deal', 'quotes written on an example enquiry'],
        'notes'         => ['contact_notes', 'contact_id', 'contact', 'notes written on an example guest'],
        'conversations' => ['conversations', 'contact_id', 'contact', 'inbox threads with an example guest'],
        'deliveries'    => ['campaign_recipients', 'contact_id', 'contact', 'campaign deliveries to an example guest'],
    ];

    /**
     * What to call each of those on screen, in the order a person reads them.
     *
     * Not derived from the entity type: a plural is not a type name with an
     * "s" on it, and the first version of this card offered to delete
     * "8 activitys, 11 childs".
     *
     * @var array<string,array{0:string,1:string}> type => [singular, plural]
     */
    private const LABELS = [
        'contact'   => ['guest', 'guests'],
        'household' => ['household', 'households'],
        'child'     => ['child', 'children'],
        'visit'     => ['visit', 'visits'],
        'deal'      => ['party enquiry', 'party enquiries'],
        'activity'  => ['activity', 'activities'],
        'task'      => ['task', 'tasks'],
        'booking'   => ['booking', 'bookings'],
    ];

    /**
     * The counts as one readable clause: "8 guests, 11 children, 2 bookings".
     *
     * Pure, and in PHP rather than in the view, so the wording is testable and
     * the view has no arithmetic in it.
     *
     * @param array<string,int> $counts
     */
    public static function describe(array $counts): string
    {
        $parts = [];

        foreach (self::LABELS as $type => [$one, $many]) {
            $n = (int) ($counts[$type] ?? 0);

            if ($n > 0) {
                $parts[] = number_format($n) . ' ' . ($n === 1 ? $one : $many);
            }
        }

        return implode(', ', $parts);
    }

    /**
     * What a removal would take with it, without taking it.
     *
     * `total` counts only the tagged rows. Rows the database cascades (a
     * booking's payments, a contact's consent log) are not counted, because
     * counting them would need a query per table to say a number nobody acts
     * on. What IS counted separately is `untagged_linked`: real rows somebody
     * created against a demo guest, which this will not delete and which will
     * be left with an empty guest. That one is worth knowing in advance.
     *
     * @return array{present:bool,counts:array<string,int>,total:int,untagged_linked:int,
     *               cascade:array<string,int>,cascade_total:int}
     */
    public static function summary(): array
    {
        $empty = [
            'present'         => false,
            'counts'          => [],
            'total'           => 0,
            'untagged_linked' => 0,
            'cascade'         => [],
            'cascade_total'   => 0,
        ];

        $tagId = self::tagId();

        // Nothing is the seed's unless the seed demonstrably ran. See
        // `seedRan()` — the tag's name is not evidence on its own.
        if ($tagId === 0 || !self::seedRan()) {
            return $empty;
        }

        $counts = [];
        $total  = 0;

        foreach (self::ENTITIES as $type => $table) {
            // Joined to the table rather than counted off `taggables` alone: a
            // tag row can outlive the row it points at (nothing cascades a
            // polymorphic join), and reporting "12 contacts" for twelve
            // dangling tag rows would promise a removal that removes nothing.
            $counts[$type] = (int) DB::value(
                'SELECT COUNT(*) FROM {taggables} t
                  JOIN {' . $table . '} e ON e.id = t.entity_id
                 WHERE ' . self::seedRowClause($type),
                ['tag' => $tagId, 'type' => $type]
            );

            $total += $counts[$type];
        }

        $cascade      = $total > 0 ? self::cascadeLosses($tagId) : [];
        $cascadeTotal = array_sum($cascade);

        return [
            'present'         => $total > 0,
            'counts'          => $counts,
            'total'           => $total,
            'untagged_linked' => $total > 0 ? self::untaggedLinked($tagId) : 0,
            'cascade'         => $cascade,
            'cascade_total'   => $cascadeTotal,
        ];
    }

    /**
     * Did the installer's demo seed actually run on this install?
     *
     * Either of two marks the seed leaves (`install/seeds/demo.php`): the
     * contact ids it recorded in `demo.seed_contact_ids`, or a booking
     * numbered `DEMO-…`. Neither is reachable from the application's own UI,
     * so neither can be produced by a venue going about its work.
     *
     * Without this, a `demo-data` tag was all it took: the automation engine's
     * "Add a tag" step takes free text, creates the tag if it is missing, and
     * applies it to REAL contacts. On an install where the seed had never run,
     * that alone made Settings → Backups offer to delete them.
     */
    private static function seedRan(): bool
    {
        return self::seedContactIds() !== []
            || DB::exists("SELECT id FROM {bookings} WHERE booking_number LIKE 'DEMO-%'");
    }

    /**
     * The contacts the seed created, as the seed recorded them.
     *
     * @return list<int>
     */
    private static function seedContactIds(): array
    {
        $ids = Settings::get(self::SEED_IDS_KEY, []);

        return is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
    }

    /**
     * The WHERE that identifies one tagged row as the seed's, for `$type`,
     * against `{taggables} t` joined to its entity as `e`.
     */
    private static function seedRowClause(string $type): string
    {
        return 't.tag_id = :tag AND t.entity_type = :type' . self::seedMark($type, 'e', ' AND ');
    }

    /**
     * The extra "this really is the seed's" condition for `$type`, against
     * table alias `$alias`, or '' where the type needs none.
     */
    private static function seedMark(string $type, string $alias, string $prefix = ''): string
    {
        if ($type !== 'contact') {
            return '';
        }

        $ids = self::seedContactIds();

        // Integers by construction, so inlining them is safe; an empty list
        // matches nothing rather than everything.
        return $prefix . ($ids === [] ? '1 = 0' : $alias . '.id IN (' . implode(',', $ids) . ')');
    }

    /**
     * Rows the venue wrote that will be DELETED with the seed's, by cascade.
     *
     * Counted only where the row is not itself the seed's: the seed writes no
     * quotes, notes or conversations today, but a count that silently included
     * them would overstate the loss the moment it did.
     *
     * @return array<string,int> key => count, zero entries dropped
     */
    private static function cascadeLosses(int $tagId): array
    {
        $losses = [];

        foreach (self::CASCADE_LOSSES as $key => [$table, $column, $parentType]) {
            // Joined to the parent's own table so the "this really is the
            // seed's" mark can be applied: a note on a REAL contact somebody
            // tagged `demo-data` is not about to be deleted, because that
            // contact is not about to be deleted either.
            $parentTable = self::ENTITIES[$parentType];

            try {
                $count = (int) DB::value(
                    'SELECT COUNT(*) FROM {' . $table . '} r
                      JOIN {' . $parentTable . '} p ON p.id = r.' . $column . '
                      JOIN {taggables} pt ON pt.entity_id = p.id
                           AND pt.entity_type = :parent AND pt.tag_id = :tag
                     WHERE NOT EXISTS (
                         SELECT 1 FROM {taggables} rt
                          WHERE rt.entity_id = r.id AND rt.tag_id = :tag2
                     )' . self::seedMark($parentType, 'p', ' AND '),
                    ['parent' => $parentType, 'tag' => $tagId, 'tag2' => $tagId]
                );
            } catch (Throwable) {
                // A table this schema version does not have is not a loss.
                continue;
            }

            if ($count > 0) {
                $losses[$key] = $count;
            }
        }

        return $losses;
    }

    /**
     * The cascade losses as one readable clause.
     *
     * @param array<string,int> $cascade
     */
    public static function describeCascade(array $cascade): string
    {
        $parts = [];

        foreach (self::CASCADE_LOSSES as $key => [, , , $label]) {
            $n = (int) ($cascade[$key] ?? 0);

            if ($n > 0) {
                $parts[] = number_format($n) . ' ' . $label;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Delete every row the demo seed created.
     *
     * Returns what it actually deleted, per entity, which is not always what
     * `summary()` predicted — somebody may have deleted a demo contact by hand
     * in between, and the honest report is the one taken from the deletes.
     *
     * Wrapped in a transaction: a removal that got as far as the bookings and
     * then failed would leave exactly the half-cleaned state this whole class
     * exists to avoid.
     *
     * @return array{counts:array<string,int>,total:int}
     */
    public static function remove(): array
    {
        return DB::transaction(static function (): array {
            $tagId = self::tagId();

            // Same corroboration as `summary()`, re-checked here rather than
            // trusted from the page: this is the method that deletes.
            if ($tagId === 0 || !self::seedRan()) {
                return ['counts' => [], 'total' => 0];
            }

            $counts = [];
            $total  = 0;

            foreach (self::ENTITIES as $type => $table) {
                $ids = DB::column(
                    'SELECT t.entity_id FROM {taggables} t
                      JOIN {' . $table . '} e ON e.id = t.entity_id
                     WHERE ' . self::seedRowClause($type),
                    ['tag' => $tagId, 'type' => $type]
                );

                if ($ids === []) {
                    $counts[$type] = 0;

                    continue;
                }

                $counts[$type] = self::deleteByIds($table, $ids);
                $total        += $counts[$type];

                // The tag rows for exactly what was just deleted. NOT
                // `DELETE ... WHERE tag_id = :tag`, which also takes rows of
                // types this never touches — a venue that has put the same
                // tag on a company of its own would have it silently removed,
                // and then the "does anything still wear it?" check below
                // would wrongly delete the tag as well.
                self::untagIds($tagId, $type, $ids);
            }

            // Tag rows pointing at a row that no longer exists. Nothing
            // cascades a polymorphic join, so these accumulate on their own;
            // they are cleaned only for the types this class owns.
            foreach (self::ENTITIES as $type => $table) {
                DB::query(
                    'DELETE t FROM {taggables} t
                      LEFT JOIN {' . $table . '} e ON e.id = t.entity_id
                     WHERE t.tag_id = :tag AND t.entity_type = :type AND e.id IS NULL',
                    ['tag' => $tagId, 'type' => $type]
                );
            }

            // And the marker itself, but only if nothing else wears it — a
            // venue that tagged its own rows `demo-data` for its own reasons
            // keeps both the rows and the tag.
            if (!DB::exists('SELECT tag_id FROM {taggables} WHERE tag_id = :tag', ['tag' => $tagId])) {
                DB::delete('tags', 'id = :id', ['id' => $tagId]);
            }

            return ['counts' => $counts, 'total' => $total];
        });
    }

    /**
     * Rows somebody created against a demo guest that this will NOT delete.
     *
     * Every table whose own foreign key into `contacts` is ON DELETE SET NULL,
     * verified against `information_schema` on the real schema. Bookings and
     * deals carry money and a date and are the ones a trial user notices, but
     * they were not the only ones: a membership sold to an example guest came
     * back `{"contact_id":null,"status":"active","price_paid_cents":29900}` —
     * active, paid for, and owned by nobody — while the page's message named
     * only bookings and deals. Anything listed here survives DETACHED, which
     * is a different and quieter failure than the cascade losses above.
     */
    private const ORPHANED = [
        'bookings'           => 'booking',
        'deals'              => 'deal',
        'memberships'        => null,
        'tickets'            => null,
        'waiver_signatures'  => null,
        'survey_responses'   => null,
    ];

    private static function untaggedLinked(int $tagId): int
    {
        $count = 0;

        foreach (self::ORPHANED as $table => $type) {
            /*
             * `$type` is the tagged entity type where the table is one this
             * class also deletes — those rows must not be counted as
             * survivors, because they do not survive. Where it is null the
             * table is never tagged, so every matching row is a survivor.
             */
            $notItsOwn = $type === null
                ? ''
                : ' AND NOT EXISTS (
                       SELECT 1 FROM {taggables} rt
                        WHERE rt.entity_id = r.id AND rt.entity_type = :type AND rt.tag_id = :tag2
                   )';

            $params = ['tag' => $tagId];

            if ($type !== null) {
                $params['type'] = $type;
                $params['tag2'] = $tagId;
            }

            try {
                $count += (int) DB::value(
                    'SELECT COUNT(*) FROM {' . $table . '} r
                      JOIN {contacts} c ON c.id = r.contact_id
                      JOIN {taggables} ct ON ct.entity_id = c.id
                           AND ct.entity_type = \'contact\' AND ct.tag_id = :tag
                     WHERE ' . self::seedMark('contact', 'c') . $notItsOwn,
                    $params
                );
            } catch (Throwable) {
                // A table this schema version does not have contributes none.
                continue;
            }
        }

        return $count;
    }

    /**
     * Delete rows by id, in chunks, with the ids bound rather than inlined.
     *
     * The chunk is not about this seed — it creates a few dozen rows — but
     * about never writing a query whose length depends on how much data is in
     * front of it.
     *
     * @param list<int|string> $ids
     */
    private static function deleteByIds(string $table, array $ids): int
    {
        $deleted = 0;

        foreach (array_chunk(array_map('intval', $ids), 200) as $chunk) {
            $names  = [];
            $params = [];

            foreach ($chunk as $i => $id) {
                $names[]        = ':id' . $i;
                $params['id' . $i] = $id;
            }

            $deleted += DB::delete($table, 'id IN (' . implode(', ', $names) . ')', $params);
        }

        return $deleted;
    }

    /**
     * Drop the tag rows for ids this class has just deleted.
     *
     * @param list<int|string> $ids
     */
    private static function untagIds(int $tagId, string $type, array $ids): void
    {
        foreach (array_chunk(array_map('intval', $ids), 200) as $chunk) {
            $names  = [];
            $params = ['tag' => $tagId, 'type' => $type];

            foreach ($chunk as $i => $id) {
                $names[]           = ':id' . $i;
                $params['id' . $i] = $id;
            }

            DB::delete(
                'taggables',
                'tag_id = :tag AND entity_type = :type AND entity_id IN (' . implode(', ', $names) . ')',
                $params
            );
        }
    }

    private static function tagId(): int
    {
        return (int) DB::value("SELECT id FROM {tags} WHERE name = 'demo-data'");
    }
}
