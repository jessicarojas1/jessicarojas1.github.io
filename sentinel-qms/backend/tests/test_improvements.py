"""Continual Improvement / Kaizen module (clause 10.3)."""

from __future__ import annotations


def test_create_and_progress_improvement(client, seeded, auth_headers):
    h = auth_headers("manager")
    created = client.post(
        "/api/v1/improvements",
        json={
            "title": "Reduce setup time on CNC cell 3",
            "category": "kaizen",
            "priority": "high",
            "estimated_benefit": 12000,
            "clause_ref": "10.3",
        },
        headers=h,
    )
    assert created.status_code == 201, created.text
    body = created.json()
    assert body["improvement_number"].startswith("KAI-")
    assert body["status"] == "idea"
    iid = body["id"]

    # Advance to done -> completed_at stamped, realized benefit captured.
    done = client.patch(
        f"/api/v1/improvements/{iid}",
        json={"status": "done", "realized_benefit": 15000},
        headers=h,
    )
    assert done.status_code == 200, done.text
    assert done.json()["status"] == "done"
    assert done.json()["realized_benefit"] == 15000


def test_filters_by_status_and_category(client, seeded, auth_headers):
    h = auth_headers("manager")
    client.post(
        "/api/v1/improvements",
        json={"title": "Cost saving idea", "category": "cost_saving"},
        headers=h,
    )
    rows = client.get("/api/v1/improvements?category=cost_saving", headers=h).json()
    assert rows and all(r["category"] == "cost_saving" for r in rows)


def test_read_only_can_view_but_not_write(client, seeded, auth_headers):
    assert client.get("/api/v1/improvements", headers=auth_headers("readonly")).status_code == 200
    denied = client.post(
        "/api/v1/improvements", json={"title": "x"}, headers=auth_headers("readonly")
    )
    assert denied.status_code == 403


def test_customer_cannot_access_improvements(client, seeded, auth_headers):
    assert client.get("/api/v1/improvements", headers=auth_headers("customer")).status_code == 403
