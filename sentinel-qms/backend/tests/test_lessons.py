"""Lessons Learned registry: CRUD, publish stamping, search, and RBAC."""

from __future__ import annotations


def _create(client, headers, **over):
    payload = {
        "title": "Bad weld root cause reused on new program",
        "category": "quality",
        "source": "ncr",
        "source_ref": "NCR-2026-0007",
        "what_happened": "Porosity escaped to the customer.",
        "root_cause": "Shielding gas flow not verified at setup.",
        "recommendation": "Add gas-flow check to the setup traveler.",
        **over,
    }
    return client.post("/api/v1/lessons-learned", json=payload, headers=headers)


def test_create_and_get(client, seeded, auth_headers):
    h = auth_headers("engineer")
    resp = _create(client, h)
    assert resp.status_code == 201, resp.text
    body = resp.json()
    assert body["lesson_number"].startswith("LL-")
    assert body["status"] == "draft"
    got = client.get(f"/api/v1/lessons-learned/{body['id']}", headers=h).json()
    assert got["source_ref"] == "NCR-2026-0007"


def test_publish_stamps_published_at(client, seeded, auth_headers):
    h = auth_headers("engineer")
    lid = _create(client, h).json()["id"]
    upd = client.patch(
        f"/api/v1/lessons-learned/{lid}", json={"status": "published"}, headers=h
    ).json()
    assert upd["status"] == "published"
    assert upd["published_at"] is not None


def test_filters_and_search(client, seeded, auth_headers):
    h = auth_headers("engineer")
    _create(client, h, title="Supplier packaging lesson", category="supplier", source="complaint")
    _create(client, h, title="Design margin lesson", category="design", source="project")
    by_cat = client.get("/api/v1/lessons-learned?category=supplier", headers=h).json()
    assert all(x["category"] == "supplier" for x in by_cat) and len(by_cat) >= 1
    found = client.get("/api/v1/lessons-learned?search=packaging", headers=h).json()
    assert any("packaging" in x["title"].lower() for x in found)


def test_soft_delete(client, seeded, auth_headers):
    h = auth_headers("engineer")
    lid = _create(client, h).json()["id"]
    assert client.delete(f"/api/v1/lessons-learned/{lid}", headers=h).status_code == 204
    assert client.get(f"/api/v1/lessons-learned/{lid}", headers=h).status_code == 404


def test_rbac_read_only_cannot_write(client, seeded, auth_headers):
    # Read-only may list, but not create.
    assert (
        client.get("/api/v1/lessons-learned", headers=auth_headers("readonly")).status_code == 200
    )
    assert _create(client, auth_headers("readonly")).status_code == 403


def test_rbac_customer_cannot_read(client, seeded, auth_headers):
    assert (
        client.get("/api/v1/lessons-learned", headers=auth_headers("customer")).status_code == 403
    )
