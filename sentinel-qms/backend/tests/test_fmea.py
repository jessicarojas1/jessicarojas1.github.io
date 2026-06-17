"""FMEA (PFMEA/DFMEA) module — RPN + action priority."""

from __future__ import annotations

from app.schemas.fmea import action_priority, rpn_of


def test_rpn_and_action_priority():
    assert rpn_of(8, 5, 4) == 160
    assert action_priority(8, 160) == "medium"
    assert action_priority(9, 9) == "high"  # severity >= 9 forces high
    assert action_priority(5, 250) == "high"  # rpn >= 200
    assert action_priority(2, 10) == "low"


def test_create_fmea_with_items(client, seeded, auth_headers):
    h = auth_headers("manager")
    created = client.post(
        "/api/v1/fmea",
        json={"title": "Bracket weld PFMEA", "fmea_type": "process", "part_number": "PN-77"},
        headers=h,
    )
    assert created.status_code == 201, created.text
    body = created.json()
    assert body["fmea_number"].startswith("FMEA-")
    fid = body["id"]

    item = client.post(
        f"/api/v1/fmea/{fid}/items",
        json={
            "function": "Weld bracket to frame",
            "failure_mode": "Insufficient weld penetration",
            "effect": "Joint fails under load",
            "severity": 9,
            "occurrence": 4,
            "detection": 5,
        },
        headers=h,
    )
    assert item.status_code == 201, item.text
    ib = item.json()
    assert ib["rpn"] == 9 * 4 * 5
    assert ib["action_priority"] == "high"  # severity 9

    detail = client.get(f"/api/v1/fmea/{fid}", headers=h).json()
    assert detail["item_count"] == 1
    assert detail["max_rpn"] == 180
    assert len(detail["items"]) == 1


def test_rating_bounds_enforced(client, seeded, auth_headers):
    h = auth_headers("manager")
    fid = client.post("/api/v1/fmea", json={"title": "x"}, headers=h).json()["id"]
    bad = client.post(
        f"/api/v1/fmea/{fid}/items",
        json={"function": "f", "failure_mode": "m", "severity": 11},
        headers=h,
    )
    assert bad.status_code == 422  # severity must be 1..10


def test_read_only_can_view_but_not_write(client, seeded, auth_headers):
    assert client.get("/api/v1/fmea", headers=auth_headers("readonly")).status_code == 200
    denied = client.post("/api/v1/fmea", json={"title": "x"}, headers=auth_headers("readonly"))
    assert denied.status_code == 403


def test_customer_role_denied(client, seeded, auth_headers):
    assert client.get("/api/v1/fmea", headers=auth_headers("customer")).status_code == 403
