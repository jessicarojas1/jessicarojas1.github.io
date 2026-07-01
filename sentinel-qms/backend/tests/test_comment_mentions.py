"""Server-side hardening of comment @mentions.

The client-supplied ``mentions`` list is untrusted: mentions are re-derived from
@email tokens in the body plus *validated* client ids, resolved to existing
active users who can view the entity, with the author excluded. These are the
ids actually notified and audited.
"""

from __future__ import annotations

from app.models.user import Notification


def _notifications_for(db_session, user_id: int) -> list[Notification]:
    return [n for n in db_session.query(Notification).all() if n.user_id == user_id]


def _create_ncr(client, headers):
    return client.post(
        "/api/v1/nonconformances",
        json={"title": "Defect", "description": "Out of spec."},
        headers=headers,
    )


def test_email_mention_in_body_notifies_that_user(client, seeded, db_session, auth_headers):
    eng = auth_headers("engineer")
    ncr_id = _create_ncr(client, eng).json()["id"]
    manager = seeded["users"]["manager"]

    resp = client.post(
        "/api/v1/comments",
        json={
            "entity_type": "nonconformance",
            "entity_id": str(ncr_id),
            "body": f"Please review @{manager.email} — MRB needed.",
            # No client-supplied mentions: the server parses the body.
            "mentions": [],
        },
        headers=eng,
    )
    assert resp.status_code == 201, resp.text

    notifs = _notifications_for(db_session, manager.id)
    assert any(n.title == "You were mentioned" for n in notifs)


def test_bogus_client_supplied_id_is_dropped(client, seeded, db_session, auth_headers):
    eng = auth_headers("engineer")
    ncr_id = _create_ncr(client, eng).json()["id"]

    resp = client.post(
        "/api/v1/comments",
        json={
            "entity_type": "nonconformance",
            "entity_id": str(ncr_id),
            "body": "No email mention here.",
            "mentions": [999999],  # nonexistent user id
        },
        headers=eng,
    )
    assert resp.status_code == 201, resp.text

    # The nonexistent user gets nothing, and nothing is spuriously created.
    assert _notifications_for(db_session, 999999) == []


def test_author_self_mention_creates_no_notification(client, seeded, db_session, auth_headers):
    eng = auth_headers("engineer")
    author = seeded["users"]["engineer"]
    ncr_id = _create_ncr(client, eng).json()["id"]

    before = len(_notifications_for(db_session, author.id))
    resp = client.post(
        "/api/v1/comments",
        json={
            "entity_type": "nonconformance",
            "entity_id": str(ncr_id),
            "body": f"Note to self @{author.email}.",
            "mentions": [author.id],
        },
        headers=eng,
    )
    assert resp.status_code == 201, resp.text

    after = len(_notifications_for(db_session, author.id))
    assert after == before, "author must not be notified for a self-mention"


def test_mention_without_view_access_is_skipped(client, seeded, db_session, auth_headers):
    """A customer (no NCR view access) mentioned by email is not notified."""
    eng = auth_headers("engineer")
    customer = seeded["users"]["customer"]
    ncr_id = _create_ncr(client, eng).json()["id"]

    resp = client.post(
        "/api/v1/comments",
        json={
            "entity_type": "nonconformance",
            "entity_id": str(ncr_id),
            "body": f"FYI @{customer.email}",
            "mentions": [customer.id],
        },
        headers=eng,
    )
    assert resp.status_code == 201, resp.text
    assert _notifications_for(db_session, customer.id) == []
