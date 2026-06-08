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
