"""Concept -> Work In Progress requires author, reviewer and approver assignees.

The three assignees are optional while a document is a Concept, so it can be
created with nothing but a title and type. They become mandatory only on the
transition out of Concept into Work In Progress.
"""

from __future__ import annotations


def _create_concept(client, headers, **extra) -> dict:
    resp = client.post(
        "/api/v1/documents",
        json={"title": "New Procedure", "doc_type": "procedure", **extra},
        headers=headers,
    )
    assert resp.status_code == 201, resp.text
    return resp.json()


def test_create_leaves_document_in_concept_without_assignees(client, seeded, auth_headers):
    h = auth_headers("manager")
    doc = _create_concept(client, h)
    assert doc["status"] == "concept"
    assert doc["author_id"] is None
    assert doc["reviewer_id"] is None
    assert doc["approver_id"] is None


def test_advance_from_concept_blocked_without_assignees(client, seeded, auth_headers):
    h = auth_headers("manager")
    doc = _create_concept(client, h)
    resp = client.post(
        f"/api/v1/documents/{doc['id']}/transition",
        json={"action": "advance"},
        headers=h,
    )
    assert resp.status_code == 409, resp.text
    msg = resp.json()["error"]["message"]
    assert "author" in msg and "reviewer" in msg and "approver" in msg
    # The document must be untouched by a rejected transition.
    after = client.get(f"/api/v1/documents/{doc['id']}", headers=h).json()
    assert after["status"] == "concept"


def test_partial_assignees_report_only_the_missing_ones(client, seeded, auth_headers):
    h = auth_headers("manager")
    users = seeded["users"]
    doc = _create_concept(client, h)
    # Assign only the author; reviewer and approver are still missing.
    patched = client.patch(
        f"/api/v1/documents/{doc['id']}",
        json={"author_id": users["engineer"].id},
        headers=h,
    )
    assert patched.status_code == 200, patched.text
    resp = client.post(
        f"/api/v1/documents/{doc['id']}/transition",
        json={"action": "advance"},
        headers=h,
    )
    assert resp.status_code == 409, resp.text
    msg = resp.json()["error"]["message"]
    assert "author" not in msg  # already assigned, so not reported
    assert "reviewer" in msg and "approver" in msg


def test_advance_succeeds_once_all_three_are_assigned(client, seeded, auth_headers):
    h = auth_headers("manager")
    users = seeded["users"]
    doc = _create_concept(client, h)
    client.patch(
        f"/api/v1/documents/{doc['id']}",
        json={
            "author_id": users["engineer"].id,
            "reviewer_id": users["manager"].id,
            "approver_id": users["admin"].id,
        },
        headers=h,
    )
    resp = client.post(
        f"/api/v1/documents/{doc['id']}/transition",
        json={"action": "advance"},
        headers=h,
    )
    assert resp.status_code == 200, resp.text
    assert resp.json()["status"] == "work_in_progress"


def test_separation_of_duties_rejected_at_creation(client, seeded, auth_headers):
    h = auth_headers("manager")
    users = seeded["users"]
    resp = client.post(
        "/api/v1/documents",
        json={
            "title": "Conflicted",
            "doc_type": "procedure",
            "author_id": users["engineer"].id,
            "reviewer_id": users["engineer"].id,  # same person as author
        },
        headers=h,
    )
    assert resp.status_code == 409, resp.text
    assert "author" in resp.json()["error"]["message"]
    assert "reviewer" in resp.json()["error"]["message"]


def test_separation_of_duties_rejected_on_update(client, seeded, auth_headers):
    h = auth_headers("manager")
    users = seeded["users"]
    doc = _create_concept(client, h, author_id=users["engineer"].id)
    resp = client.patch(
        f"/api/v1/documents/{doc['id']}",
        json={"approver_id": users["engineer"].id},  # same as the author
        headers=h,
    )
    assert resp.status_code == 409, resp.text
    assert "approver" in resp.json()["error"]["message"]


def test_assignees_may_be_set_at_creation(client, seeded, auth_headers):
    h = auth_headers("manager")
    users = seeded["users"]
    doc = _create_concept(
        client,
        h,
        author_id=users["engineer"].id,
        reviewer_id=users["manager"].id,
        approver_id=users["admin"].id,
    )
    assert doc["status"] == "concept"
    assert doc["author_id"] == users["engineer"].id
    resp = client.post(
        f"/api/v1/documents/{doc['id']}/transition",
        json={"action": "advance"},
        headers=h,
    )
    assert resp.status_code == 200, resp.text
    assert resp.json()["status"] == "work_in_progress"
