"""RBAC enforcement tests."""
from __future__ import annotations


def test_readonly_cannot_create_ncr(client, seeded, auth_headers):
    headers = auth_headers("readonly")
    resp = client.post(
        "/api/v1/nonconformances",
        json={"title": "x", "description": "y", "severity": "minor"},
        headers=headers,
    )
    assert resp.status_code == 403


def test_readonly_can_read(client, seeded, auth_headers):
    headers = auth_headers("readonly")
    assert client.get("/api/v1/nonconformances", headers=headers).status_code == 200


def test_only_admin_manages_users(client, seeded, auth_headers):
    assert client.get("/api/v1/users", headers=auth_headers("engineer")).status_code == 403
    assert client.get("/api/v1/users", headers=auth_headers("admin")).status_code == 200


def test_engineer_cannot_manage_suppliers_write(client, seeded, auth_headers):
    # Quality Engineer lacks supplier:write per the role matrix.
    headers = auth_headers("engineer")
    resp = client.post(
        "/api/v1/suppliers",
        json={"name": "New Supplier", "status": "prospective"},
        headers=headers,
    )
    assert resp.status_code == 403


def test_manager_can_create_supplier(client, seeded, auth_headers):
    headers = auth_headers("manager")
    resp = client.post(
        "/api/v1/suppliers",
        json={"name": "New Supplier", "status": "prospective"},
        headers=headers,
    )
    assert resp.status_code == 201, resp.text
    assert resp.json()["supplier_code"].startswith("SUP-")
