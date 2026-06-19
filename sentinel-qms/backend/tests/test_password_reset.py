"""Self-service password reset and authenticated change-password."""

from __future__ import annotations

from app.models.user import User
from app.services import password_reset


def _login(client, email, password):
    return client.post("/api/v1/auth/login", json={"username": email, "password": password})


def test_request_is_generic_and_no_enumeration(client, seeded):
    # Known and unknown emails return the same generic response + 200.
    known = client.post("/api/v1/auth/password-reset/request", json={"email": "qe@test.local"})
    unknown = client.post("/api/v1/auth/password-reset/request", json={"email": "nope@x.io"})
    assert known.status_code == 200
    assert unknown.status_code == 200
    assert known.json() == unknown.json()


def test_reset_flow_sets_new_password(client, seeded, db_session):
    user = db_session.execute(User.__table__.select().where(User.email == "qe@test.local")).first()
    # Mint a token directly (the plaintext is normally emailed).
    db_user = db_session.get(User, user.id)
    token = password_reset.create_reset(db_session, db_user)
    db_session.commit()

    resp = client.post(
        "/api/v1/auth/password-reset/confirm",
        json={"token": token, "new_password": "BrandNewPass123!"},
    )
    assert resp.status_code == 200, resp.text

    # Old password no longer works; new one does.
    assert _login(client, "qe@test.local", "EngPass123!").status_code == 401
    assert _login(client, "qe@test.local", "BrandNewPass123!").status_code == 200


def test_reset_token_is_single_use(client, seeded, db_session):
    db_user = db_session.get(User, db_session.query(User).filter_by(email="qe@test.local").one().id)
    token = password_reset.create_reset(db_session, db_user)
    db_session.commit()
    first = client.post(
        "/api/v1/auth/password-reset/confirm",
        json={"token": token, "new_password": "FirstChange123!"},
    )
    assert first.status_code == 200
    second = client.post(
        "/api/v1/auth/password-reset/confirm",
        json={"token": token, "new_password": "SecondChange123!"},
    )
    assert second.status_code == 422


def test_reset_rejects_short_password(client, seeded, db_session):
    db_user = db_session.query(User).filter_by(email="qe@test.local").one()
    token = password_reset.create_reset(db_session, db_user)
    db_session.commit()
    resp = client.post(
        "/api/v1/auth/password-reset/confirm",
        json={"token": token, "new_password": "short"},
    )
    assert resp.status_code == 422


def test_change_password_requires_current(client, seeded, auth_headers):
    h = auth_headers("engineer")
    wrong = client.post(
        "/api/v1/auth/change-password",
        json={"current_password": "WRONG", "new_password": "NewStrongPass123!"},
        headers=h,
    )
    assert wrong.status_code == 401


def test_change_password_succeeds(client, seeded, auth_headers):
    h = auth_headers("engineer")
    ok = client.post(
        "/api/v1/auth/change-password",
        json={"current_password": "EngPass123!", "new_password": "NewStrongPass123!"},
        headers=h,
    )
    assert ok.status_code == 200
    assert _login(client, "qe@test.local", "NewStrongPass123!").status_code == 200
    assert _login(client, "qe@test.local", "EngPass123!").status_code == 401
