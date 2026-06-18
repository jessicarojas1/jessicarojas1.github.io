"""Per-user notification preferences (email/chat opt-out by category)."""

from __future__ import annotations

from app.models.user import Notification, User
from app.services import notifications


def test_preferences_endpoints_roundtrip(client, seeded, auth_headers):
    h = auth_headers("engineer")
    assert client.get("/api/v1/notifications/preferences", headers=h).json() == {
        "muted_categories": []
    }
    put = client.put(
        "/api/v1/notifications/preferences",
        json={"muted_categories": ["fmea", "fmea", " spc "]},
        headers=h,
    )
    assert put.status_code == 200, put.text
    assert put.json()["muted_categories"] == ["fmea", "spc"]  # deduped + trimmed + sorted
    assert client.get("/api/v1/notifications/preferences", headers=h).json()[
        "muted_categories"
    ] == [
        "fmea",
        "spc",
    ]


def test_muted_category_skips_dispatch_but_keeps_in_app(db_session, seeded, monkeypatch):
    user = seeded["users"]["engineer"]
    row = db_session.get(User, user.id)
    row.notification_prefs = {"muted_categories": ["fmea"]}
    db_session.commit()

    sent: list[str] = []
    monkeypatch.setattr(
        notifications.delivery,
        "dispatch_notification",
        lambda **kwargs: sent.append(kwargs.get("title", "")),
    )

    # Muted category: in-app row created, no outbound dispatch.
    notifications.notify_user(
        db_session, user_id=user.id, title="High RPN", category="fmea", entity_type="fmea"
    )
    # Non-muted category: dispatched.
    notifications.notify_user(
        db_session,
        user_id=user.id,
        title="SPC violation",
        category="spc",
        entity_type="key_characteristic",
    )
    db_session.commit()

    rows = [n for n in db_session.query(Notification).all() if n.user_id == user.id]
    assert {n.category for n in rows} == {"fmea", "spc"}  # both recorded in-app
    assert sent == ["SPC violation"]  # only the non-muted one was dispatched
