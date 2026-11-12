"""Enterprise document workflow (option A): stage authority, approval permission
and the reject / send-back action.

Role facts the tests rely on (seeded by conftest):
- ``manager`` (Quality Manager) and ``admin`` are *privileged* — they may perform
  any transition (the override that stops anyone being locked out).
- ``engineer`` (Quality Engineer) holds ``documents.edit`` but NOT
  ``documents.approve`` — a non-privileged editor, exactly what the stage-authority
  and approval-permission checks are meant to constrain.
- ``readonly`` / ``customer`` have no document-edit grants; they appear only as
  assignees here, never as the acting user.
"""

from __future__ import annotations


def _create(client, headers, users, author, reviewer, approver) -> dict:
    resp = client.post(
        "/api/v1/documents",
        json={
            "title": "Controlled Procedure",
            "doc_type": "procedure",
            "author_id": users[author].id,
            "reviewer_id": users[reviewer].id,
            "approver_id": users[approver].id,
        },
        headers=headers,
    )
    assert resp.status_code == 201, resp.text
    return resp.json()


def _transition(client, headers, doc_id, payload) -> "object":
    return client.post(f"/api/v1/documents/{doc_id}/transition", json=payload, headers=headers)


def _advance(client, headers, doc_id) -> None:
    resp = _transition(client, headers, doc_id, {"action": "advance"})
    assert resp.status_code == 200, resp.text


def test_privileged_manager_drives_full_ladder_to_approved(client, seeded, auth_headers):
    users = seeded["users"]
    mh = auth_headers("manager")
    doc = _create(client, mh, users, "admin", "readonly", "customer")
    _advance(client, mh, doc["id"])  # concept -> work_in_progress
    _advance(client, mh, doc["id"])  # work_in_progress -> peer_review
    _advance(client, mh, doc["id"])  # peer_review -> qa_review
    resp = _transition(client, mh, doc["id"], {"action": "approve"})
    assert resp.status_code == 200, resp.text
    assert resp.json()["status"] == "approved"


def test_assigned_reviewer_may_advance_their_stage(client, seeded, auth_headers):
    users = seeded["users"]
    mh = auth_headers("manager")
    doc = _create(client, mh, users, "admin", "engineer", "readonly")  # reviewer = engineer
    _advance(client, mh, doc["id"])  # -> work_in_progress
    _advance(client, mh, doc["id"])  # -> peer_review
    # The assigned reviewer (non-privileged, has documents.edit) advances their stage.
    resp = _transition(client, auth_headers("engineer"), doc["id"], {"action": "advance"})
    assert resp.status_code == 200, resp.text
    assert resp.json()["status"] == "qa_review"


def test_unassigned_editor_cannot_advance_a_review_stage(client, seeded, auth_headers):
    users = seeded["users"]
    mh = auth_headers("manager")
    doc = _create(client, mh, users, "admin", "readonly", "customer")  # engineer is NOT assigned
    _advance(client, mh, doc["id"])  # -> work_in_progress
    _advance(client, mh, doc["id"])  # -> peer_review
    resp = _transition(client, auth_headers("engineer"), doc["id"], {"action": "advance"})
    assert resp.status_code == 409, resp.text
    assert "reviewer" in resp.json()["error"]["message"]


def test_approval_requires_the_approve_permission(client, seeded, auth_headers):
    users = seeded["users"]
    mh = auth_headers("manager")
    doc = _create(client, mh, users, "admin", "readonly", "engineer")  # approver = engineer
    _advance(client, mh, doc["id"])  # -> work_in_progress
    _advance(client, mh, doc["id"])  # -> peer_review
    _advance(client, mh, doc["id"])  # -> qa_review
    # engineer is the assigned approver (authority OK) but lacks documents.approve.
    resp = _transition(client, auth_headers("engineer"), doc["id"], {"action": "approve"})
    assert resp.status_code == 409, resp.text
    assert "documents.approve" in resp.json()["error"]["message"]


def test_reject_requires_a_reason_and_returns_to_wip(client, seeded, auth_headers):
    users = seeded["users"]
    mh = auth_headers("manager")
    doc = _create(client, mh, users, "admin", "readonly", "customer")
    _advance(client, mh, doc["id"])  # -> work_in_progress
    _advance(client, mh, doc["id"])  # -> peer_review
    no_reason = _transition(client, mh, doc["id"], {"action": "reject"})
    assert no_reason.status_code == 409, no_reason.text
    assert "reason" in no_reason.json()["error"]["message"].lower()
    sent_back = _transition(
        client, mh, doc["id"], {"action": "reject", "reason": "Needs more detail in scope."}
    )
    assert sent_back.status_code == 200, sent_back.text
    assert sent_back.json()["status"] == "work_in_progress"


def test_reject_is_only_valid_from_a_review_stage(client, seeded, auth_headers):
    users = seeded["users"]
    mh = auth_headers("manager")
    doc = _create(client, mh, users, "admin", "readonly", "customer")
    _advance(client, mh, doc["id"])  # -> work_in_progress (not a review stage)
    resp = _transition(client, mh, doc["id"], {"action": "reject", "reason": "nope"})
    assert resp.status_code == 409, resp.text
