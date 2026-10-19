#!/usr/bin/env python3
"""
Keep Castle CRM current with CenterEdge, nightly, through the CRM's REST API.

    ce_sync.py --since 2026-10-18           # first run: everything changed since then
    ce_sync.py                              # later runs: since the last one (less a day)
    ce_sync.py --dry-run                    # say what it would do, write nothing

The BULK load is ce_export.py and the importer, once. This is the trickle
after it: guests created or changed in CenterEdge since the last run, and the
visits they have made, pushed up from the venue LAN to the CRM on shared
hosting. Outbound HTTPS only; nothing reaches into the venue.

READ ONLY against CenterEdge, exactly as ce_export.py is (it shares that
file's connection and filters). Against the CRM it writes only what an
API token with contacts_manage allows, and:

  - A guest is found by CenterEdge customer number (the importer stored it),
    then by email. A new one is created; an existing one has only its BLANK
    fields filled, so a correction somebody made in the CRM is never undone
    by the till's older copy.
  - A visit is one per guest per day, and the API is idempotent by the day,
    so a re-run or an overlapping window changes nothing.
  - Consent is NOT carried by default. CenterEdge's bulk-email box is evidence
    only if the venue says it is, so it is synced only when the CRM env file
    says CE_CONSENT_SYNC=1 and names the wording guests agreed to in
    CE_CONSENT_DISCLOSURE — the same decision the importer asks a person to
    affirm. A guest who withdrew in the CRM is never re-subscribed: the CRM
    refuses that itself.
  - Minors are never contacts, and placeholder phone numbers are dropped,
    by the same rules as the export.

Configuration, outside the repository, mode 600:

  ~/.config/castle-crm/centeredge.env   CenterEdge, as for ce_export.py
  ~/.config/castle-crm/crm-api.env      CRM_URL=https://thecastletechteam.com/crm
                                        CRM_TOKEN=cc_live_...   (Settings -> API tokens:
                                        view_contacts + contacts_manage, nothing else)

State (what was synced when, and the customer-number-to-contact map) lives in
~/.local/share/castle-crm/ce-sync.json.

A nightly line for `crontab -e` on the venue machine:

  15 3 * * * $HOME/.local/share/castle-crm/ce-venv/bin/python $HOME/crm/tools/centeredge/ce_sync.py >> $HOME/.local/share/castle-crm/ce-sync.log 2>&1
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from ce_export import (  # noqa: E402 — the shared, read-only half
    CONTACT_WHERE,
    SHARED_PHONE_LIMIT,
    connect,
    day,
    digits,
    load_env,
    placeholder,
    rows,
    text,
)

STATE_PATH = Path.home() / ".local/share/castle-crm/ce-sync.json"

# The API allows 600 reads and 300 writes a minute, per address, in fixed
# windows. Both are paced well inside that: the first version paced only its
# writes, spent a minute's reads in seconds, and then sat out every window
# after it behind a 429.
READ_PAUSE = 0.12
WRITE_PAUSE = 0.25

# A guest CenterEdge has and the CRM does not (not imported yet, or a minor)
# is not looked up again every night; only after this many days.
RECHECK_MISSING_DAYS = 7

# Fields filled on an existing guest only while the CRM's copy is blank.
FILLABLE = ["first_name", "last_name", "email", "phone", "alt_phone", "address_1", "address_2",
            "city", "state", "postal_code", "birth_date"]


class Crm:
    """The CRM's REST API, with the patience shared hosting needs."""

    def __init__(self, base: str, token: str, dry_run: bool) -> None:
        self.base = base.rstrip("/") + "/api/v1"
        self.token = token
        self.dry_run = dry_run
        self.writes = 0

    def call(self, method: str, path: str, body: dict | None = None) -> tuple[int, dict]:
        if self.dry_run and method != "GET":
            return 0, {}

        data = None if body is None else json.dumps(body).encode()
        for attempt in range(6):
            request = urllib.request.Request(
                self.base + path,
                data=data,
                method=method,
                headers={
                    "Authorization": "Bearer " + self.token,
                    "Accept": "application/json",
                    "Content-Type": "application/json",
                    "User-Agent": "castle-crm-ce-sync/1",
                },
            )
            try:
                with urllib.request.urlopen(request, timeout=30) as response:
                    status, payload = response.status, response.read()
            except urllib.error.HTTPError as error:
                status, payload = error.code, error.read()
                if status == 429 or status >= 500:
                    wait = int(error.headers.get("Retry-After") or 0) or 2 ** attempt
                    time.sleep(min(wait, 60))
                    continue
            if method != "GET":
                self.writes += 1
            time.sleep(READ_PAUSE if method == "GET" else WRITE_PAUSE)
            try:
                return status, json.loads(payload or b"{}")
            except ValueError:
                return status, {"error": payload[:200].decode(errors="replace")}
        return 429, {"error": "still rate limited after six attempts"}

    def find(self, card: str, email: str) -> dict | None:
        for field, value in (("external_pos_id", card), ("email", email)):
            if not value:
                continue
            status, payload = self.call("GET", "/contacts?per_page=2&" + urllib.parse.urlencode({field: value}))
            if status == 200 and payload.get("data"):
                return payload["data"][0]
        return None


def load_state(path: Path) -> dict:
    try:
        return json.loads(path.read_text())
    except (OSError, ValueError):
        return {"last_run": None, "contacts": {}}


def save_state(state: dict, path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temp = path.with_suffix(".tmp")
    temp.write_text(json.dumps(state))
    os.chmod(temp, 0o600)
    temp.replace(path)


def changed_guests(conn, since: str) -> list[dict]:
    """Adults reachable by email or phone, created or changed since `since`."""
    sql = f"""
        SELECT c.CustKey, c.FirstName, c.LastName, c.EMailAddress, c.RawPhoneNumber, c.PhoneNumber,
               c.AltPhoneNumber, c.Address1, c.Address2, c.City, c.State, c.ZipCode, c.BirthDate,
               c.BulkEmail
          FROM Customers c
         WHERE {CONTACT_WHERE}
           AND (c.TimeCreated >= '{since}' OR c.LastUpdate >= '{since}')
         ORDER BY c.CustKey
    """
    return list(rows(conn, sql))


def shared_numbers(conn) -> set[str]:
    """Phone numbers on more than SHARED_PHONE_LIMIT accounts — nobody's own."""
    sql = f"""
        SELECT RIGHT(n, 10) AS num FROM (
            SELECT CustKey, REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                   COALESCE(NULLIF(RawPhoneNumber, ''), PhoneNumber), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '') AS n
              FROM Customers) x
         WHERE LEN(n) >= 10
         GROUP BY RIGHT(n, 10)
        HAVING COUNT(DISTINCT CustKey) > {SHARED_PHONE_LIMIT}
    """
    return {r["num"] for r in rows(conn, sql)}


def visit_days(conn, since: str) -> list[dict]:
    """Every guest-day since `since`, for the guests the export would make contacts.

    Driven by CenterEdge's visits, not by which guests this script has
    touched: a guest from the bulk import who comes back is still a visit,
    whether or not anything on their customer record changed.
    """
    sql = f"""
        WITH contacts AS (SELECT c.CustKey FROM Customers c WHERE {CONTACT_WHERE}),
        days AS (
            SELECT v.CustKey, CAST(v.VisitDate AS date) AS Day
              FROM CustVisits v JOIN contacts k ON k.CustKey = v.CustKey
             WHERE v.VisitDate >= '{since}'
            UNION
            SELECT s.CustKey, CAST(s.ShiftDate AS date)
              FROM CustSales s JOIN contacts k ON k.CustKey = s.CustKey
             WHERE s.ShiftDate >= '{since}'
        )
        SELECT d.CustKey, d.Day,
               (SELECT SUM(ISNULL(s.AmtSold, 0) - ISNULL(s.AmtReturned, 0)) FROM CustSales s
                 WHERE s.CustKey = d.CustKey AND CAST(s.ShiftDate AS date) = d.Day) AS Spent
          FROM days d
         ORDER BY d.CustKey, d.Day
    """
    return list(rows(conn, sql))


def payload_for(r: dict, shared: set[str]) -> dict:
    def phone(value) -> str:
        number = digits(value)[-10:]
        return "" if not number or placeholder(number) or number in shared else text(value)

    email = text(r["EMailAddress"]).lower()
    fields = {
        "first_name": text(r["FirstName"]),
        "last_name": text(r["LastName"]),
        "email": email if "@" in email else "",
        "phone": phone(r["RawPhoneNumber"]) or phone(r["PhoneNumber"]),
        "alt_phone": phone(r["AltPhoneNumber"]),
        "address_1": text(r["Address1"]),
        "address_2": text(r["Address2"]),
        "city": text(r["City"]),
        "state": text(r["State"]),
        "postal_code": text(r["ZipCode"]),
        "birth_date": day(r["BirthDate"]),
    }
    return {k: v for k, v in fields.items() if v}


def main() -> int:
    parser = argparse.ArgumentParser(description="Push CenterEdge changes to Castle CRM (read-only against CenterEdge).")
    parser.add_argument("--since", help="YYYY-MM-DD; required on the first run")
    parser.add_argument("--dry-run", action="store_true", help="say what would happen, write nothing")
    parser.add_argument("--ce-env", type=Path, default=Path.home() / ".config/castle-crm/centeredge.env")
    parser.add_argument("--crm-env", type=Path, default=Path.home() / ".config/castle-crm/crm-api.env")
    parser.add_argument("--state", type=Path, default=STATE_PATH, help="where the run state lives")
    args = parser.parse_args()

    state = load_state(args.state)
    if args.since:
        since = dt.date.fromisoformat(args.since).isoformat()
    elif state.get("last_run"):
        # A day of overlap: a guest edited at 23:59 the night before the last
        # run is caught, and everything here is idempotent.
        since = (dt.date.fromisoformat(state["last_run"][:10]) - dt.timedelta(days=1)).isoformat()
    else:
        sys.exit("First run: pass --since YYYY-MM-DD (the date of the CSV import, so this picks up from there).")

    crm_env = load_env_crm(args.crm_env)
    crm = Crm(crm_env["CRM_URL"], crm_env["CRM_TOKEN"], args.dry_run)
    consent_sync = crm_env.get("CE_CONSENT_SYNC") == "1"
    disclosure = crm_env.get("CE_CONSENT_DISCLOSURE", "").strip()
    if consent_sync and not disclosure:
        sys.exit("CE_CONSENT_SYNC=1 needs CE_CONSENT_DISCLOSURE: the wording guests agreed to at the till.")

    started = dt.datetime.now().isoformat(timespec="seconds")
    counts = {"created": 0, "filled": 0, "unchanged": 0, "consented": 0, "visits": 0, "visits_already": 0, "errors": 0}
    contact_map: dict[str, int] = state.get("contacts", {})
    missing: dict[str, str] = state.get("missing", {})

    conn = connect(load_env(args.ce_env))
    try:
        shared = shared_numbers(conn)
        guests = changed_guests(conn, since)

        for r in guests:
            key = str(r["CustKey"])
            fields = payload_for(r, shared)
            if "email" not in fields and "phone" not in fields:
                continue

            found = crm.find(key, fields.get("email", ""))
            if found is None:
                status, body = crm.call("POST", "/contacts", fields | {
                    "external_pos_id": key, "source": "CenterEdge"})
                if args.dry_run:
                    counts["created"] += 1
                    continue
                if status != 201:
                    counts["errors"] += 1
                    print(f"  ! create {key}: {status} {body.get('error', '')}")
                    continue
                found = body["data"]
                counts["created"] += 1
            else:
                fill = {k: v for k, v in fields.items() if k in FILLABLE and not found.get(k)}
                if not found.get("external_pos_id"):
                    fill["external_pos_id"] = key
                if fill:
                    status, body = crm.call("PATCH", f"/contacts/{found['id']}", fill)
                    if status not in (0, 200):
                        counts["errors"] += 1
                        print(f"  ! fill {key}: {status} {body.get('error', '')}")
                        continue
                    counts["filled"] += 1
                else:
                    counts["unchanged"] += 1

            contact_map[key] = int(found["id"]) if found.get("id") else contact_map.get(key, 0)

            if consent_sync and r["BulkEmail"] in (1, True) and fields.get("email") and not found.get("email_consent"):
                status, body = crm.call("POST", f"/contacts/{found['id']}/consent", {
                    "channel": "email", "granted": True,
                    "source": "CenterEdge bulk-email opt-in", "disclosure": disclosure})
                if status in (0, 200, 201):
                    counts["consented"] += 1

        today = dt.date.today()
        for v in visit_days(conn, since):
            key = str(v["CustKey"])
            checked = missing.get(key)
            stale = checked is None or (today - dt.date.fromisoformat(checked)).days >= RECHECK_MISSING_DAYS
            if not contact_map.get(key) and stale:
                found = crm.find(key, "")
                if found:
                    contact_map[key] = int(found["id"])
                    missing.pop(key, None)
                else:
                    missing[key] = today.isoformat()
            contact_id = contact_map.get(key)
            if not contact_id:
                # Not in the CRM: imported nowhere yet, or a minor. Nothing
                # to attach the visit to, and inventing a guest is not this
                # script's job.
                continue
            when = day(v["Day"])
            status, body = crm.call("POST", "/visits", {
                "contact_id": contact_id, "visit_date": when, "source": "pos_import",
                "spend_cents": max(0, round(float(v["Spent"] or 0) * 100)),
                "external_ref": f"CE-{v['CustKey']}-{when}"})
            if status == 201 or args.dry_run:
                counts["visits"] += 1
            elif status == 200:
                counts["visits_already"] += 1
            else:
                counts["errors"] += 1
                print(f"  ! visit {v['CustKey']} {when}: {status} {body.get('error', body.get('reason', ''))}")
    finally:
        conn.close()

    if not args.dry_run:
        state["last_run"] = started
        state["contacts"] = {k: v for k, v in contact_map.items() if v}
        state["missing"] = missing
        save_state(state, args.state)

    mode = "DRY RUN — nothing written. " if args.dry_run else ""
    print(f"{started} {mode}since {since}: {len(guests)} changed in CenterEdge; "
          f"{counts['created']} created, {counts['filled']} filled in, {counts['unchanged']} already current, "
          f"{counts['consented']} consent recorded; {counts['visits']} visits recorded, "
          f"{counts['visits_already']} already there; {counts['errors']} errors; {crm.writes} API writes.")
    return 1 if counts["errors"] else 0


def load_env_crm(path: Path) -> dict[str, str]:
    """The CRM's address and token, from a file only this user can read."""
    values = load_env_generic(path)
    missing = [k for k in ("CRM_URL", "CRM_TOKEN") if not values.get(k)]
    if missing:
        sys.exit(f"{path} is missing {', '.join(missing)}.")
    if not values["CRM_URL"].startswith(("https://", "http://127.0.0.1", "http://localhost")):
        sys.exit("CRM_URL must be https:// — the token travels in every request.")
    return values


def load_env_generic(path: Path) -> dict[str, str]:
    import shlex
    import stat

    if not path.is_file():
        sys.exit(f"No file at {path}. See the top of this script for its shape.")
    if path.stat().st_mode & (stat.S_IRWXG | stat.S_IRWXO):
        sys.exit(f"{path} is readable by other users. Run: chmod 600 {path}")
    values: dict[str, str] = {}
    for line in path.read_text().splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            key, raw = line.split("=", 1)
            parsed = shlex.split(raw)
            values[key.strip()] = parsed[0] if parsed else ""
    return values


if __name__ == "__main__":
    sys.exit(main())
