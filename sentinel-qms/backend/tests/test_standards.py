"""Standards coverage-matrix CRUD + coverage math."""

from __future__ import annotations


def test_create_standard_with_requirements_and_coverage(client, seeded, auth_headers):
    admin = auth_headers("admin")
    s = client.post(
        "/api/v1/standards",
        json={"code": "AS9110", "name": "AS9110 — MRO"},
        headers=admin,
    )
    assert s.status_code == 201, s.text
    sid = s.json()["id"]

    for clause, status_ in [("4", "covered"), ("8.4", "partial"), ("9.2", "gap")]:
        r = client.post(
            f"/api/v1/standards/{sid}/requirements",
            json={"clause": clause, "title": f"Clause {clause}", "coverage_status": status_},
            headers=admin,
        )
        assert r.status_code == 201, r.text

    detail = client.get(f"/api/v1/standards/{sid}", headers=admin).json()
    cov = detail["coverage"]
    assert cov["total"] == 3
    assert cov["covered"] == 1 and cov["partial"] == 1 and cov["gap"] == 1
    # (1 covered + 0.5 partial) / 3 applicable = 50%
    assert cov["coverage_pct"] == 50.0


def test_duplicate_code_conflicts(client, seeded, auth_headers):
    admin = auth_headers("admin")
    body = {"code": "DUPSTD", "name": "Dup"}
    assert client.post("/api/v1/standards", json=body, headers=admin).status_code == 201
    assert client.post("/api/v1/standards", json=body, headers=admin).status_code == 409


def test_update_requirement_changes_coverage(client, seeded, auth_headers):
    admin = auth_headers("admin")
    sid = client.post("/api/v1/standards", json={"code": "X1", "name": "X"}, headers=admin).json()[
        "id"
    ]
    rid = client.post(
        f"/api/v1/standards/{sid}/requirements",
        json={"clause": "1", "title": "c", "coverage_status": "gap"},
        headers=admin,
    ).json()["id"]
    client.patch(
        f"/api/v1/standards/requirements/{rid}",
        json={"coverage_status": "covered", "module_key": "audits"},
        headers=admin,
    )
    detail = client.get(f"/api/v1/standards/{sid}", headers=admin).json()
    assert detail["coverage"]["coverage_pct"] == 100.0
    assert detail["requirements"][0]["module_key"] == "audits"


def test_write_requires_admin(client, seeded, auth_headers):
    resp = client.post(
        "/api/v1/standards", json={"code": "NOPE", "name": "x"}, headers=auth_headers("engineer")
    )
    assert resp.status_code == 403
    # reads are open to any authenticated user
    assert client.get("/api/v1/standards", headers=auth_headers("engineer")).status_code == 200
