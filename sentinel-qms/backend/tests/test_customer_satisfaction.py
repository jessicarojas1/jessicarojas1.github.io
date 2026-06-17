"""Customer Satisfaction surveys module (clause 9.1.2)."""

from __future__ import annotations

from app.schemas.customer_satisfaction import overall_of


def _make_customer(client, h) -> int:
    r = client.post(
        "/api/v1/customers",
        json={"code": "CUST-CSAT", "name": "Boeing Defense", "country": "USA"},
        headers=h,
    )
    assert r.status_code == 201, r.text
    return r.json()["id"]


def test_overall_of_helper():
    assert overall_of(90, 80, 100, None) == 90.0
    assert overall_of(None, None, None, None) is None
    assert overall_of(50, None, None, None) == 50.0
    assert overall_of(10, 20, 30, 99) == 99.0  # explicit wins


def test_create_survey_and_summary(client, seeded, auth_headers):
    h = auth_headers("manager")
    cid = _make_customer(client, h)
    created = client.post(
        "/api/v1/customer-satisfaction",
        json={
            "customer_id": cid,
            "period": "Q1 2026",
            "quality_score": 90,
            "delivery_score": 80,
            "communication_score": 100,
        },
        headers=h,
    )
    assert created.status_code == 201, created.text
    body = created.json()
    assert body["survey_number"].startswith("CSAT-")
    assert body["overall_score"] == 90.0
    assert body["customer_name"] == "Boeing Defense"

    summary = client.get("/api/v1/customer-satisfaction/summary", headers=h).json()
    assert summary["count"] == 1
    assert summary["average_overall"] == 90.0


def test_create_survey_unknown_customer_rejected(client, seeded, auth_headers):
    r = client.post(
        "/api/v1/customer-satisfaction",
        json={"customer_id": 999999, "quality_score": 50},
        headers=auth_headers("manager"),
    )
    assert r.status_code == 422, r.text


def test_read_only_can_view_but_not_write(client, seeded, auth_headers):
    assert (
        client.get("/api/v1/customer-satisfaction", headers=auth_headers("readonly")).status_code
        == 200
    )
    denied = client.post(
        "/api/v1/customer-satisfaction",
        json={"customer_id": 1, "quality_score": 1},
        headers=auth_headers("readonly"),
    )
    assert denied.status_code == 403


def test_customer_role_denied(client, seeded, auth_headers):
    assert (
        client.get("/api/v1/customer-satisfaction", headers=auth_headers("customer")).status_code
        == 403
    )
