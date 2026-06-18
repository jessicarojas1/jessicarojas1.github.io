"""Risk-signal notifications for the new modules (FMEA high-RPN, objective at-risk)."""

from __future__ import annotations

from app.models.user import Notification


def _notifs(db, category):
    return [n for n in db.query(Notification).all() if n.category == category]


def test_high_rpn_fmea_item_notifies_quality(client, seeded, auth_headers, db_session):
    h = auth_headers("manager")
    fid = client.post("/api/v1/fmea", json={"title": "Weld PFMEA"}, headers=h).json()["id"]

    # Low-priority item: no alert.
    client.post(
        f"/api/v1/fmea/{fid}/items",
        json={
            "function": "f",
            "failure_mode": "minor",
            "severity": 2,
            "occurrence": 2,
            "detection": 2,
        },
        headers=h,
    )
    assert _notifs(db_session, "fmea") == []

    # High severity (>=9) -> high priority -> quality team notified (unowned FMEA).
    r = client.post(
        f"/api/v1/fmea/{fid}/items",
        json={
            "function": "weld",
            "failure_mode": "no penetration",
            "severity": 9,
            "occurrence": 4,
            "detection": 5,
        },
        headers=h,
    )
    assert r.status_code == 201, r.text
    alerts = _notifs(db_session, "fmea")
    assert alerts, "expected an FMEA high-RPN notification"
    assert alerts[0].entity_type == "fmea"
    assert alerts[0].entity_id == str(fid)


def test_objective_below_target_flags_at_risk_and_notifies(
    client, seeded, auth_headers, db_session
):
    h = auth_headers("manager")
    oid = client.post(
        "/api/v1/quality-objectives",
        json={"title": "First-pass yield", "target_value": 100, "direction": "higher_better"},
        headers=h,
    ).json()["id"]

    rec = client.post(
        f"/api/v1/quality-objectives/{oid}/measurements",
        json={"value": 50},  # 50% of target -> at risk
        headers=h,
    )
    assert rec.status_code == 201, rec.text

    detail = client.get(f"/api/v1/quality-objectives/{oid}", headers=h).json()
    assert detail["status"] == "at_risk"
    alerts = _notifs(db_session, "quality_objective")
    assert alerts and alerts[0].entity_type == "quality_objective"


def test_objective_meeting_target_is_marked_met_without_alert(
    client, seeded, auth_headers, db_session
):
    h = auth_headers("manager")
    oid = client.post(
        "/api/v1/quality-objectives",
        json={"title": "On-time delivery", "target_value": 90, "direction": "higher_better"},
        headers=h,
    ).json()["id"]
    client.post(f"/api/v1/quality-objectives/{oid}/measurements", json={"value": 95}, headers=h)
    detail = client.get(f"/api/v1/quality-objectives/{oid}", headers=h).json()
    assert detail["status"] == "met"
    assert _notifs(db_session, "quality_objective") == []
