"""Internal audit program (annual schedule)."""

from __future__ import annotations


def test_program_with_items_and_progress(client, seeded, auth_headers):
    h = auth_headers("manager")  # audit:write
    p = client.post(
        "/api/v1/audit-programs",
        json={
            "name": "2026 Internal Audit Program",
            "year": 2026,
            "objectives": "Cover all clauses",
        },
        headers=h,
    )
    assert p.status_code == 201, p.text
    pid = p.json()["id"]

    for area, period in [("Receiving Inspection", "2026-Q1"), ("Calibration", "2026-Q2")]:
        r = client.post(
            f"/api/v1/audit-programs/{pid}/items",
            json={"area": area, "planned_period": period, "clause_reference": "7.1.5"},
            headers=h,
        )
        assert r.status_code == 201, r.text

    detail = client.get(f"/api/v1/audit-programs/{pid}", headers=h).json()
    assert detail["progress"]["total"] == 2
    assert detail["progress"]["completed"] == 0

    iid = detail["items"][0]["id"]
    client.patch(f"/api/v1/audit-programs/items/{iid}", json={"status": "completed"}, headers=h)
    detail2 = client.get(f"/api/v1/audit-programs/{pid}", headers=h).json()
    assert detail2["progress"]["completed"] == 1
    assert detail2["progress"]["completed_pct"] == 50.0

    # list shows the program with progress
    lst = client.get("/api/v1/audit-programs", headers=h).json()
    assert any(x["id"] == pid for x in lst)


def test_write_requires_audit_write(client, seeded, auth_headers):
    resp = client.post(
        "/api/v1/audit-programs", json={"name": "x", "year": 2026}, headers=auth_headers("readonly")
    )
    assert resp.status_code == 403
    assert client.get("/api/v1/audit-programs", headers=auth_headers("readonly")).status_code == 200
