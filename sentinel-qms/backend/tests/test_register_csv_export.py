"""CSV list-export endpoints for the Supplier, Audit, Risk, and Complaint registers."""

from __future__ import annotations

import csv
import io

from app.models.user import AuditLog

SUPPLIER_EXPORT = "/api/v1/suppliers/export.csv"
AUDIT_EXPORT = "/api/v1/audits/export.csv"
RISK_EXPORT = "/api/v1/risks/export.csv"
COMPLAINT_EXPORT = "/api/v1/complaints/export.csv"

SUPPLIER_COLUMNS = [
    "supplier_code",
    "name",
    "status",
    "certification",
    "cert_expiry",
    "cage_code",
    "country",
    "created_at",
]
AUDIT_COLUMNS = [
    "audit_number",
    "title",
    "audit_type",
    "status",
    "standard",
    "auditee_area",
    "planned_date",
    "actual_date",
    "created_at",
]
RISK_COLUMNS = [
    "risk_number",
    "title",
    "category",
    "status",
    "severity",
    "likelihood",
    "detectability",
    "rpn",
    "residual_rpn",
    "review_date",
    "created_at",
]
COMPLAINT_COLUMNS = [
    "complaint_number",
    "title",
    "customer_name",
    "status",
    "severity",
    "is_rma",
    "rma_number",
    "received_date",
    "created_at",
    "closed_at",
]


def _parse_csv(text: str) -> list[list[str]]:
    return list(csv.reader(io.StringIO(text)))


def _assert_csv_attachment(resp, expected_columns):
    assert resp.status_code == 200, resp.text
    assert resp.headers["content-type"].startswith("text/csv")
    assert "attachment" in resp.headers["content-disposition"]
    assert ".csv" in resp.headers["content-disposition"]
    table = _parse_csv(resp.text)
    assert table[0] == expected_columns
    assert len(table) >= 2  # header + >=1 data row
    return table


def _create_supplier(client, headers) -> dict:
    resp = client.post(
        "/api/v1/suppliers",
        json={
            "name": "Acme Aerospace Inc.",
            "status": "approved",
            "certification": "AS9100D",
            "cage_code": "1A2B3",
            "country": "USA",
        },
        headers=headers,
    )
    assert resp.status_code == 201, resp.text
    return resp.json()


def _create_audit(client, headers) -> dict:
    resp = client.post(
        "/api/v1/audits",
        json={
            "title": "Internal AS9100 surveillance audit",
            "audit_type": "internal",
            "standard": "AS9100D",
            "auditee_area": "Receiving inspection",
        },
        headers=headers,
    )
    assert resp.status_code == 201, resp.text
    return resp.json()


def _create_risk(client, headers) -> dict:
    resp = client.post(
        "/api/v1/risks",
        json={
            "title": "Single-source supplier dependency",
            "category": "supply_chain",
            "description": "Critical component sourced from one supplier.",
            "severity": 8,
            "likelihood": 5,
            "detectability": 4,
        },
        headers=headers,
    )
    assert resp.status_code == 201, resp.text
    return resp.json()


def _create_complaint(client, headers) -> dict:
    resp = client.post(
        "/api/v1/complaints",
        json={
            "title": "Bracket cracked in service",
            "description": "Customer reports a cracked mounting bracket.",
            "severity": "high",
            "customer_name": "Globex Corp",
        },
        headers=headers,
    )
    assert resp.status_code == 201, resp.text
    return resp.json()


def test_supplier_csv_export(client, db_session, seeded, auth_headers):
    h = auth_headers("manager")
    supplier = _create_supplier(client, h)

    resp = client.get(SUPPLIER_EXPORT, headers=h)
    table = _assert_csv_attachment(resp, SUPPLIER_COLUMNS)
    assert supplier["supplier_code"] in {row[0] for row in table[1:]}

    export_rows = (
        db_session.query(AuditLog)
        .filter(AuditLog.entity_type == "supplier", AuditLog.action == "export")
        .all()
    )
    assert len(export_rows) == 1


def test_audit_csv_export(client, db_session, seeded, auth_headers):
    h = auth_headers("manager")
    rec = _create_audit(client, h)

    resp = client.get(AUDIT_EXPORT, headers=h)
    table = _assert_csv_attachment(resp, AUDIT_COLUMNS)
    assert rec["audit_number"] in {row[0] for row in table[1:]}

    export_rows = (
        db_session.query(AuditLog)
        .filter(AuditLog.entity_type == "audit", AuditLog.action == "export")
        .all()
    )
    assert len(export_rows) == 1


def test_risk_csv_export(client, db_session, seeded, auth_headers):
    h = auth_headers("manager")
    risk = _create_risk(client, h)

    resp = client.get(RISK_EXPORT, headers=h)
    table = _assert_csv_attachment(resp, RISK_COLUMNS)
    assert risk["risk_number"] in {row[0] for row in table[1:]}

    export_rows = (
        db_session.query(AuditLog)
        .filter(AuditLog.entity_type == "risk", AuditLog.action == "export")
        .all()
    )
    assert len(export_rows) == 1


def test_complaint_csv_export(client, db_session, seeded, auth_headers):
    h = auth_headers("manager")
    complaint = _create_complaint(client, h)

    resp = client.get(COMPLAINT_EXPORT, headers=h)
    table = _assert_csv_attachment(resp, COMPLAINT_COLUMNS)
    assert complaint["complaint_number"] in {row[0] for row in table[1:]}

    export_rows = (
        db_session.query(AuditLog)
        .filter(AuditLog.entity_type == "complaint", AuditLog.action == "export")
        .all()
    )
    assert len(export_rows) == 1


def test_risk_csv_export_respects_filters(client, db_session, seeded, auth_headers):
    h = auth_headers("manager")
    risk = _create_risk(client, h)
    # The created risk is IDENTIFIED; filtering for CLOSED must exclude it.
    resp = client.get(RISK_EXPORT, params={"status": "closed"}, headers=h)
    assert resp.status_code == 200, resp.text
    rows = _parse_csv(resp.text)
    assert rows[0] == RISK_COLUMNS
    assert risk["risk_number"] not in {r[0] for r in rows[1:]}


def test_supplier_csv_export_forbidden_for_unprivileged(client, db_session, seeded, auth_headers):
    # The Customer role holds no module permissions and must be denied.
    resp = client.get(SUPPLIER_EXPORT, headers=auth_headers("customer"))
    assert resp.status_code == 403, resp.text


def test_audit_csv_export_forbidden_for_unprivileged(client, db_session, seeded, auth_headers):
    resp = client.get(AUDIT_EXPORT, headers=auth_headers("customer"))
    assert resp.status_code == 403, resp.text


def test_risk_csv_export_forbidden_for_unprivileged(client, db_session, seeded, auth_headers):
    resp = client.get(RISK_EXPORT, headers=auth_headers("customer"))
    assert resp.status_code == 403, resp.text


def test_complaint_csv_export_forbidden_for_unprivileged(client, db_session, seeded, auth_headers):
    resp = client.get(COMPLAINT_EXPORT, headers=auth_headers("customer"))
    assert resp.status_code == 403, resp.text
