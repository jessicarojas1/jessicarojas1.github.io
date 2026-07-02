"""CAPA effectiveness-verification due date round-trip tests."""

from __future__ import annotations


def _create_capa(client, headers, **extra):
    body = {
        "title": "Recurring bore oversize",
        "capa_type": "corrective",
        "d2_problem_description": "Bores oversize across lots.",
    }
    body.update(extra)
    return client.post("/api/v1/capa", json=body, headers=headers)


def test_effectiveness_due_defaults_to_none_when_omitted(client, seeded, auth_headers):
    eng = auth_headers("engineer")
    created = _create_capa(client, eng)
    assert created.status_code == 201, created.text
    assert created.json()["effectiveness_due_date"] is None


def test_effectiveness_due_created_and_round_trips(client, seeded, auth_headers):
    eng = auth_headers("engineer")
    created = _create_capa(client, eng, effectiveness_due_date="2026-09-01")
    assert created.status_code == 201, created.text

    capa_id = created.json()["id"]
    assert created.json()["effectiveness_due_date"] == "2026-09-01"

    fetched = client.get(f"/api/v1/capa/{capa_id}", headers=eng)
    assert fetched.status_code == 200
    assert fetched.json()["effectiveness_due_date"] == "2026-09-01"


def test_effectiveness_due_can_be_patched(client, seeded, auth_headers):
    eng = auth_headers("engineer")
    capa_id = _create_capa(client, eng).json()["id"]

    patched = client.patch(
        f"/api/v1/capa/{capa_id}",
        json={"effectiveness_due_date": "2026-10-15"},
        headers=eng,
    )
    assert patched.status_code == 200, patched.text
    assert patched.json()["effectiveness_due_date"] == "2026-10-15"

    fetched = client.get(f"/api/v1/capa/{capa_id}", headers=eng)
    assert fetched.json()["effectiveness_due_date"] == "2026-10-15"
