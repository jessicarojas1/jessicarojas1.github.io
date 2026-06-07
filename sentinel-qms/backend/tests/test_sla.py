"""SLA escalation sweep tests (service-level, no HTTP)."""
from __future__ import annotations

from datetime import date, timedelta

from app.models.capa import Capa, CapaAction, CapaActionStatus, CapaStatus
from app.models.nonconformance import NcSeverity, NcStatus, Nonconformance
from app.models.settings import OrgSettings
from app.models.user import Notification
from app.services import sla


def _settings(db, **overrides):
    org = OrgSettings(id=1, organization_name="Acme QMS")
    for k, v in overrides.items():
        setattr(org, k, v)
    db.add(org)
    db.commit()
    return org


def _capa(db, owner_id, **overrides):
    capa = Capa(
        capa_number=overrides.pop("capa_number", "CAPA-0001"),
        title="Bore oversize",
        d2_problem_description="Bores oversize across lots.",
        status=CapaStatus.OPEN,
        owner_id=owner_id,
    )
    for k, v in overrides.items():
        setattr(capa, k, v)
    db.add(capa)
    db.commit()
    return capa


def _ncr(db, **overrides):
    ncr = Nonconformance(
        ncr_number=overrides.pop("ncr_number", "NCR-0001"),
        title="Surface defect",
        description="Scratch found at receiving.",
        severity=overrides.pop("severity", NcSeverity.CRITICAL),
        status=overrides.pop("status", NcStatus.OPEN),
    )
    for k, v in overrides.items():
        setattr(ncr, k, v)
    db.add(ncr)
    db.commit()
    return ncr


def _notifs(db, category="sla"):
    return [n for n in db.query(Notification).all() if n.category == category]


def test_capa_overdue_escalates_owner_and_managers(db_session, seeded):
    _settings(db_session)
    owner = seeded["users"]["engineer"]
    _capa(db_session, owner.id, due_date=date.today() - timedelta(days=2))

    summary = sla.run_sla_sweep(db_session)
    assert summary["capa_overdue"] == 1

    notifs = _notifs(db_session)
    recipients = {n.user_id for n in notifs}
    # Owner + Quality Manager + Admin all notified.
    assert owner.id in recipients
    assert seeded["users"]["manager"].id in recipients
    assert seeded["users"]["admin"].id in recipients
    assert all(n.entity_type == "capa" for n in notifs)


def test_capa_sweep_is_idempotent(db_session, seeded):
    _settings(db_session)
    owner = seeded["users"]["engineer"]
    _capa(db_session, owner.id, due_date=date.today() - timedelta(days=2))

    first = sla.run_sla_sweep(db_session)
    count_after_first = len(_notifs(db_session))
    second = sla.run_sla_sweep(db_session)

    assert first["capa_overdue"] == 1
    assert second["capa_overdue"] == 0
    assert len(_notifs(db_session)) == count_after_first  # no duplicate notifications


def test_capa_due_soon_escalates(db_session, seeded):
    _settings(db_session, sla_capa_due_soon_days=7)
    owner = seeded["users"]["engineer"]
    _capa(db_session, owner.id, due_date=date.today() + timedelta(days=3))

    summary = sla.run_sla_sweep(db_session)
    assert summary["capa_due_soon"] == 1
    assert summary["capa_overdue"] == 0


def test_capa_action_overdue_escalates(db_session, seeded):
    _settings(db_session)
    owner = seeded["users"]["engineer"]
    capa = _capa(db_session, owner.id)  # no CAPA due_date
    action = CapaAction(
        capa_id=capa.id,
        description="Re-zero fixture",
        status=CapaActionStatus.IN_PROGRESS,
        owner_id=owner.id,
        due_date=date.today() - timedelta(days=1),
    )
    db_session.add(action)
    db_session.commit()

    summary = sla.run_sla_sweep(db_session)
    assert summary["capa_action_overdue"] == 1
    assert summary["capa_overdue"] == 0


def test_ncr_severity_windows(db_session, seeded):
    _settings(db_session, sla_ncr_critical_days=7, sla_ncr_minor_days=30)
    # Critical NCR aged 10 days -> breaches the 7-day window.
    _ncr(
        db_session,
        ncr_number="NCR-CRIT",
        severity=NcSeverity.CRITICAL,
        detected_at=date.today() - timedelta(days=10),
    )
    # Minor NCR aged 10 days -> within the 30-day window, no escalation.
    _ncr(
        db_session,
        ncr_number="NCR-MINOR",
        severity=NcSeverity.MINOR,
        detected_at=date.today() - timedelta(days=10),
    )

    summary = sla.run_sla_sweep(db_session)
    assert summary["ncr_overdue"] == 1
    notifs = _notifs(db_session)
    assert any("NCR-CRIT" in n.title for n in notifs)
    assert not any("NCR-MINOR" in n.title for n in notifs)


def test_sla_disabled_short_circuits(db_session, seeded):
    _settings(db_session, sla_enabled=False)
    owner = seeded["users"]["engineer"]
    _capa(db_session, owner.id, due_date=date.today() - timedelta(days=30))

    summary = sla.run_sla_sweep(db_session)
    assert summary["enabled"] is False
    assert summary["capa_overdue"] == 0
    assert _notifs(db_session) == []


def test_closed_capa_not_escalated(db_session, seeded):
    _settings(db_session)
    owner = seeded["users"]["engineer"]
    _capa(
        db_session,
        owner.id,
        status=CapaStatus.CLOSED,
        due_date=date.today() - timedelta(days=5),
    )

    summary = sla.run_sla_sweep(db_session)
    assert summary["capa_overdue"] == 0
