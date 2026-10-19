#!/usr/bin/env python3
"""
Export The Castle's guests from CenterEdge into files Castle CRM imports.

    python3 tools/centeredge/ce_export.py                 # writes ~/castle-crm-exports/<date>/
    python3 tools/centeredge/ce_export.py --out /path/to/dir
    python3 tools/centeredge/ce_export.py --env ~/.config/castle-crm/centeredge.env

Runs on a machine at the venue, because the CRM lives on shared hosting and
CenterEdge's SQL Server is on the venue LAN — a datacenter cannot reach it, and
it must never be exposed so that one could. The files it writes are uploaded
through Settings -> Import a file, which dry-runs them, reports every refusal
by line, and asks for the consent affirmation before honouring an opt-in.

READ ONLY. Every statement is a SELECT, the session reads uncommitted so it
never takes a lock the point of sale could wait behind, and nothing here can
write: the connection is opened, read and closed.

THE FILES HOLD REAL GUESTS. They go outside the repository by default and must
never be committed — a guest list in git history is in every clone for ever.

What it writes, and why the split:

  contacts.csv  Adults (18+, or no birth date) with an email or a phone. A row
                with neither cannot be matched again and the importer refuses it.
  children.csv  Children, as docs/PRIVACY.md allows them: a first name and a
                birth date, attached to a guardian. From two places — a minor
                CenterEdge account linked to a parent's, and the birthday child
                on a party booking. Never the child's surname, email or phone.
  visits.csv    One row per guest per day, with that day's spend.
  summary.txt   The counts, including who was deliberately left out.

A MINOR'S OWN ACCOUNT IS NEVER A CONTACT. CenterEdge holds 35,000 accounts for
under-18s, many with an email that is really a parent's. Castle CRM stores no
contact route for a child, so a minor becomes a child record under the parent
CenterEdge links them to — and one with no parent linked is left out and
counted, not guessed at.

Needs one package, `pymssql`, in a virtual environment (no root required):

    python3 -m venv ~/.local/share/castle-crm/ce-venv
    ~/.local/share/castle-crm/ce-venv/bin/pip install pymssql
    ~/.local/share/castle-crm/ce-venv/bin/python tools/centeredge/ce_export.py

Connection details come from an env file outside the repository
(default ~/.config/castle-crm/centeredge.env, mode 600):

    CE_HOST=192.168.1.2
    CE_PORT=1433
    CE_DB=CenterEdge
    CE_USER=...
    CE_PASS='...'
"""

from __future__ import annotations

import argparse
import csv
import datetime as dt
import os
import shlex
import stat
import sys
from pathlib import Path

ADULT_AGE = 18

# A phone number on more accounts than this is not anybody's own: it is the
# venue's main line typed in at the desk, or a placeholder. Measured on the
# Castle's data: 123-456-xxxx on 150 accounts, the venue's own number on 112.
# The importer treats a phone as identity, so leaving these in would fold
# strangers together or refuse them as duplicates of each other. Ten, not
# five: numbers on 6-10 accounts average under two surnames — big families on
# one landline — while those on 11 or more average six.
SHARED_PHONE_LIMIT = 10

# The header spellings the importer auto-maps (app/Services/Importer.php
# CONTACT_FIELDS / VISIT_FIELDS aliases, lower-cased with non-letters dropped).
CONTACT_HEADERS = [
    "Customer ID", "First Name", "Last Name", "Email", "Phone", "Alt Phone",
    "Address 1", "Address 2", "City", "State", "Zip", "Birth Date", "Source",
    "Email Opt In", "Opt In Date", "Text Opt In",
]
CHILD_HEADERS = ["Guardian Customer ID", "Child First Name", "Child Birth Date"]
VISIT_HEADERS = ["Customer ID", "Visit Date", "Amount Spent", "Reference"]

# An email worth keeping: something@something.something. The importer is the
# real judge; this only stops obvious junk counting as "reachable" here.
EMAIL_SQL = "{col} LIKE '%_@_%._%'"
PHONE_SQL = "(LEN({raw}) >= 10 OR LEN({fmt}) >= 10)"

ADULT_SQL = f"(c.BirthDate IS NULL OR c.BirthDate <= DATEADD(year, -{ADULT_AGE}, CAST(GETDATE() AS date)))"
MINOR_SQL = f"(c.BirthDate IS NOT NULL AND c.BirthDate > DATEADD(year, -{ADULT_AGE}, CAST(GETDATE() AS date)))"
REACHABLE_SQL = "(" + EMAIL_SQL.format(col="c.EMailAddress") + " OR " + PHONE_SQL.format(raw="c.RawPhoneNumber", fmt="c.PhoneNumber") + ")"

# The guests exported as contacts. Everything else is joined back to this, so
# a visit or a child can only ever point at a contact the import will create.
CONTACT_WHERE = f"c.CustStatusID = 1 AND {ADULT_SQL} AND {REACHABLE_SQL}"


def load_env(path: Path) -> dict[str, str]:
    if not path.is_file():
        sys.exit(f"No connection file at {path}. See the top of this script for its shape.")

    mode = path.stat().st_mode
    if mode & (stat.S_IRWXG | stat.S_IRWXO):
        sys.exit(f"{path} is readable by other users. Run: chmod 600 {path}")

    values: dict[str, str] = {}
    for line in path.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, raw = line.split("=", 1)
        parsed = shlex.split(raw)
        values[key.strip()] = parsed[0] if parsed else ""

    missing = [k for k in ("CE_HOST", "CE_DB", "CE_USER", "CE_PASS") if not values.get(k)]
    if missing:
        sys.exit(f"{path} is missing {', '.join(missing)}.")

    return values


def connect(env: dict[str, str]):
    try:
        import pymssql  # noqa: PLC0415 — imported late so --help works without it
    except ImportError:
        sys.exit("pymssql is not installed. See the top of this script for the two-line setup.")

    conn = pymssql.connect(
        server=env["CE_HOST"],
        port=int(env.get("CE_PORT") or 1433),
        user=env["CE_USER"],
        password=env["CE_PASS"],
        database=env["CE_DB"],
        login_timeout=15,
        timeout=600,
        charset="UTF-8",
    )
    cursor = conn.cursor()
    # Never take a shared lock the point of sale could queue behind.
    cursor.execute("SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED")
    return conn


def rows(conn, sql: str):
    cursor = conn.cursor(as_dict=True)
    assert sql.lstrip().upper().startswith(("SELECT", "WITH")), "read-only: SELECT only"
    cursor.execute(sql)
    while True:
        batch = cursor.fetchmany(5000)
        if not batch:
            break
        yield from batch


def text(value) -> str:
    if value is None:
        return ""
    return " ".join(str(value).split())


def day(value) -> str:
    if value is None:
        return ""
    if isinstance(value, (dt.datetime, dt.date)):
        return value.strftime("%Y-%m-%d")
    return str(value)[:10]


def digits(value) -> str:
    return "".join(ch for ch in text(value) if ch.isdigit())


def placeholder(number: str) -> bool:
    """A number nobody could actually have: all one digit, 123456…, 555-5555."""
    core = number[-10:]
    return (
        len(set(core)) <= 1
        or core.startswith("1234567")
        or "5555555" in core
        or core in ("0123456789", "9876543210")
    )


def yes(value) -> str:
    return "yes" if value in (1, True, "1") else "no"


def export_contacts(conn, path: Path) -> tuple[int, int, int]:
    """Returns (rows written, phones blanked as shared or fake, rows dropped as unreachable after that)."""
    sql = f"""
        SELECT c.CustKey, c.FirstName, c.LastName, c.EMailAddress, c.RawPhoneNumber, c.PhoneNumber,
               c.AltPhoneNumber, c.Address1, c.Address2, c.City, c.State, c.ZipCode, c.BirthDate,
               c.BulkEmail, c.LastBulkEmailUpdate, c.TimeCreated, c.TextMessages
          FROM Customers c
         WHERE {CONTACT_WHERE}
         ORDER BY c.CustKey
    """
    records = list(rows(conn, sql))

    # Counted once per ACCOUNT. CenterEdge keeps the same number in both its
    # raw and formatted columns, and counting per column made three relatives
    # on one landline look like six strangers.
    shared: dict[str, int] = {}
    for r in records:
        numbers = {digits(r[c])[-10:] for c in ("RawPhoneNumber", "PhoneNumber", "AltPhoneNumber")}
        for number in numbers - {""}:
            shared[number] = shared.get(number, 0) + 1

    def usable(value) -> str:
        number = digits(value)[-10:]
        if not number or placeholder(number) or shared.get(number, 0) > SHARED_PHONE_LIMIT:
            return ""
        return text(value)

    count = blanked = dropped = 0
    with path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.writer(handle)
        writer.writerow(CONTACT_HEADERS)
        for r in records:
            email = text(r["EMailAddress"])
            has_email = "@" in email
            raw = text(r["RawPhoneNumber"]) or text(r["PhoneNumber"])
            phone = usable(r["RawPhoneNumber"]) or usable(r["PhoneNumber"])
            if raw and not phone:
                blanked += 1
            if not has_email and not phone:
                # Reachable only through a number that is not theirs.
                dropped += 1
                continue
            consent_at = r["LastBulkEmailUpdate"] or r["TimeCreated"]
            writer.writerow([
                r["CustKey"],
                text(r["FirstName"]),
                text(r["LastName"]),
                email if has_email else "",
                phone,
                usable(r["AltPhoneNumber"]),
                text(r["Address1"]),
                text(r["Address2"]),
                text(r["City"]),
                text(r["State"]),
                text(r["ZipCode"]),
                day(r["BirthDate"]),
                "CenterEdge",
                # An opt-in with no address to send to is not an opt-in.
                yes(r["BulkEmail"]) if has_email else "no",
                day(consent_at) if has_email and r["BulkEmail"] in (1, True) else "",
                yes(r["TextMessages"]) if phone else "no",
            ])
            count += 1
    return count, blanked, dropped


def export_children(conn, path: Path) -> tuple[int, int]:
    """Children from linked minor accounts and from birthday-party bookings."""
    guardian = f"""
        SELECT c.CustKey, c.CustomerID FROM Customers c WHERE {CONTACT_WHERE}
    """
    linked = f"""
        WITH guardians AS ({guardian})
        SELECT g.CustKey AS GuardianKey, c.FirstName, c.BirthDate
          FROM CustomerRelationships r
          JOIN guardians g ON g.CustomerID = r.ParentCustomerID
          JOIN Customers c ON c.CustomerID = r.ChildCustomerID
         WHERE r.RelType IN (0, 1) AND c.CustStatusID = 1 AND {MINOR_SQL}
    """
    parties = f"""
        WITH guardians AS ({guardian})
        SELECT g.CustKey AS GuardianKey, gc.FirstName, gc.DateOfBirth AS BirthDate
          FROM GroupBirthdays b
          JOIN GroupArrivals a ON a.RefID = b.RefID
          JOIN guardians g ON g.CustomerID = COALESCE(a.ContactCustomerID, a.CustomerID)
          JOIN GroupChildren gc ON gc.GroupChildID = b.GroupChildID
         WHERE gc.DateOfBirth IS NOT NULL
           AND gc.DateOfBirth > DATEADD(year, -{ADULT_AGE}, CAST(GETDATE() AS date))
           AND gc.DateOfBirth <= CAST(GETDATE() AS date)
    """
    seen: set[tuple[int, str, str]] = set()
    unnamed = 0
    with path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.writer(handle)
        writer.writerow(CHILD_HEADERS)
        for sql in (linked, parties):
            for r in rows(conn, sql):
                # The first word of the first name only: CenterEdge's field
                # sometimes holds "Emma Smith", and a child's surname is one of
                # the things docs/PRIVACY.md says is not stored.
                name = text(r["FirstName"]).split(" ")[0].strip(".,")
                born = day(r["BirthDate"])
                if not name or not born:
                    unnamed += 1
                    continue
                key = (int(r["GuardianKey"]), name.lower(), born)
                if key in seen:
                    continue
                seen.add(key)
                writer.writerow([r["GuardianKey"], name, born])
    return len(seen), unnamed


def export_visits(conn, path: Path) -> int:
    # One row per guest per day: CustVisits says they came, CustSales says
    # what they spent that day. A day with sales but no visit row is still a
    # visit — they bought something.
    sql = f"""
        WITH contacts AS (SELECT c.CustKey FROM Customers c WHERE {CONTACT_WHERE}),
        visit_days AS (
            SELECT v.CustKey, CAST(v.VisitDate AS date) AS Day
              FROM CustVisits v JOIN contacts k ON k.CustKey = v.CustKey
            UNION
            SELECT s.CustKey, CAST(s.ShiftDate AS date)
              FROM CustSales s JOIN contacts k ON k.CustKey = s.CustKey
        ),
        spend AS (
            SELECT s.CustKey, CAST(s.ShiftDate AS date) AS Day,
                   SUM(ISNULL(s.AmtSold, 0) - ISNULL(s.AmtReturned, 0)) AS Spent
              FROM CustSales s JOIN contacts k ON k.CustKey = s.CustKey
             GROUP BY s.CustKey, CAST(s.ShiftDate AS date)
        )
        SELECT d.CustKey, d.Day, ISNULL(sp.Spent, 0) AS Spent
          FROM visit_days d
          LEFT JOIN spend sp ON sp.CustKey = d.CustKey AND sp.Day = d.Day
         ORDER BY d.CustKey, d.Day
    """
    count = 0
    with path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.writer(handle)
        writer.writerow(VISIT_HEADERS)
        for r in rows(conn, sql):
            spent = max(0.0, float(r["Spent"] or 0))
            when = day(r["Day"])
            writer.writerow([r["CustKey"], when, f"{spent:.2f}", f"CE-{r['CustKey']}-{when}"])
            count += 1
    return count


def left_out(conn) -> dict[str, int]:
    sql = f"""
        SELECT
          SUM(CASE WHEN c.CustStatusID <> 1 THEN 1 ELSE 0 END) AS inactive,
          SUM(CASE WHEN c.CustStatusID = 1 AND {ADULT_SQL} AND NOT {REACHABLE_SQL} THEN 1 ELSE 0 END) AS adult_unreachable,
          SUM(CASE WHEN c.CustStatusID = 1 AND {MINOR_SQL} THEN 1 ELSE 0 END) AS minor_accounts
          FROM Customers c
    """
    return {k: int(v or 0) for k, v in next(rows(conn, sql)).items()}


def main() -> int:
    parser = argparse.ArgumentParser(description="Export CenterEdge guests for Castle CRM's importer (read-only).")
    parser.add_argument("--env", type=Path, default=Path.home() / ".config/castle-crm/centeredge.env")
    parser.add_argument("--out", type=Path, default=None, help="directory to write into (default ~/castle-crm-exports/<date>)")
    args = parser.parse_args()

    out = args.out or Path.home() / "castle-crm-exports" / dt.date.today().isoformat()
    repo = Path(__file__).resolve().parents[2]
    if out.resolve().is_relative_to(repo):
        sys.exit(f"Refusing to write guest data inside the repository ({repo}). Choose another --out.")

    out.mkdir(parents=True, exist_ok=True)
    os.chmod(out, 0o700)

    conn = connect(load_env(args.env))
    try:
        contacts, blanked, dropped = export_contacts(conn, out / "contacts.csv")
        children, unnamed = export_children(conn, out / "children.csv")
        visits = export_visits(conn, out / "visits.csv")
        skipped = left_out(conn)
    finally:
        conn.close()

    for name in ("contacts.csv", "children.csv", "visits.csv"):
        os.chmod(out / name, 0o600)

    summary = "\n".join([
        f"CenterEdge export, {dt.datetime.now():%Y-%m-%d %H:%M}",
        "",
        f"contacts.csv  {contacts:>7,}  adults with an email or a phone",
        f"children.csv  {children:>7,}  first name and birth date, under a guardian above",
        f"visits.csv    {visits:>7,}  guest-days, with that day's spend",
        "",
        "Left out, deliberately:",
        f"  {skipped['minor_accounts']:>7,}  accounts belonging to under-{ADULT_AGE}s (a child is never a contact;"
        " those with a linked parent are in children.csv)",
        f"  {skipped['adult_unreachable']:>7,}  adults with no email and no phone (nothing to match them by)",
        f"  {skipped['inactive']:>7,}  suspended or cancelled accounts",
        f"  {dropped:>7,}  adults whose only phone was shared or a placeholder, with no email",
        f"  {blanked:>7,}  phone numbers blanked as shared by more than {SHARED_PHONE_LIMIT} accounts, or fake"
        " (the guest is kept if they have an email)",
        f"  {unnamed:>7,}  child rows with no first name or birth date",
        "",
        "Import contacts.csv first, then children.csv and visits.csv, which match",
        "against it by Customer ID. Settings -> Import a file.",
        "",
        "These files hold real guests. Delete them once imported.",
    ])
    (out / "summary.txt").write_text(summary + "\n")
    os.chmod(out / "summary.txt", 0o600)
    print(summary)
    print(f"\nWritten to {out}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
