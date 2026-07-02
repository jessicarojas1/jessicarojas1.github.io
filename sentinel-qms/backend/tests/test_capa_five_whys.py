"""Structured 5-Why root-cause worksheet on CAPA round-trip tests."""

from __future__ import annotations


def _create_capa(client, headers, **extra):
    body = {
        "title": "Recurring bore oversize",
        "capa_type": "corrective",
        "d2_problem_description": "Bores oversize across lots.",
    }
    body.update(extra)
    return client.post("/api/v1/capa", json=body, headers=headers)


def test_five_whys_defaults_to_none_when_omitted(client, seeded, auth_headers):
    eng = auth_headers("engineer")
    created = _create_capa(client, eng)
    assert created.status_code == 201, created.text
    assert created.json()["five_whys"] is None

    capa_id = created.json()["id"]
    fetched = client.get(f"/api/v1/capa/{capa_id}", headers=eng)
    assert fetched.status_code == 200
    assert fetched.json()["five_whys"] is None


def test_five_whys_created_and_round_trips(client, seeded, auth_headers):
    eng = auth_headers("engineer")
    chain = [
        {"why": "Bores are oversize", "because": "Boring tool cuts too deep"},
        {"why": "Tool cuts too deep", "because": "Tool offset drifted"},
        {"why": "Offset drifted", "because": "No re-zero step in the setup"},
    ]
    created = _create_capa(client, eng, five_whys=chain)
    assert created.status_code == 201, created.text

    capa_id = created.json()["id"]
    assert created.json()["five_whys"] == chain

    # GET returns the same structure.
    fetched = client.get(f"/api/v1/capa/{capa_id}", headers=eng)
    assert fetched.status_code == 200
    assert fetched.json()["five_whys"] == chain


def test_five_whys_can_be_patched(client, seeded, auth_headers):
    eng = auth_headers("engineer")
    capa_id = _create_capa(client, eng).json()["id"]

    new_chain = [
        {"why": "Machine tripped", "because": "Coolant flow too low"},
        {"why": "Coolant flow low", "because": "Filter clogged"},
    ]
    patched = client.patch(
        f"/api/v1/capa/{capa_id}",
        json={"five_whys": new_chain},
        headers=eng,
    )
    assert patched.status_code == 200, patched.text
    assert patched.json()["five_whys"] == new_chain

    fetched = client.get(f"/api/v1/capa/{capa_id}", headers=eng)
    assert fetched.json()["five_whys"] == new_chain
