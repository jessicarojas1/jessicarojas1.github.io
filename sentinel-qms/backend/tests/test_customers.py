"""Customer & contract register with requirement flow-down."""

from __future__ import annotations


def test_customer_contract_flow(client, seeded, auth_headers):
    h = auth_headers("manager")  # supplier:write
    cust = client.post(
        "/api/v1/customers",
        json={"code": "CUST-1", "name": "Boeing", "country": "USA"},
        headers=h,
    )
    assert cust.status_code == 201, cust.text
    cid = cust.json()["id"]
    assert (
        client.post(
            "/api/v1/customers", json={"code": "CUST-1", "name": "dup"}, headers=h
        ).status_code
        == 409
    )

    con = client.post(
        "/api/v1/customers/contracts",
        json={
            "contract_number": "C-100",
            "customer_id": cid,
            "title": "F-15 spares",
            "dpas_rating": "DO-A1",
            "itar_controlled": True,
        },
        headers=h,
    )
    assert con.status_code == 201, con.text
    con_id = con.json()["id"]
    assert con.json()["itar_controlled"] is True

    req = client.post(
        f"/api/v1/customers/contracts/{con_id}/requirements",
        json={"clause": "3.2.1", "description": "AS9100 flow-down", "flow_down_to": "supplier"},
        headers=h,
    )
    assert req.status_code == 201, req.text
    rid = req.json()["id"]
    client.patch(f"/api/v1/customers/requirements/{rid}", json={"status": "flowed_down"}, headers=h)

    detail = client.get(f"/api/v1/customers/contracts/{con_id}", headers=h).json()
    assert len(detail["requirements"]) == 1
    assert detail["requirements"][0]["status"] == "flowed_down"

    # customer list shows contract_count
    custs = client.get("/api/v1/customers", headers=h).json()
    assert any(c["id"] == cid and c["contract_count"] == 1 for c in custs)


def test_write_requires_supplier_write(client, seeded, auth_headers):
    resp = client.post(
        "/api/v1/customers", json={"code": "X", "name": "Y"}, headers=auth_headers("engineer")
    )
    assert resp.status_code == 403
    assert client.get("/api/v1/customers", headers=auth_headers("engineer")).status_code == 200
