"""SCAR -> CAPA linkage."""

from __future__ import annotations


def _make_supplier(client, h):
    return client.post(
        "/api/v1/suppliers",
        json={"supplier_code": "SUP-CAPA", "name": "Acme"},
        headers=h,
    ).json()["id"]


def test_create_capa_from_scar(client, seeded, auth_headers):
    h = auth_headers("manager")
    sid = _make_supplier(client, h)
    scar = client.post(
        f"/api/v1/suppliers/{sid}/scars",
        json={"title": "Late parts", "description": "Repeated late shipments"},
        headers=h,
    )
    assert scar.status_code == 201, scar.text
    scar_id = scar.json()["id"]
    r = client.post(f"/api/v1/suppliers/scars/{scar_id}/create-capa", headers=h)
    assert r.status_code == 200, r.text
    assert r.json()["capa_number"].startswith("CAPA-")
    # second attempt conflicts
    assert (
        client.post(f"/api/v1/suppliers/scars/{scar_id}/create-capa", headers=h).status_code == 409
    )
