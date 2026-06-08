"""Concession / Deviation / Waiver permits."""

from __future__ import annotations


def test_concession_crud_numbering_and_close(client, seeded, auth_headers):
    h = auth_headers("manager")  # ncr:write
    r = client.post(
        "/api/v1/concessions",
        json={
            "title": "Oversize hole permitted",
            "description": "Permit +0.2mm on hole dia for lot",
            "concession_type": "deviation",
            "part_number": "PN-7",
            "quantity": 50,
        },
        headers=h,
    )
    assert r.status_code == 201, r.text
    obj = r.json()
    assert obj["concession_number"].startswith("DEV-")
    assert obj["status"] == "draft"
    cid = obj["id"]

    upd = client.patch(
        f"/api/v1/concessions/{cid}",
        json={"status": "approved", "customer_approved": True},
        headers=h,
    )
    assert upd.status_code == 200 and upd.json()["status"] == "approved"

    # filter by type + status
    lst = client.get("/api/v1/concessions?type=deviation", headers=h).json()
    assert any(x["id"] == cid for x in lst)

    assert client.delete(f"/api/v1/concessions/{cid}", headers=h).status_code == 200
    assert client.get(f"/api/v1/concessions/{cid}", headers=h).status_code == 404


def test_write_requires_ncr_write(client, seeded, auth_headers):
    resp = client.post(
        "/api/v1/concessions",
        json={"title": "x", "description": "y"},
        headers=auth_headers("readonly"),
    )
    assert resp.status_code == 403
    assert client.get("/api/v1/concessions", headers=auth_headers("readonly")).status_code == 200
