"""Nonconformance CRUD, numbering, and disposition tests."""
from __future__ import annotations


def _create_ncr(client, headers, **overrides):
    payload = {
        "title": "Surface finish defect",
        "description": "Ra exceeds 63 microinches on flange face.",
        "severity": "major",
        "part_number": "PN-9001",
        "quantity_affected": 5,
    }
    payload.update(overrides)
    return client.post("/api/v1/nonconformances", json=payload, headers=headers)


def test_create_ncr_assigns_number(client, seeded, auth_headers):
    headers = auth_headers("engineer")
    resp = _create_ncr(client, headers)
    assert resp.status_code == 201, resp.text
    body = resp.json()
    assert body["ncr_number"].startswith("NCR-")
    assert body["status"] == "open"


def test_ncr_numbering_is_sequential(client, seeded, auth_headers):
    headers = auth_headers("engineer")
    first = _create_ncr(client, headers).json()["ncr_number"]
    second = _create_ncr(client, headers).json()["ncr_number"]
    assert first != second
    assert int(first.rsplit("-", 1)[1]) + 1 == int(second.rsplit("-", 1)[1])


def test_list_and_filter(client, seeded, auth_headers):
    headers = auth_headers("engineer")
    _create_ncr(client, headers, severity="critical")
    resp = client.get("/api/v1/nonconformances?severity=critical", headers=headers)
    assert resp.status_code == 200
    data = resp.json()
    assert data["total"] >= 1
    assert all(item["severity"] == "critical" for item in data["items"])


def test_disposition_requires_reauth_signature(client, seeded, auth_headers):
    headers = auth_headers("engineer")
    ncr_id = _create_ncr(client, headers).json()["id"]
    # Move to under_review then disposition.
    client.post(
        f"/api/v1/nonconformances/{ncr_id}/status",
        json={"status": "under_review"},
        headers=headers,
    )
    resp = client.post(
        f"/api/v1/nonconformances/{ncr_id}/dispositions",
        json={
            "disposition_type": "use_as_is",
            "justification": "Within engineering deviation; fit/function unaffected.",
            "signature": {
                "meaning": "dispositioned",
                "reason": "MRB approved",
                "password": "EngPass123!",
            },
        },
        headers=headers,
    )
    assert resp.status_code == 201, resp.text
    assert resp.json()["status"] == "dispositioned"


def test_disposition_bad_password_rejected(client, seeded, auth_headers):
    headers = auth_headers("engineer")
    ncr_id = _create_ncr(client, headers).json()["id"]
    resp = client.post(
        f"/api/v1/nonconformances/{ncr_id}/dispositions",
        json={
            "disposition_type": "scrap",
            "justification": "Unusable.",
            "signature": {"meaning": "dispositioned", "password": "wrong"},
        },
        headers=headers,
    )
    assert resp.status_code == 401


def test_invalid_status_transition(client, seeded, auth_headers):
    headers = auth_headers("engineer")
    ncr_id = _create_ncr(client, headers).json()["id"]
    # open -> closed is not allowed directly.
    resp = client.post(
        f"/api/v1/nonconformances/{ncr_id}/status",
        json={"status": "closed"},
        headers=headers,
    )
    assert resp.status_code == 409


def test_soft_delete_hides_record(client, seeded, auth_headers):
    headers = auth_headers("engineer")
    ncr_id = _create_ncr(client, headers).json()["id"]
    assert client.delete(f"/api/v1/nonconformances/{ncr_id}", headers=headers).status_code == 200
    assert client.get(f"/api/v1/nonconformances/{ncr_id}", headers=headers).status_code == 404
