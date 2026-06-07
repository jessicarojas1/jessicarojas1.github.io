"""Scheduled report digest tests (service-level, no HTTP / no SMTP)."""

from __future__ import annotations

from datetime import UTC, datetime, timedelta

from app.models.settings import OrgSettings
from app.services import report_digest


def _settings(db, **overrides):
    org = OrgSettings(id=1, organization_name="Acme QMS")
    for k, v in overrides.items():
        setattr(org, k, v)
    db.add(org)
    db.commit()
    return org


def test_parse_recipients_dedupes_and_validates():
    raw = "a@b.com, a@b.com\n bad-email ;c@d.com\n"
    assert report_digest.parse_recipients(raw) == ["a@b.com", "c@d.com"]
    assert report_digest.parse_recipients("") == []
    assert report_digest.parse_recipients(None) == []


def test_build_digest_includes_org_name(db_session, seeded):
    _settings(db_session)
    subject, body = report_digest.build_digest(db_session)
    assert "Acme QMS" in subject
    assert "Open NCRs" in body
    assert "Open CAPAs" in body


def test_digest_due_respects_enabled_and_cadence(db_session, seeded):
    now = datetime.now(UTC)
    org = _settings(
        db_session,
        report_schedule_enabled=True,
        report_schedule_frequency="weekly",
        report_schedule_recipients="ops@acme.com",
    )
    # Never sent -> due.
    assert report_digest.digest_due(org, now) is True
    # Sent just now -> not due.
    org.report_schedule_last_sent_at = now
    assert report_digest.digest_due(org, now) is False
    # Sent 8 days ago -> due again (weekly).
    org.report_schedule_last_sent_at = now - timedelta(days=8)
    assert report_digest.digest_due(org, now) is True
    # Disabled -> never due (even when otherwise overdue).
    org.report_schedule_enabled = False
    assert report_digest.digest_due(org, now) is False


def test_digest_not_due_without_recipients(db_session, seeded):
    now = datetime.now(UTC)
    org = _settings(
        db_session,
        report_schedule_enabled=True,
        report_schedule_recipients=None,
    )
    assert report_digest.digest_due(org, now) is False


def test_maybe_send_scheduled_claims_once(db_session, seeded):
    _settings(
        db_session,
        report_schedule_enabled=True,
        report_schedule_frequency="weekly",
        report_schedule_recipients="ops@acme.com",
    )
    # No SMTP configured in tests, so 0 emails actually go out, but the send is
    # still *claimed* (timestamp stamped) so it won't re-fire this period.
    first = report_digest.maybe_send_scheduled(db_session)
    assert first["claimed"] is True

    org = db_session.get(OrgSettings, 1)
    assert org.report_schedule_last_sent_at is not None

    second = report_digest.maybe_send_scheduled(db_session)
    assert second["claimed"] is False


def test_send_digest_now_reports_no_smtp(db_session, seeded):
    _settings(db_session, report_schedule_recipients="ops@acme.com")
    result = report_digest.send_digest_now(db_session)
    # No SMTP host configured under tests -> nothing sent, clear detail.
    assert result["ok"] is False
    assert result["sent"] == 0
    assert "SMTP" in result["detail"]


def test_send_digest_now_requires_recipients(db_session, seeded):
    _settings(db_session, report_schedule_recipients=None)
    result = report_digest.send_digest_now(db_session)
    assert result["ok"] is False
    assert "recipient" in result["detail"].lower()
