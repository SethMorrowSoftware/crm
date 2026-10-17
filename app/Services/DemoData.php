<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

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
     * @return array{present:bool,counts:array<string,int>,total:int,untagged_linked:int}
     */
    public static function summary(): array
    {
        $tagId = self::tagId();

        if ($tagId === 0) {
            return ['present' => false, 'counts' => [], 'total' => 0, 'untagged_linked' => 0];
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
                 WHERE t.tag_id = :tag AND t.entity_type = :type',
                ['tag' => $tagId, 'type' => $type]
            );

            $total += $counts[$type];
        }

        return [
            'present'         => $total > 0,
            'counts'          => $counts,
            'total'           => $total,
            'untagged_linked' => self::untaggedLinked($tagId),
        ];
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

            if ($tagId === 0) {
                return ['counts' => [], 'total' => 0];
            }

            $counts = [];
            $total  = 0;

            foreach (self::ENTITIES as $type => $table) {
                $ids = DB::column(
                    'SELECT t.entity_id FROM {taggables} t
                      JOIN {' . $table . '} e ON e.id = t.entity_id
                     WHERE t.tag_id = :tag AND t.entity_type = :type',
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
     * Bookings and deals only: they are the two that carry money and a date,
     * they are the two whose FK is SET NULL rather than CASCADE, and they are
     * therefore the two that survive visibly rather than vanishing.
     */
    private static function untaggedLinked(int $tagId): int
    {
        $count = 0;

        foreach (['bookings' => 'booking', 'deals' => 'deal'] as $table => $type) {
            $count += (int) DB::value(
                'SELECT COUNT(*) FROM {' . $table . '} r
                  JOIN {contacts} c ON c.id = r.contact_id
                  JOIN {taggables} ct ON ct.entity_id = c.id
                       AND ct.entity_type = \'contact\' AND ct.tag_id = :tag
                 WHERE NOT EXISTS (
                     SELECT 1 FROM {taggables} rt
                      WHERE rt.entity_id = r.id AND rt.entity_type = :type AND rt.tag_id = :tag2
                 )',
                ['tag' => $tagId, 'type' => $type, 'tag2' => $tagId]
            );
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
