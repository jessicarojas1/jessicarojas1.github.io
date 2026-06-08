"""Universal 'create CAPA from source' (NCR, complaint, audit finding)."""

from __future__ import annotations


def _make_ncr(client, h):
    return client.post(
        "/api/v1/nonconformances",
        json={"title": "Bad weld", "description": "Crack found", "severity": "major"},
        headers=h,
    ).json()["id"]


def test_create_capa_from_ncr(client, seeded, auth_headers):
    h = auth_headers("manager")
    nid = _make_ncr(client, h)
    r = client.post(f"/api/v1/nonconformances/{nid}/create-capa", headers=h)
    assert r.status_code == 200, r.text
    assert r.json()["capa_number"].startswith("CAPA-")
    # NCR now linked
    ncr = client.get(f"/api/v1/nonconformances/{nid}", headers=h).json()
    assert ncr["capa_id"] == r.json()["capa_id"]
    # CAPA exists, corrective, open
    capa = client.get(f"/api/v1/capa/{r.json()['capa_id']}", headers=h).json()
    assert capa["capa_type"] == "corrective" and capa["status"] == "open"
    # second attempt conflicts
    assert client.post(f"/api/v1/nonconformances/{nid}/create-capa", headers=h).status_code == 409


def test_create_capa_from_complaint(client, seeded, auth_headers):
    h = auth_headers("manager")
    cid = client.post(
        "/api/v1/complaints",
        json={
            "title": "Late + defective",
            "description": "Customer reported scratches",
            "customer_name": "Acme Aero",
        },
        headers=h,
    ).json()["id"]
    r = client.post(f"/api/v1/complaints/{cid}/create-capa", headers=h)
    assert r.status_code == 200, r.text
    c = client.get(f"/api/v1/complaints/{cid}", headers=h).json()
    assert c["capa_id"] == r.json()["capa_id"]


def test_create_capa_from_finding(client, seeded, auth_headers):
    h = auth_headers("manager")
    aid = client.post(
        "/api/v1/audits",
        json={"title": "Internal audit", "audit_type": "internal"},
        headers=h,
    ).json()["id"]
    fid = client.post(
        f"/api/v1/audits/{aid}/findings",
        json={"finding_type": "major_nonconformity", "description": "Procedure not followed"},
        headers=h,
    ).json()["id"]
    r = client.post(f"/api/v1/audits/findings/{fid}/create-capa", headers=h)
    assert r.status_code == 200, r.text
    assert r.json()["capa_number"].startswith("CAPA-")
    assert client.post(f"/api/v1/audits/findings/{fid}/create-capa", headers=h).status_code == 409
