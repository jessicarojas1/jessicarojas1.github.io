"""Management-review clause 9.3 auto-input compilation."""

from __future__ import annotations


def _create_review(client, headers):
    return client.post(
        "/api/v1/management-reviews",
        json={"title": "Q2 Management Review"},
        headers=headers,
    )


def test_auto_inputs_compiles_and_is_idempotent(client, seeded, auth_headers):
    headers = auth_headers("manager")
    review_id = _create_review(client, headers).json()["id"]

    r1 = client.post(f"/api/v1/management-reviews/{review_id}/auto-inputs", headers=headers)
    assert r1.status_code == 201, r1.text
    inputs = r1.json()
    assert len(inputs) >= 5
    cats = {i["category"] for i in inputs}
    assert "Nonconformities & Corrective Actions" in cats
    assert "Internal Audit Results" in cats

    # Re-running replaces rather than duplicates the auto rows.
    r2 = client.post(f"/api/v1/management-reviews/{review_id}/auto-inputs", headers=headers)
    assert r2.status_code == 201
    detail = client.get(f"/api/v1/management-reviews/{review_id}", headers=headers).json()
    auto = [i for i in detail["inputs"] if i["category"] in cats]
    assert len(auto) == len(inputs)


def test_auto_inputs_requires_edit_permission(client, seeded, auth_headers):
    mgr = auth_headers("manager")
    review_id = _create_review(client, mgr).json()["id"]
    resp = client.post(
        f"/api/v1/management-reviews/{review_id}/auto-inputs",
        headers=auth_headers("readonly"),
    )
    assert resp.status_code == 403
