"""SLA sweep escalates overdue supplier SCAR responses and lapsed supplier certs."""

from __future__ import annotations

from datetime import date, timedelta

from app.models.supplier import ScarStatus, Supplier, SupplierScar, SupplierStatus
from app.models.user import Notification
from app.services.sla import run_sla_sweep


def _enable_sla(db):
    from app.models.settings import OrgSettings

    org = db.get(OrgSettings, 1) or OrgSettings(id=1)
    org.sla_enabled = True
    db.add(org)
    db.flush()


def _supplier(db, *, code: str, cert_expiry: date | None = None) -> Supplier:
    supplier = Supplier(
        supplier_code=code,
        name=f"Supplier {code}",
        status=SupplierStatus.APPROVED,
        cert_expiry=cert_expiry,
    )
    db.add(supplier)
    db.flush()
    return supplier


def _scar(db, *, supplier_id: int, number: str, due: date | None, status=ScarStatus.ISSUED):
    scar = SupplierScar(
        scar_number=number,
        supplier_id=supplier_id,
        title=f"SCAR {number}",
        description="Defective parts received.",
        status=status,
        response_due_date=due,
    )
    db.add(scar)
    db.flush()
    return scar


def test_overdue_scar_escalates_once(db_session, seeded):
    _enable_sla(db_session)
    sup = _supplier(db_session, code="SUP-SCAR-1")
    _scar(
        db_session,
        supplier_id=sup.id,
        number="SCAR-1",
        due=date.today() - timedelta(days=5),
    )
    db_session.commit()

    summary = run_sla_sweep(db_session)
    assert summary["scar_response_overdue"] == 1
    notes = db_session.query(Notification).filter(Notification.entity_type == "supplier").all()
    assert any("response overdue" in (n.title or "").lower() for n in notes)

    # Idempotent — already claimed.
    assert run_sla_sweep(db_session)["scar_response_overdue"] == 0


def test_expired_supplier_cert_escalates(db_session, seeded):
    _enable_sla(db_session)
    _supplier(
        db_session,
        code="SUP-CERT-1",
        cert_expiry=date.today() - timedelta(days=2),
    )
    db_session.commit()

    summary = run_sla_sweep(db_session)
    assert summary["supplier_cert_expired"] == 1
    notes = db_session.query(Notification).filter(Notification.entity_type == "supplier").all()
    assert any("certification expired" in (n.title or "").lower() for n in notes)


def test_future_cert_and_closed_scar_not_escalated(db_session, seeded):
    _enable_sla(db_session)
    sup = _supplier(
        db_session,
        code="SUP-OK-1",
        cert_expiry=date.today() + timedelta(days=365),
    )
    # Closed SCAR whose response date is in the past must NOT escalate.
    _scar(
        db_session,
        supplier_id=sup.id,
        number="SCAR-CLOSED",
        due=date.today() - timedelta(days=10),
        status=ScarStatus.CLOSED,
    )
    db_session.commit()

    summary = run_sla_sweep(db_session)
    assert summary["scar_response_overdue"] == 0
    assert summary["supplier_cert_expired"] == 0
