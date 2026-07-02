"""SLA sweep escalates CAPAs overdue for effectiveness verification."""

from __future__ import annotations

from datetime import date, timedelta

from app.models.capa import Capa, CapaStatus
from app.models.user import Notification
from app.services.sla import run_sla_sweep


def _enable_sla(db):
    from app.models.settings import OrgSettings

    org = db.get(OrgSettings, 1) or OrgSettings(id=1)
    org.sla_enabled = True
    db.add(org)
    db.flush()


def _capa(
    db,
    *,
    number,
    effectiveness_due_date,
    effectiveness_verified=False,
    status=CapaStatus.VERIFICATION,
) -> Capa:
    capa = Capa(
        capa_number=number,
        title="Recurring bore oversize",
        d2_problem_description="Bores oversize across lots.",
        status=status,
        effectiveness_verified=effectiveness_verified,
        effectiveness_due_date=effectiveness_due_date,
    )
    db.add(capa)
    db.flush()
    return capa


def test_overdue_effectiveness_escalates_once(db_session, seeded):
    _enable_sla(db_session)
    _capa(
        db_session,
        number="CAPA-EFF-1",
        effectiveness_due_date=date.today() - timedelta(days=10),
    )
    db_session.commit()

    summary = run_sla_sweep(db_session)
    assert summary["capa_effectiveness_overdue"] == 1
    notes = db_session.query(Notification).filter(Notification.entity_type == "capa").all()
    assert any("effectiveness verification overdue" in (n.title or "").lower() for n in notes)

    # Idempotent — already claimed.
    assert run_sla_sweep(db_session)["capa_effectiveness_overdue"] == 0


def test_future_effectiveness_due_not_escalated(db_session, seeded):
    _enable_sla(db_session)
    _capa(
        db_session,
        number="CAPA-EFF-2",
        effectiveness_due_date=date.today() + timedelta(days=30),
    )
    db_session.commit()
    assert run_sla_sweep(db_session)["capa_effectiveness_overdue"] == 0


def test_verified_effectiveness_not_escalated(db_session, seeded):
    _enable_sla(db_session)
    _capa(
        db_session,
        number="CAPA-EFF-3",
        effectiveness_due_date=date.today() - timedelta(days=10),
        effectiveness_verified=True,
    )
    db_session.commit()
    assert run_sla_sweep(db_session)["capa_effectiveness_overdue"] == 0
