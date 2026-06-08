"""Per-user saved list views."""

from __future__ import annotations


def test_saved_view_lifecycle(client, seeded, auth_headers):
    h = auth_headers("manager")
    created = client.post(
        "/api/v1/saved-views",
        json={
            "page_key": "nonconformances",
            "name": "My open criticals",
            "params": {"status": "open", "severity": "critical", "sort": "created_at"},
        },
        headers=h,
    )
    assert created.status_code == 201, created.text
    vid = created.json()["id"]
    assert created.json()["params"]["severity"] == "critical"

    lst = client.get("/api/v1/saved-views?page_key=nonconformances", headers=h).json()
    assert any(v["id"] == vid for v in lst)
    # different page is isolated
    assert client.get("/api/v1/saved-views?page_key=capa", headers=h).json() == []

    assert client.delete(f"/api/v1/saved-views/{vid}", headers=h).status_code == 204
    assert client.get("/api/v1/saved-views?page_key=nonconformances", headers=h).json() == []


def test_saved_views_are_per_user(client, seeded, auth_headers):
    mgr = auth_headers("manager")
    eng = auth_headers("engineer")
    client.post(
        "/api/v1/saved-views",
        json={"page_key": "capa", "name": "mine", "params": {}},
        headers=mgr,
    )
    # engineer sees none of manager's views
    assert client.get("/api/v1/saved-views?page_key=capa", headers=eng).json() == []
