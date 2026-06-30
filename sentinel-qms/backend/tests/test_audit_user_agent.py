"""Audit trail captures the client User-Agent alongside the IP."""

from __future__ import annotations

from sqlalchemy import select

from app.core import audit
from app.models.user import AuditLog


def test_login_records_user_agent(client, db_session, seeded):
    ua = "Mozilla/5.0 (Test) SentinelQMS-UA-Probe/1.0"
    resp = client.post(
        "/api/v1/auth/login",
        json={"username": "admin@test.local", "password": "AdminPass123!"},
        headers={"User-Agent": ua},
    )
    assert resp.status_code == 200, resp.text
    row = (
        db_session.execute(
            select(AuditLog).where(AuditLog.action == "login").order_by(AuditLog.id.desc())
        )
        .scalars()
        .first()
    )
    assert row is not None
    assert row.user_agent == ua


def test_record_truncates_long_user_agent(db_session):
    long_ua = "X" * 400
    entry = audit.record(
        db_session,
        actor_id=None,
        actor_email="a@b.c",
        action="probe",
        entity_type="auth",
        user_agent=long_ua,
    )
    assert entry.user_agent is not None
    assert len(entry.user_agent) == 256
