"""MSA / Gage R&R studies."""

from __future__ import annotations


def test_msa_result_derivation(client, seeded, auth_headers):
    h = auth_headers("manager")  # calibration:write
    # acceptable (<10%)
    a = client.post(
        "/api/v1/msa-studies",
        json={"characteristic": "Bore dia", "study_type": "gage_rr", "grr_percent": 8.5, "ndc": 6},
        headers=h,
    )
    assert a.status_code == 201, a.text
    assert a.json()["study_number"].startswith("MSA-")
    assert a.json()["result"] == "acceptable"

    # marginal (10-30%)
    m = client.post(
        "/api/v1/msa-studies",
        json={"characteristic": "Length", "grr_percent": 22},
        headers=h,
    )
    assert m.json()["result"] == "marginal"

    # unacceptable (>30%)
    u = client.post(
        "/api/v1/msa-studies",
        json={"characteristic": "Angle", "grr_percent": 45},
        headers=h,
    )
    uid = u.json()["id"]
    assert u.json()["result"] == "unacceptable"

    # update grr re-derives result
    upd = client.patch(f"/api/v1/msa-studies/{uid}", json={"grr_percent": 5}, headers=h)
    assert upd.json()["result"] == "acceptable"

    flt = client.get("/api/v1/msa-studies?result=marginal", headers=h).json()
    assert all(s["result"] == "marginal" for s in flt)


def test_write_requires_calibration_write(client, seeded, auth_headers):
    resp = client.post(
        "/api/v1/msa-studies", json={"characteristic": "x"}, headers=auth_headers("readonly")
    )
    assert resp.status_code == 403
    assert client.get("/api/v1/msa-studies", headers=auth_headers("readonly")).status_code == 200
