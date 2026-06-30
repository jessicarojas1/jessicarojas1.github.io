"""SLA sweep escalates expired / soon-to-expire training (ISO 9001 7.2)."""

from __future__ import annotations

from datetime import date, timedelta

from app.models.training import Personnel, TrainingCourse, TrainingRecord, TrainingStatus
from app.models.user import Notification
from app.services.sla import run_sla_sweep


def _enable_sla(db, *, due_soon_days: int = 30):
    from app.models.settings import OrgSettings

    org = db.get(OrgSettings, 1) or OrgSettings(id=1)
    org.sla_enabled = True
    org.sla_capa_due_soon_days = due_soon_days
    db.add(org)
    db.flush()


def _record(db, *, code: str, expiry: date, status=TrainingStatus.COMPLETED) -> TrainingRecord:
    person = Personnel(employee_id=f"E-{code}", full_name=f"Person {code}")
    course = TrainingCourse(course_code=f"C-{code}", title=f"Course {code}")
    db.add_all([person, course])
    db.flush()
    rec = TrainingRecord(
        personnel_id=person.id, course_id=course.id, status=status, expiry_date=expiry
    )
    db.add(rec)
    db.flush()
    return rec


def test_expired_training_escalates_and_flips_status(db_session, seeded):
    _enable_sla(db_session)
    rec = _record(db_session, code="EXP", expiry=date.today() - timedelta(days=3))
    db_session.commit()

    summary = run_sla_sweep(db_session)
    assert summary["training_expired"] == 1
    db_session.refresh(rec)
    assert rec.status == TrainingStatus.EXPIRED
    notes = db_session.query(Notification).filter(Notification.entity_type == "training").all()
    assert any("expired" in (n.title or "").lower() for n in notes)

    # Idempotent: a second sweep does not re-escalate.
    assert run_sla_sweep(db_session)["training_expired"] == 0


def test_expiring_soon_training_escalates(db_session, seeded):
    _enable_sla(db_session, due_soon_days=30)
    _record(db_session, code="SOON", expiry=date.today() + timedelta(days=10))
    db_session.commit()
    summary = run_sla_sweep(db_session)
    assert summary["training_expiring_soon"] == 1


def test_valid_training_not_escalated(db_session, seeded):
    _enable_sla(db_session, due_soon_days=30)
    _record(db_session, code="OK", expiry=date.today() + timedelta(days=365))
    db_session.commit()
    summary = run_sla_sweep(db_session)
    assert summary["training_expired"] == 0
    assert summary["training_expiring_soon"] == 0
