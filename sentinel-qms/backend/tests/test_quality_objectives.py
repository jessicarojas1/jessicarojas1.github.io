"""Quality Objectives & KPIs module (clause 6.2)."""

from __future__ import annotations

from app.models.quality_objective import ObjectiveDirection
from app.schemas.quality_objective import attainment_pct


def test_attainment_pct_math():
    hb = ObjectiveDirection.HIGHER_BETTER
    lb = ObjectiveDirection.LOWER_BETTER
    assert attainment_pct(None, 100, hb) is None
    assert attainment_pct(90, 100, hb) == 90.0
    assert attainment_pct(120, 100, hb) == 120.0
    # capped at 200%
    assert attainment_pct(500, 100, hb) == 200.0
    # lower-is-better: target 5, actual 4 -> over-performing
    assert attainment_pct(4, 5, lb) == 125.0
    assert attainment_pct(0, 5, lb) == 200.0  # zero defects = best


def test_create_and_measure_objective(client, seeded, auth_headers):
    h = auth_headers("manager")
    created = client.post(
        "/api/v1/quality-objectives",
        json={
            "title": "On-time delivery >= 98%",
            "target_value": 98,
            "unit": "%",
            "direction": "higher_better",
            "cadence": "monthly",
            "clause_ref": "6.2",
        },
        headers=h,
    )
    assert created.status_code == 201, created.text
    body = created.json()
    assert body["objective_number"].startswith("QO-")
    assert body["attainment_pct"] is None  # not yet measured
    oid = body["id"]

    rec = client.post(
        f"/api/v1/quality-objectives/{oid}/measurements",
        json={"value": 96.5, "note": "May actuals"},
        headers=h,
    )
    assert rec.status_code == 201, rec.text

    detail = client.get(f"/api/v1/quality-objectives/{oid}", headers=h).json()
    assert detail["current_value"] == 96.5
    assert detail["attainment_pct"] == round(96.5 / 98 * 100, 1)
    assert len(detail["measurements"]) == 1


def test_read_only_can_view_but_not_write(client, seeded, auth_headers):
    assert (
        client.get("/api/v1/quality-objectives", headers=auth_headers("readonly")).status_code
        == 200
    )
    denied = client.post(
        "/api/v1/quality-objectives",
        json={"title": "x", "target_value": 1},
        headers=auth_headers("readonly"),
    )
    assert denied.status_code == 403


def test_customer_cannot_access_objectives(client, seeded, auth_headers):
    assert (
        client.get("/api/v1/quality-objectives", headers=auth_headers("customer")).status_code
        == 403
    )
