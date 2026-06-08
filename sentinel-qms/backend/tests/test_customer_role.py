"""The dedicated read-only Customer role.

A Customer is an internal authenticated principal with NO standing module
access — their only surface is "Shared with Me" (the shares endpoints, gated by
share ownership). These tests lock that contract in place.
"""

from __future__ import annotations

from app.core.iam import role_default_permissions
from app.core.permissions import default_level_for
from app.core.rbac import Role


def test_customer_role_has_no_default_permissions():
    assert role_default_permissions(["Customer"]) == set()


def test_customer_page_levels_are_all_none():
    # Even pages with no read-permission gate (e.g. Documentation) must not leak.
    for page_key in ("dashboard", "documentation", "nonconformances", "users"):
        assert default_level_for(Role.CUSTOMER, page_key) == "none"


def test_customer_permissions_me_is_empty(client, seeded, auth_headers):
    resp = client.get("/api/v1/permissions/me", headers=auth_headers("customer"))
    assert resp.status_code == 200, resp.text
    # Only non-"none" levels are returned, so a customer sees nothing.
    assert resp.json() == {}


def test_customer_cannot_read_modules(client, seeded, auth_headers):
    resp = client.get("/api/v1/nonconformances", headers=auth_headers("customer"))
    assert resp.status_code == 403


def test_customer_can_access_shared_inbox(client, seeded, auth_headers):
    resp = client.get("/api/v1/shares/mine", headers=auth_headers("customer"))
    assert resp.status_code == 200, resp.text
    assert resp.json() == []
