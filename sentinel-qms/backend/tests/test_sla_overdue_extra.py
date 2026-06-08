"""SLA sweep extensions: audit / calibration / concession overdue escalation."""

from __future__ import annotations

from datetime import date, timedelta

from app.models.audit_mgmt import Audit, AuditStatus
from app.models.calibration import Equipment, EquipmentStatus
from app.models.concession import Concession, ConcessionStatus
from app.models.user import Notification
from app.services.sla import run_sla_sweep


def _enable_sla(db):
    from app.models.settings import OrgSettings

    org = db.get(OrgSettings, 1) or OrgSettings(id=1)
    org.sla_enabled = True
    db.add(org)
    db.flush()


def test_audit_calibration_concession_overdue(client, seeded, db_session):
    db = db_session
    _enable_sla(db)
    past = date.today() - timedelta(days=5)

    db.add(
        Audit(
            audit_number="AUD-2026-9001",
            title="Overdue audit",
            audit_type="internal",
            status=AuditStatus.PLANNED,
            planned_date=past,
        )
    )
    db.add(
        Equipment(
            asset_tag="GAUGE-9", name="Caliper", status=EquipmentStatus.ACTIVE, next_due_date=past
        )
    )
    db.add(
        Concession(
            concession_number="DEV-2026-9001",
            concession_type="deviation",
            title="Expired dev",
            description="d",
            status=ConcessionStatus.APPROVED,
            expiry_date=past,
        )
    )
    db.flush()

    summary = run_sla_sweep(db)
    assert summary["audit_overdue"] >= 1
    assert summary["calibration_overdue"] >= 1
    assert summary["concession_expired"] >= 1

    # idempotent — a second sweep escalates nothing new
    again = run_sla_sweep(db)
    assert again["audit_overdue"] == 0
    assert again["calibration_overdue"] == 0
    assert again["concession_expired"] == 0

    cats = {n.entity_type for n in db.query(Notification).all()}
    assert {"audit", "equipment", "concession"} <= cats
