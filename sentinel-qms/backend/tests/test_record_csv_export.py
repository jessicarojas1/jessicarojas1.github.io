"""CSV list-export endpoints for the Nonconformance (NCR) and CAPA modules."""

from __future__ import annotations

import csv
import io

from app.models.user import AuditLog

NCR_EXPORT = "/api/v1/nonconformances/export.csv"
CAPA_EXPORT = "/api/v1/capa/export.csv"

NCR_COLUMNS = [
    "ncr_number",
    "title",
    "status",
    "severity",
    "source",
    "part_number",
    "detected_at",
    "created_at",
    "closed_at",
    "assigned_to",
]
CAPA_COLUMNS = [
    "capa_number",
    "title",
    "capa_type",
    "status",
    "owner_id",
    "root_cause_method",
    "due_date",
    "created_at",
    "closed_at",
]


def _parse_csv(text: str) -> list[list[str]]:
    return list(csv.reader(io.StringIO(text)))


def _create_ncr(client, headers) -> dict:
    resp = client.post(
        "/api/v1/nonconformances",
        json={
            "title": "Bent bracket on inbound lot",
            "description": "Several brackets arrived bent.",
            "severity": "major",
            "source": "receiving",
            "part_number": "PN-1001",
        },
        headers=headers,
    )
    assert resp.status_code == 201, resp.text
    return resp.json()


def _create_capa(client, headers) -> dict:
    resp = client.post(
        "/api/v1/capa",
        json={
            "title": "Eliminate bent-bracket recurrence",
            "d2_problem_description": "Brackets bend during transit.",
            "capa_type": "corrective",
            "root_cause_method": "5why",
        },
        headers=headers,
    )
    assert resp.status_code == 201, resp.text
    return resp.json()


def _assert_csv_attachment(resp, expected_columns):
    assert resp.status_code == 200, resp.text
    assert resp.headers["content-type"].startswith("text/csv")
    assert "attachment" in resp.headers["content-disposition"]
    assert ".csv" in resp.headers["content-disposition"]
    table = _parse_csv(resp.text)
    assert table[0] == expected_columns
    assert len(table) >= 2  # header + >=1 data row
    return table


def test_ncr_csv_export(client, db_session, seeded, auth_headers):
    h = auth_headers("engineer")
    ncr = _create_ncr(client, h)

    resp = client.get(NCR_EXPORT, headers=h)
    table = _assert_csv_attachment(resp, NCR_COLUMNS)
    numbers = {row[0] for row in table[1:]}
    assert ncr["ncr_number"] in numbers

    export_rows = (
        db_session.query(AuditLog)
        .filter(AuditLog.entity_type == "nonconformance", AuditLog.action == "export")
        .all()
    )
    assert len(export_rows) == 1


def test_capa_csv_export(client, db_session, seeded, auth_headers):
    h = auth_headers("engineer")
    capa = _create_capa(client, h)

    resp = client.get(CAPA_EXPORT, headers=h)
    table = _assert_csv_attachment(resp, CAPA_COLUMNS)
    numbers = {row[0] for row in table[1:]}
    assert capa["capa_number"] in numbers

    export_rows = (
        db_session.query(AuditLog)
        .filter(AuditLog.entity_type == "capa", AuditLog.action == "export")
        .all()
    )
    assert len(export_rows) == 1


def test_ncr_csv_export_respects_filters(client, db_session, seeded, auth_headers):
    h = auth_headers("engineer")
    ncr = _create_ncr(client, h)
    # The created NCR is OPEN; filtering for CLOSED must exclude it.
    resp = client.get(NCR_EXPORT, params={"status": "closed"}, headers=h)
    assert resp.status_code == 200, resp.text
    rows = _parse_csv(resp.text)
    assert rows[0] == NCR_COLUMNS
    assert ncr["ncr_number"] not in {r[0] for r in rows[1:]}


def test_ncr_csv_export_forbidden_for_unprivileged(client, db_session, seeded, auth_headers):
    # The Customer role holds no module permissions and must be denied.
    resp = client.get(NCR_EXPORT, headers=auth_headers("customer"))
    assert resp.status_code == 403, resp.text


def test_capa_csv_export_forbidden_for_unprivileged(client, db_session, seeded, auth_headers):
    resp = client.get(CAPA_EXPORT, headers=auth_headers("customer"))
    assert resp.status_code == 403, resp.text
