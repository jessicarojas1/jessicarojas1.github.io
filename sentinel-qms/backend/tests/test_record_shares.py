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
