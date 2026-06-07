"""Counterfeit-parts prevention: sourcing records + alert log."""

from __future__ import annotations


def test_sourcing_crud_and_numbering(client, seeded, auth_headers):
    mgr = auth_headers("manager")  # has supplier:write
    r = client.post(
        "/api/v1/counterfeit/sourcing",
        json={"part_number": "PN-555", "source_type": "broker", "risk_level": "high"},
        headers=mgr,
    )
    assert r.status_code == 201, r.text
    rec = r.json()
    assert rec["record_number"].startswith("CFP-")
    assert rec["status"] == "pending"

    rid = rec["id"]
    upd = client.patch(
        f"/api/v1/counterfeit/sourcing/{rid}",
        json={"status": "verified", "coc_received": True},
        headers=mgr,
    )
    assert upd.status_code == 200
    assert upd.json()["status"] == "verified"
    assert upd.json()["coc_received"] is True

    lst = client.get("/api/v1/counterfeit/sourcing?status=verified", headers=mgr).json()
    assert any(x["id"] == rid for x in lst)

    assert client.delete(f"/api/v1/counterfeit/sourcing/{rid}", headers=mgr).status_code == 200
    assert client.get(f"/api/v1/counterfeit/sourcing/{rid}", headers=mgr).status_code == 404


def test_alert_crud(client, seeded, auth_headers):
    mgr = auth_headers("manager")
    a = client.post(
        "/api/v1/counterfeit/alerts",
        json={
            "title": "Suspect capacitors",
            "source": "gidep",
            "external_ref": "GIDEP-X1",
            "part_numbers": "CAP-1, CAP-2",
            "affects_inventory": True,
        },
        headers=mgr,
    )
    assert a.status_code == 201, a.text
    assert a.json()["alert_number"].startswith("CFA-")
    aid = a.json()["id"]
    upd = client.patch(
        f"/api/v1/counterfeit/alerts/{aid}",
        json={"status": "under_assessment", "impact_assessment": "Checking 3 lots."},
        headers=mgr,
    )
    assert upd.status_code == 200 and upd.json()["status"] == "under_assessment"


def test_write_requires_supplier_write(client, seeded, auth_headers):
    # Quality Engineer lacks supplier:write.
    resp = client.post(
        "/api/v1/counterfeit/alerts",
        json={"title": "x"},
        headers=auth_headers("engineer"),
    )
    assert resp.status_code == 403
    # but can read
    assert (
        client.get("/api/v1/counterfeit/alerts", headers=auth_headers("engineer")).status_code
        == 200
    )
