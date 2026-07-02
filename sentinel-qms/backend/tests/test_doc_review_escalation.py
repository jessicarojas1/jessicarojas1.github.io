"""SLA sweep escalates controlled documents past their periodic-review date."""

from __future__ import annotations

from datetime import date, timedelta

from app.models.document import Document, DocumentStatus, DocumentType
from app.models.user import Notification
from app.services.sla import run_sla_sweep


def _enable_sla(db):
    from app.models.settings import OrgSettings

    org = db.get(OrgSettings, 1) or OrgSettings(id=1)
    org.sla_enabled = True
    db.add(org)
    db.flush()


def _doc(db, *, number, review, status=DocumentStatus.APPROVED) -> Document:
    doc = Document(
        document_number=number,
        title="Calibration Procedure",
        doc_type=DocumentType.PROCEDURE,
        status=status,
        current_revision="A",
        next_review_date=review,
    )
    db.add(doc)
    db.flush()
    return doc


def test_overdue_review_escalates_once(db_session, seeded):
    _enable_sla(db_session)
    _doc(db_session, number="DOC-REV-1", review=date.today() - timedelta(days=10))
    db_session.commit()

    summary = run_sla_sweep(db_session)
    assert summary["document_review_overdue"] == 1
    notes = db_session.query(Notification).filter(Notification.entity_type == "document").all()
    assert any("review overdue" in (n.title or "").lower() for n in notes)

    # Idempotent — already claimed.
    assert run_sla_sweep(db_session)["document_review_overdue"] == 0


def test_future_review_not_escalated(db_session, seeded):
    _enable_sla(db_session)
    _doc(db_session, number="DOC-REV-2", review=date.today() + timedelta(days=90))
    db_session.commit()
    assert run_sla_sweep(db_session)["document_review_overdue"] == 0


def test_unapproved_doc_not_escalated(db_session, seeded):
    _enable_sla(db_session)
    _doc(
        db_session,
        number="DOC-REV-3",
        review=date.today() - timedelta(days=5),
        status=DocumentStatus.WORK_IN_PROGRESS,
    )
    db_session.commit()
    assert run_sla_sweep(db_session)["document_review_overdue"] == 0
