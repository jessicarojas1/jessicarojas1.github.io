"""Records Retention & Disposition Schedule: CRUD, filters, RBAC, legal hold, audit."""

from __future__ import annotations

from sqlalchemy import select

from app.models.user import AuditLog


def _create(client, headers, **over):
    payload = {
        "title": "Quality records retention",
        "record_category": "quality_records",
        "retention_trigger": "closure",
        "retention_years": 7,
        "disposition_action": "review",
        "authority_reference": "AS9100D 7.5.3",
        **over,
    }
    return client.post("/api/v1/retention-policies", json=payload, headers=headers)


def test_create_and_get(client, seeded, auth_headers):
    h = auth_headers("engineer")
    resp = _create(client, h)
    assert resp.status_code == 201, resp.text
    body = resp.json()
    assert body["policy_number"].startswith("RET-")
    assert body["status"] == "draft"
    assert body["retention_years"] == 7
    got = client.get(f"/api/v1/retention-policies/{body['id']}", headers=h).json()
    assert got["authority_reference"] == "AS9100D 7.5.3"


def test_create_writes_audit_row(client, seeded, auth_headers, db_session):
    h = auth_headers("engineer")
    pid = _create(client, h).json()["id"]
    row = (
        db_session.execute(
            select(AuditLog)
            .where(AuditLog.entity_type == "retention_policy")
            .where(AuditLog.entity_id == str(pid))
            .where(AuditLog.action == "create")
        )
        .scalars()
        .first()
    )
    assert row is not None


def test_filters_and_search(client, seeded, auth_headers):
    h = auth_headers("engineer")
    _create(client, h, title="Design records", record_category="design_records")
    _create(client, h, title="Supplier records", record_category="supplier_records")
    by_cat = client.get("/api/v1/retention-policies?category=design_records", headers=h).json()
    assert all(x["record_category"] == "design_records" for x in by_cat) and len(by_cat) >= 1
    found = client.get("/api/v1/retention-policies?search=Supplier", headers=h).json()
    assert any("supplier" in x["title"].lower() for x in found)


def test_update(client, seeded, auth_headers):
    h = auth_headers("engineer")
    pid = _create(client, h).json()["id"]
    upd = client.patch(
        f"/api/v1/retention-policies/{pid}",
        json={"status": "active", "retention_years": 10},
        headers=h,
    ).json()
    assert upd["status"] == "active"
    assert upd["retention_years"] == 10


def test_legal_hold_round_trips(client, seeded, auth_headers):
    h = auth_headers("engineer")
    pid = _create(client, h, legal_hold=True).json()["id"]
    got = client.get(f"/api/v1/retention-policies/{pid}", headers=h).json()
    assert got["legal_hold"] is True
    # Toggling it back off round-trips too.
    off = client.patch(
        f"/api/v1/retention-policies/{pid}", json={"legal_hold": False}, headers=h
    ).json()
    assert off["legal_hold"] is False


def test_permanent_policy_allows_null_retention(client, seeded, auth_headers):
    h = auth_headers("engineer")
    resp = _create(client, h, disposition_action="permanent", retention_years=None)
    assert resp.status_code == 201, resp.text
    body = resp.json()
    assert body["disposition_action"] == "permanent"
    assert body["retention_years"] is None


def test_soft_delete(client, seeded, auth_headers):
    h = auth_headers("engineer")
    pid = _create(client, h).json()["id"]
    assert client.delete(f"/api/v1/retention-policies/{pid}", headers=h).status_code == 204
    assert client.get(f"/api/v1/retention-policies/{pid}", headers=h).status_code == 404


def test_rbac_read_only_cannot_write(client, seeded, auth_headers):
    assert (
        client.get("/api/v1/retention-policies", headers=auth_headers("readonly")).status_code
        == 200
    )
    assert _create(client, auth_headers("readonly")).status_code == 403


def test_rbac_customer_cannot_read(client, seeded, auth_headers):
    assert (
        client.get("/api/v1/retention-policies", headers=auth_headers("customer")).status_code
        == 403
    )
