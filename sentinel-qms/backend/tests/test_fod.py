"""FOD program (AS9146): zones, events, raise-NCR."""

from __future__ import annotations


def test_zone_crud_and_conflict(client, seeded, auth_headers):
    h = auth_headers("manager")  # inspection:write
    z = client.post(
        "/api/v1/fod/zones",
        json={"code": "Z1", "name": "Final Assembly", "risk_level": "high"},
        headers=h,
    )
    assert z.status_code == 201, z.text
    assert (
        client.post("/api/v1/fod/zones", json={"code": "Z1", "name": "dup"}, headers=h).status_code
        == 409
    )
    lst = client.get("/api/v1/fod/zones", headers=h).json()
    assert any(x["code"] == "Z1" for x in lst)


def test_event_lifecycle_and_close(client, seeded, auth_headers):
    h = auth_headers("manager")
    e = client.post(
        "/api/v1/fod/events",
        json={"title": "Lockwire found in wing", "object_type": "lockwire", "severity": "high"},
        headers=h,
    )
    assert e.status_code == 201, e.text
    eid = e.json()["id"]
    assert e.json()["event_number"].startswith("FOD-")
    assert e.json()["status"] == "open"
    upd = client.patch(
        f"/api/v1/fod/events/{eid}",
        json={"status": "closed", "root_cause": "tool control gap"},
        headers=h,
    )
    assert upd.status_code == 200 and upd.json()["status"] == "closed"
    open_only = client.get("/api/v1/fod/events?status=open", headers=h).json()
    assert all(x["id"] != eid for x in open_only)


def test_raise_ncr_from_event(client, seeded, auth_headers):
    h = auth_headers("manager")
    eid = client.post("/api/v1/fod/events", json={"title": "Debris near engine"}, headers=h).json()[
        "id"
    ]
    r = client.post(f"/api/v1/fod/events/{eid}/raise-ncr", headers=h)
    assert r.status_code == 200, r.text
    assert r.json()["ncr_number"].startswith("NCR-")
    ev = client.get(f"/api/v1/fod/events/{eid}", headers=h).json()
    assert ev["ncr_id"] == r.json()["ncr_id"]
    assert client.post(f"/api/v1/fod/events/{eid}/raise-ncr", headers=h).status_code == 409


def test_write_requires_inspection_write(client, seeded, auth_headers):
    resp = client.post("/api/v1/fod/events", json={"title": "x"}, headers=auth_headers("readonly"))
    assert resp.status_code == 403
    assert client.get("/api/v1/fod/events", headers=auth_headers("readonly")).status_code == 200
