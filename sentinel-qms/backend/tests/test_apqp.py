"""APQP/PPAP (AS9145) module: projects, auto-seeded PPAP elements, progress."""

from __future__ import annotations


def test_create_project_seeds_ppap_and_progress(client, seeded, auth_headers):
    mgr = auth_headers("manager")  # has inspection:write
    r = client.post(
        "/api/v1/apqp",
        json={"part_number": "PN-900", "part_name": "Bracket", "customer": "Boeing"},
        headers=mgr,
    )
    assert r.status_code == 201, r.text
    proj = r.json()
    assert proj["project_number"].startswith("APQP-")
    assert proj["current_phase"] == "planning"
    assert len(proj["elements"]) == 18  # standard PPAP package
    assert proj["ppap"]["approved_pct"] == 0.0

    # Approve one element + mark one N/A → progress reflects applicable base.
    eid = proj["elements"][0]["id"]
    na_id = proj["elements"][1]["id"]
    client.patch(f"/api/v1/apqp/elements/{eid}", json={"status": "approved"}, headers=mgr)
    client.patch(f"/api/v1/apqp/elements/{na_id}", json={"status": "not_applicable"}, headers=mgr)
    detail = client.get(f"/api/v1/apqp/{proj['id']}", headers=mgr).json()
    # 1 approved / 17 applicable
    assert detail["ppap"]["applicable"] == 17
    assert detail["ppap"]["approved"] == 1
    assert detail["ppap"]["approved_pct"] == round(1 / 17 * 100, 1)


def test_phase_advance_and_list_filter(client, seeded, auth_headers):
    mgr = auth_headers("manager")
    pid = client.post(
        "/api/v1/apqp",
        json={"part_number": "PN-901", "part_name": "Housing"},
        headers=mgr,
    ).json()["id"]
    upd = client.patch(f"/api/v1/apqp/{pid}", json={"current_phase": "validation"}, headers=mgr)
    assert upd.status_code == 200 and upd.json()["current_phase"] == "validation"
    lst = client.get("/api/v1/apqp?phase=validation", headers=mgr).json()
    assert any(p["id"] == pid for p in lst)


def test_write_requires_inspection_write(client, seeded, auth_headers):
    resp = client.post(
        "/api/v1/apqp",
        json={"part_number": "x", "part_name": "y"},
        headers=auth_headers("readonly"),
    )
    assert resp.status_code == 403
    assert client.get("/api/v1/apqp", headers=auth_headers("readonly")).status_code == 200


def test_apqp_contract_link(client, seeded, auth_headers):
    h = auth_headers("manager")
    cust = client.post(
        "/api/v1/customers", json={"code": "C-APQP", "name": "Cust"}, headers=h
    ).json()["id"]
    con = client.post(
        "/api/v1/customers/contracts",
        json={"contract_number": "K-1", "customer_id": cust, "title": "T"},
        headers=h,
    ).json()["id"]
    pid = client.post(
        "/api/v1/apqp",
        json={"part_number": "PN-K", "part_name": "Part", "contract_id": con},
        headers=h,
    ).json()["id"]
    detail = client.get(f"/api/v1/apqp/{pid}", headers=h).json()
    assert detail["contract_id"] == con
    # can be re-linked / cleared via patch
    upd = client.patch(f"/api/v1/apqp/{pid}", json={"contract_id": None}, headers=h)
    assert upd.json()["contract_id"] is None
