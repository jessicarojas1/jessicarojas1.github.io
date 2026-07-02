"""Optimistic-concurrency (lost-update) guard on CAPA/NCR/Risk PATCH endpoints.

The guard is a conditional update: when the client echoes back the ``updated_at``
it last saw as ``expected_updated_at`` and that value is stale, the write is
rejected with 409 ``stale_write``. Omitting the token preserves the legacy
last-write-wins behavior (back-compat).
"""

from __future__ import annotations

from datetime import UTC, datetime, timedelta

from app.models.capa import Capa


def _create_capa(client, headers):
    return client.post(
        "/api/v1/capa",
        json={
            "title": "Recurring bore oversize",
            "capa_type": "corrective",
            "d2_problem_description": "Bores oversize across lots.",
        },
        headers=headers,
    )


def test_stale_expected_updated_at_conflicts(client, seeded, db_session, auth_headers):
    headers = auth_headers("engineer")
    capa = _create_capa(client, headers).json()

    # Simulate a client that loaded the record earlier: its token predates the
    # record's current updated_at by more than the whole-second compare window.
    stale_token = (datetime.now(UTC) - timedelta(minutes=5)).isoformat()

    conflict = client.patch(
        f"/api/v1/capa/{capa['id']}",
        json={
            "d3_containment": "Quarantine affected lots.",
            "expected_updated_at": stale_token,
        },
        headers=headers,
    )
    assert conflict.status_code == 409, conflict.text
    assert conflict.json()["error"]["code"] == "stale_write"

    # The conflicting write must not have been applied.
    row = db_session.get(Capa, int(capa["id"]))
    db_session.refresh(row)
    assert row.d3_containment is None


def test_current_expected_updated_at_succeeds(client, seeded, auth_headers):
    headers = auth_headers("engineer")
    capa = _create_capa(client, headers).json()

    # Read the latest record to get the current token, then echo it back.
    current = client.get(f"/api/v1/capa/{capa['id']}", headers=headers).json()
    resp = client.patch(
        f"/api/v1/capa/{capa['id']}",
        json={
            "d1_team": "MRB team.",
            "expected_updated_at": current["updated_at"],
        },
        headers=headers,
    )
    assert resp.status_code == 200, resp.text
    assert resp.json()["d1_team"] == "MRB team."


def test_no_token_is_backward_compatible(client, seeded, auth_headers):
    headers = auth_headers("engineer")
    capa = _create_capa(client, headers).json()

    # No expected_updated_at -> behaves exactly as today (no 409), even after a
    # concurrent write moved updated_at forward.
    client.patch(
        f"/api/v1/capa/{capa['id']}",
        json={"d1_team": "Team A."},
        headers=headers,
    )
    resp = client.patch(
        f"/api/v1/capa/{capa['id']}",
        json={"d3_containment": "Containment applied."},
        headers=headers,
    )
    assert resp.status_code == 200, resp.text
    assert resp.json()["d3_containment"] == "Containment applied."


def test_expected_updated_at_is_never_persisted(client, seeded, auth_headers):
    """The token must not be written onto the ORM object."""
    headers = auth_headers("engineer")
    capa = _create_capa(client, headers).json()
    current = client.get(f"/api/v1/capa/{capa['id']}", headers=headers).json()

    resp = client.patch(
        f"/api/v1/capa/{capa['id']}",
        json={"title": "Renamed CAPA", "expected_updated_at": current["updated_at"]},
        headers=headers,
    )
    assert resp.status_code == 200, resp.text
    body = resp.json()
    assert body["title"] == "Renamed CAPA"
    # updated_at should reflect the fresh server write, not the echoed token.
    assert "expected_updated_at" not in body
