"""CAPA 8D workflow, effectiveness verification, and close-out tests."""
from __future__ import annotations


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


def _advance(client, headers, capa_id, status):
    return client.post(
        f"/api/v1/capa/{capa_id}/status", json={"status": status}, headers=headers
    )


def test_full_8d_workflow_to_close(client, seeded, auth_headers):
    eng = auth_headers("engineer")
    mgr = auth_headers("manager")

    capa_id = _create_capa(client, eng).json()["id"]

    # Cannot jump to action_plan without a root cause.
    assert _advance(client, eng, capa_id, "root_cause").status_code == 200
    assert _advance(client, eng, capa_id, "action_plan").status_code == 409

    # Document D4, then progress.
    client.patch(
        f"/api/v1/capa/{capa_id}",
        json={"d4_root_cause": "Tool offset drift.", "d5_corrective_action": "Add re-zero step."},
        headers=eng,
    )
    assert _advance(client, eng, capa_id, "action_plan").status_code == 200
    assert _advance(client, eng, capa_id, "implementation").status_code == 200
    assert _advance(client, eng, capa_id, "verification").status_code == 200

    # Verify effectiveness (requires capa:close — manager has it).
    ver = client.post(
        f"/api/v1/capa/{capa_id}/verify-effectiveness",
        json={"effective": True, "notes": "No recurrence in 3 lots."},
        headers=mgr,
    )
    assert ver.status_code == 200, ver.text
    assert ver.json()["effectiveness_verified"] is True

    # Close with e-signature.
    close = client.post(
        f"/api/v1/capa/{capa_id}/close",
        json={
            "d8_closure": "Closed; standard work updated.",
            "signature": {"meaning": "approved", "password": "MgrPass123!"},
        },
        headers=mgr,
    )
    assert close.status_code == 200, close.text
    assert close.json()["status"] == "closed"


def test_close_blocked_without_verification(client, seeded, auth_headers):
    eng = auth_headers("engineer")
    mgr = auth_headers("manager")
    capa_id = _create_capa(client, eng).json()["id"]
    client.patch(
        f"/api/v1/capa/{capa_id}",
        json={"d4_root_cause": "x", "d5_corrective_action": "y"},
        headers=eng,
    )
    for s in ("root_cause", "action_plan", "implementation", "verification"):
        _advance(client, eng, capa_id, s)
    resp = client.post(
        f"/api/v1/capa/{capa_id}/close",
        json={"d8_closure": "z", "signature": {"meaning": "approved", "password": "MgrPass123!"}},
        headers=mgr,
    )
    assert resp.status_code == 409


def test_actions_crud(client, seeded, auth_headers):
    eng = auth_headers("engineer")
    capa_id = _create_capa(client, eng).json()["id"]
    add = client.post(
        f"/api/v1/capa/{capa_id}/actions",
        json={"description": "Update setup sheet", "action_kind": "corrective"},
        headers=eng,
    )
    assert add.status_code == 201
    action_id = add.json()["id"]
    upd = client.patch(
        f"/api/v1/capa/{capa_id}/actions/{action_id}",
        json={"status": "completed"},
        headers=eng,
    )
    assert upd.status_code == 200
    assert upd.json()["status"] == "completed"
    assert upd.json()["completed_at"] is not None
