"""SLA sweep escalates risks overdue for periodic re-assessment."""

from __future__ import annotations

from datetime import date, timedelta

from app.models.risk import Risk, RiskStatus
from app.models.user import Notification
from app.services.sla import run_sla_sweep


def _enable_sla(db):
    from app.models.settings import OrgSettings

    org = db.get(OrgSettings, 1) or OrgSettings(id=1)
    org.sla_enabled = True
    db.add(org)
    db.flush()


def _risk(db, *, number, review, status=RiskStatus.MONITORING) -> Risk:
    risk = Risk(
        risk_number=number,
        title="Supplier capacity shortfall",
        description="Single-source supplier may not meet demand.",
        status=status,
        review_date=review,
    )
    db.add(risk)
    db.flush()
    return risk


def test_overdue_risk_review_escalates_once(db_session, seeded):
    _enable_sla(db_session)
    _risk(db_session, number="RISK-REV-1", review=date.today() - timedelta(days=10))
    db_session.commit()

    summary = run_sla_sweep(db_session)
    assert summary["risk_review_overdue"] == 1
    notes = db_session.query(Notification).filter(Notification.entity_type == "risk").all()
    assert any("review overdue" in (n.title or "").lower() for n in notes)

    # Idempotent — already claimed.
    assert run_sla_sweep(db_session)["risk_review_overdue"] == 0


def test_future_risk_review_not_escalated(db_session, seeded):
    _enable_sla(db_session)
    _risk(db_session, number="RISK-REV-2", review=date.today() + timedelta(days=90))
    db_session.commit()
    assert run_sla_sweep(db_session)["risk_review_overdue"] == 0


def test_closed_risk_not_escalated(db_session, seeded):
    _enable_sla(db_session)
    _risk(
        db_session,
        number="RISK-REV-3",
        review=date.today() - timedelta(days=5),
        status=RiskStatus.CLOSED,
    )
    db_session.commit()
    assert run_sla_sweep(db_session)["risk_review_overdue"] == 0
