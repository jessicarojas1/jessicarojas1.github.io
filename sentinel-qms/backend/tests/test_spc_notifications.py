"""SPC control-chart violations fan out to notifications."""

from __future__ import annotations

from app.models.user import Notification
from app.services.spc import detect_violations, new_violations

# Tight in-control baseline so a single high reading clears the 3-sigma rule.
_BASE = [10.0, 9.9, 10.1, 10.0, 9.95, 10.05, 10.0, 9.98, 10.02, 9.97, 10.03, 10.0]


def _spc_notifs(db, user_id: int | None = None) -> list[Notification]:
    rows = [n for n in db.query(Notification).all() if n.category == "spc"]
    if user_id is not None:
        rows = [n for n in rows if n.user_id == user_id]
    return rows


def test_new_violations_diff_only_reports_fresh_points():
    before = detect_violations(_BASE)
    after = detect_violations([*_BASE, 13.0])
    fresh = new_violations(before, after)
    assert fresh, "the outlier should introduce a new violation"
    # Re-running the same 'after' against itself yields nothing new.
    assert new_violations(after, after) == []


def test_violation_notifies_kc_owner(client, seeded, auth_headers, db_session):
    h = auth_headers("manager")
    owner = seeded["users"]["engineer"]
    kid = client.post(
        "/api/v1/key-characteristics",
        json={
            "part_number": "PN-OWN",
            "characteristic": "Bore dia",
            "usl": 20,
            "lsl": 0,
            "owner_id": owner.id,
        },
        headers=h,
    ).json()["id"]

    for val in _BASE:
        client.post(
            f"/api/v1/key-characteristics/{kid}/measurements", json={"value": val}, headers=h
        )
    assert _spc_notifs(db_session, owner.id) == []

    # The outlier breaches the control limits -> owner is notified once.
    r = client.post(
        f"/api/v1/key-characteristics/{kid}/measurements", json={"value": 13.0}, headers=h
    )
    assert r.status_code == 201, r.text
    owned = _spc_notifs(db_session, owner.id)
    assert len(owned) == 1
    assert owned[0].entity_type == "key_characteristic"
    assert owned[0].entity_id == str(kid)


def test_violation_notifies_quality_team_when_unowned(client, seeded, auth_headers, db_session):
    h = auth_headers("manager")
    kid = client.post(
        "/api/v1/key-characteristics",
        json={"part_number": "PN-UNOWNED", "characteristic": "dia", "usl": 20, "lsl": 0},
        headers=h,
    ).json()["id"]
    for val in _BASE:
        client.post(
            f"/api/v1/key-characteristics/{kid}/measurements", json={"value": val}, headers=h
        )

    client.post(
        f"/api/v1/key-characteristics/{kid}/measurements", json={"value": 13.0}, headers=h
    )
    # Quality Manager + Quality Engineer both receive the unowned alert.
    recipients = {n.user_id for n in _spc_notifs(db_session)}
    assert seeded["users"]["manager"].id in recipients
    assert seeded["users"]["engineer"].id in recipients
