"""Record sharing ('Shared with Me')."""

from __future__ import annotations


def test_share_lifecycle(client, seeded, auth_headers):
    mgr = auth_headers("manager")
    eng_id = seeded["users"]["engineer"].id

    created = client.post(
        "/api/v1/shares",
        json={
            "entity_type": "nonconformance",
            "entity_id": "1",
            "label": "NCR-2026-0001 — Bad weld",
            "shared_with_user_id": eng_id,
            "note": "Please review",
        },
        headers=mgr,
    )
    assert created.status_code == 201, created.text
    sid = created.json()["id"]

    # recipient sees it; sharer does not
    eng = auth_headers("engineer")
    mine = client.get("/api/v1/shares/mine", headers=eng).json()
    assert any(s["id"] == sid for s in mine)
    assert client.get("/api/v1/shares/mine", headers=mgr).json() == []

    # recipient can remove it
    assert client.delete(f"/api/v1/shares/{sid}", headers=eng).status_code == 204
    assert client.get("/api/v1/shares/mine", headers=eng).json() == []


def test_share_requires_auth(client, seeded):
    assert client.get("/api/v1/shares/mine").status_code in (401, 403)


def test_share_pdf_authorized_to_recipient(client, seeded, auth_headers):
    mgr = auth_headers("manager")
    eng_id = seeded["users"]["engineer"].id
    nid = client.post(
        "/api/v1/nonconformances",
        json={"title": "Shared NCR", "description": "d", "severity": "major"},
        headers=mgr,
    ).json()["id"]
    sid = client.post(
        "/api/v1/shares",
        json={
            "entity_type": "nonconformance",
            "entity_id": str(nid),
            "label": "NCR shared",
            "shared_with_user_id": eng_id,
        },
        headers=mgr,
    ).json()["id"]

    # recipient can download the branded PDF
    eng = auth_headers("engineer")
    resp = client.get(f"/api/v1/shares/{sid}/pdf", headers=eng)
    assert resp.status_code == 200, resp.text
    assert resp.headers["content-type"] == "application/pdf"
    assert resp.content[:4] == b"%PDF"

    # a non-recipient (readonly) cannot
    other = client.get(f"/api/v1/shares/{sid}/pdf", headers=auth_headers("readonly"))
    assert other.status_code == 404


def test_customer_cannot_self_share_a_record(client, seeded, auth_headers):
    """A Customer (no module access) must not be able to self-issue a share and
    then read a record's PDF — the sharer must be able to view the record."""
    mgr = auth_headers("manager")
    nid = client.post(
        "/api/v1/nonconformances",
        json={"title": "Controlled NCR", "description": "d", "severity": "major"},
        headers=mgr,
    ).json()["id"]

    cust = auth_headers("customer")
    cust_id = seeded["users"]["customer"].id
    resp = client.post(
        "/api/v1/shares",
        json={
            "entity_type": "nonconformance",
            "entity_id": str(nid),
            "label": "trying to exfiltrate",
            "shared_with_user_id": cust_id,
        },
        headers=cust,
    )
    assert resp.status_code == 403, resp.text


def test_customer_can_read_record_shared_by_authorized_user(client, seeded, auth_headers):
    """The legitimate flow still works: an authorized user shares a record with a
    Customer, who can then read exactly that record's PDF."""
    mgr = auth_headers("manager")
    cust_id = seeded["users"]["customer"].id
    nid = client.post(
        "/api/v1/nonconformances",
        json={"title": "Shared with customer", "description": "d", "severity": "minor"},
        headers=mgr,
    ).json()["id"]
    sid = client.post(
        "/api/v1/shares",
        json={
            "entity_type": "nonconformance",
            "entity_id": str(nid),
            "label": "for the customer",
            "shared_with_user_id": cust_id,
        },
        headers=mgr,
    ).json()["id"]

    resp = client.get(f"/api/v1/shares/{sid}/pdf", headers=auth_headers("customer"))
    assert resp.status_code == 200, resp.text
    assert resp.content[:4] == b"%PDF"


def test_share_rejects_unknown_entity_type(client, seeded, auth_headers):
    resp = client.post(
        "/api/v1/shares",
        json={
            "entity_type": "secret_vault",
            "entity_id": "1",
            "label": "nope",
            "shared_with_user_id": seeded["users"]["engineer"].id,
        },
        headers=auth_headers("manager"),
    )
    assert resp.status_code == 422, resp.text
