"""CSV export of the immutable audit trail (admin-only, itself audited)."""

from __future__ import annotations

from sqlalchemy import select

from app.models.user import AuditLog

EXPORT_URL = "/api/v1/audit-logs/export.csv"

EXPECTED_COLUMNS = [
    "id",
    "created_at",
    "actor_id",
    "actor_email",
    "action",
    "entity_type",
    "entity_id",
    "ip_address",
    "user_agent",
    "request_id",
]


def test_admin_can_export_audit_csv(client, seeded, auth_headers):
    # Logging in already wrote a "login" audit row, so the trail is non-empty.
    headers = auth_headers("admin")

    resp = client.get(EXPORT_URL, headers=headers)
    assert resp.status_code == 200, resp.text
    assert resp.headers["content-type"].startswith("text/csv")
    assert "attachment" in resp.headers["content-disposition"]
    assert 'filename="audit-log.csv"' in resp.headers["content-disposition"]

    lines = resp.text.splitlines()
    assert lines, "CSV should contain at least a header row"
    header = lines[0].split(",")
    assert header == EXPECTED_COLUMNS
    # At least one data row (the admin login audit entry).
    assert len(lines) >= 2


def test_non_admin_cannot_export_audit_csv(client, seeded, auth_headers):
    resp = client.get(EXPORT_URL, headers=auth_headers("engineer"))
    assert resp.status_code == 403, resp.text


def test_export_action_is_itself_audited(client, db_session, seeded, auth_headers):
    resp = client.get(EXPORT_URL, headers=auth_headers("admin"))
    assert resp.status_code == 200, resp.text

    row = (
        db_session.execute(
            select(AuditLog)
            .where(AuditLog.action == "export", AuditLog.entity_type == "audit_log")
            .order_by(AuditLog.id.desc())
        )
        .scalars()
        .first()
    )
    assert row is not None
    assert row.action == "export"
    assert row.entity_type == "audit_log"
    assert row.actor_email == "admin@test.local"
