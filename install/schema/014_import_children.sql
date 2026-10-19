-- Castle CRM — 014 Importing children
--
-- A third kind of import file: children, each attached to a guardian who is
-- already a contact. It is how a venue's party history reaches the birthday
-- campaign — CenterEdge alone holds some 32,000 children's first names and
-- birthdays under the parents who booked them.
--
-- MODIFY rather than a new column: the job's entity is still exactly one
-- thing, and widening the ENUM keeps every existing row as it was.

ALTER TABLE `{prefix}imports`
    MODIFY `entity` ENUM('contacts','visits','children') NOT NULL;
