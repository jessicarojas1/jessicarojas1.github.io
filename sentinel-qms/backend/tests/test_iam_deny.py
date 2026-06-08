"""IAM deny: unassign a role-granted permission for a user."""

from __future__ import annotations


def test_deny_removes_role_granted_permission(client, seeded, auth_headers):
    admin = auth_headers("admin")
    mgr_id = seeded["users"]["manager"].id

    rows = client.get("/api/v1/iam/users", headers=admin).json()
    mgr = next(u for u in rows if u["id"] == mgr_id)
    assert "nonconformances.close" in mgr["role_default"]
    assert "nonconformances.close" in mgr["effective"]

    # Deny it -> drops out of effective + appears in denied.
    resp = client.put(
        f"/api/v1/iam/users/{mgr_id}",
        json={"granted": [], "denied": ["nonconformances.close"]},
        headers=admin,
    )
    assert resp.status_code == 200, resp.text
    body = resp.json()
    assert "nonconformances.close" in body["denied"]
    assert "nonconformances.close" not in body["effective"]

    # The denied permission is gone from the manager's own effective set.
    me = client.get("/api/v1/iam/me", headers=auth_headers("manager")).json()
    assert "nonconformances.close" not in me["permissions"]

    # Lifting the deny restores it.
    resp2 = client.put(
        f"/api/v1/iam/users/{mgr_id}", json={"granted": [], "denied": []}, headers=admin
    )
    assert "nonconformances.close" in resp2.json()["effective"]
    me2 = client.get("/api/v1/iam/me", headers=auth_headers("manager")).json()
    assert "nonconformances.close" in me2["permissions"]


def test_grant_and_deny_conflict_rejected(client, seeded, auth_headers):
    admin = auth_headers("admin")
    mgr_id = seeded["users"]["manager"].id
    resp = client.put(
        f"/api/v1/iam/users/{mgr_id}",
        json={"granted": ["capa.close"], "denied": ["capa.close"]},
        headers=admin,
    )
    assert resp.status_code in (400, 422)
