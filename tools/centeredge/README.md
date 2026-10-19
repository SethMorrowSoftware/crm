# Bringing The Castle's guests across from CenterEdge

`ce_export.py` reads CenterEdge's SQL Server **read-only** and writes three
files that Castle CRM's importer takes as they are. It runs on a machine at
the venue, because the CRM lives on shared hosting at LiveCode Hosting and the
POS database is on the venue LAN, where a datacenter cannot reach it and never
should.

## One-time setup (no root needed)

```bash
python3 -m venv ~/.local/share/castle-crm/ce-venv
~/.local/share/castle-crm/ce-venv/bin/pip install pymssql

mkdir -p ~/.config/castle-crm
cat > ~/.config/castle-crm/centeredge.env <<'ENV'
CE_HOST=192.168.1.2
CE_PORT=1433
CE_DB=CenterEdge
CE_USER=...
CE_PASS='...'
ENV
chmod 600 ~/.config/castle-crm/centeredge.env
```

The script refuses to read a connection file other users can read, and refuses
to write its output inside this repository.

## Each run

```bash
~/.local/share/castle-crm/ce-venv/bin/python tools/centeredge/ce_export.py
```

It writes `~/castle-crm-exports/<today>/` (mode 700) and prints a summary.
On 19 October 2026 it took five seconds and produced:

| File | Rows | What |
|---|---|---|
| `contacts.csv` | 84,989 | Adults (18+, or no birth date) with an email or a phone |
| `children.csv` | 32,261 | A first name and birth date, under a guardian in `contacts.csv` |
| `visits.csv` | 133,643 | One row per guest per day, with that day's spend |

Then, in the CRM, **Settings → Import a file**, in this order, each one dry-run
first:

1. `contacts.csv` as **Guests**. Every column maps itself. Tick the consent
   affirmation only if you are satisfied CenterEdge's "Bulk email" box was
   ticked by the guest, not by default; without it, nobody imported is
   emailed. The opt-in date is the date that box last changed.
2. `children.csv` as **Children**. Each child attaches to the guardian
   imported in step 1, by CenterEdge customer number.
3. `visits.csv` as **Visit history**. Same matching.

Re-running the export later and re-importing is safe: a guest, a child or a
visit already here is matched, not duplicated.

**Delete the export folder once imported.** It is the guest list.

## What it deliberately leaves out

- **Under-18s' own accounts are never contacts.** CenterEdge holds about
  35,000, many carrying a parent's email. `docs/PRIVACY.md` says a child is a
  first name and a birth date under a guardian, and nothing that could reach
  the child. So a minor linked to a parent in CenterEdge becomes a child
  record under that parent, and one with no parent linked is left out and
  counted.
- **Phone numbers that are nobody's own.** A number on more than ten accounts
  is the venue's main line typed at the desk or a placeholder like
  123-456-7890, and the importer treats a phone as identity. Those numbers
  are blanked. A guest who still has an email is kept.
- Suspended and cancelled accounts, and adults with no way to reach them.

## Read-only, and gentle with the till

Every statement is a `SELECT`. The session reads uncommitted, so it never
takes a lock the point of sale could wait behind. The whole run is a handful
of queries and takes seconds.
