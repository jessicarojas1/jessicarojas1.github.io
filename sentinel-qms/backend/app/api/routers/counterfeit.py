"""Counterfeit-parts prevention endpoints (AS5553/AS6081).

Part-sourcing verification records and a GIDEP/ERAI-style alert log. Gated with
supplier-quality RBAC: reads require SUPPLIER_READ, writes SUPPLIER_WRITE.
"""

from __future__ import annotations

from datetime import date

from fastapi import APIRouter, Depends, Query, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.exceptions import ConflictError, NotFoundError
from app.core.rbac import Permission, require_permission
from app.models.counterfeit import (
    AlertStatus,
    CounterfeitAlert,
    PartSourcingRecord,
    VerificationStatus,
)
from app.models.nonconformance import NcSeverity, NcStatus, Nonconformance
from app.schemas.auth import CurrentUser
from app.schemas.counterfeit import (
    AlertCreate,
    AlertRead,
    AlertUpdate,
    NcrLinkResult,
    SourcingCreate,
    SourcingRead,
    SourcingUpdate,
)
from app.services import numbering

router = APIRouter(prefix="/counterfeit", tags=["counterfeit"])

_READ = require_permission(Permission.SUPPLIER_READ)
_WRITE = require_permission(Permission.SUPPLIER_WRITE)
# Raising an NCR creates a nonconformance, so require NCR write rights.
_RAISE_NCR = require_permission(Permission.NCR_WRITE)


def _create_ncr(
    db: Session,
    actor_id: int,
    *,
    title: str,
    description: str,
    part_number: str | None = None,
    supplier_id: int | None = None,
) -> Nonconformance:
    """Create an OPEN, CRITICAL counterfeit-sourced NCR and return it."""
    ncr = Nonconformance(
        ncr_number=numbering.next_number(db, Nonconformance, "ncr_number", "NCR"),
        title=title[:512],
        description=description,
        severity=NcSeverity.CRITICAL,
        status=NcStatus.OPEN,
        part_number=part_number,
        supplier_id=supplier_id,
        source="counterfeit",
        detected_at=date.today(),
        created_by=actor_id,
        updated_by=actor_id,
    )
    db.add(ncr)
    db.flush()
    return ncr


# ---- Part sourcing records ----
@router.get("/sourcing", response_model=list[SourcingRead])
def list_sourcing(
    db: Session = Depends(get_db),
    status_filter: VerificationStatus | None = Query(None, alias="status"),
    _: CurrentUser = Depends(_READ),
) -> list[PartSourcingRecord]:
    stmt = select(PartSourcingRecord).where(PartSourcingRecord.is_deleted.is_(False))
    if status_filter:
        stmt = stmt.where(PartSourcingRecord.status == status_filter)
    stmt = stmt.order_by(PartSourcingRecord.id.desc())
    return list(db.execute(stmt).scalars().all())


@router.post("/sourcing", response_model=SourcingRead, status_code=status.HTTP_201_CREATED)
def create_sourcing(
    body: SourcingCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> PartSourcingRecord:
    rec = PartSourcingRecord(
        **body.model_dump(),
        record_number=numbering.next_number(db, PartSourcingRecord, "record_number", "CFP"),
        status=VerificationStatus.PENDING,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(rec)
    db.commit()
    db.refresh(rec)
    return rec


def _get_sourcing(db: Session, record_id: int) -> PartSourcingRecord:
    rec = db.get(PartSourcingRecord, record_id)
    if rec is None or rec.is_deleted:
        raise NotFoundError(f"Sourcing record {record_id} not found.")
    return rec


@router.get("/sourcing/{record_id}", response_model=SourcingRead)
def get_sourcing(
    record_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> PartSourcingRecord:
    return _get_sourcing(db, record_id)


@router.patch("/sourcing/{record_id}", response_model=SourcingRead)
def update_sourcing(
    record_id: int,
    body: SourcingUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> PartSourcingRecord:
    rec = _get_sourcing(db, record_id)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(rec, key, value)
    rec.updated_by = actor.id
    db.commit()
    db.refresh(rec)
    return rec


@router.delete("/sourcing/{record_id}", response_model=SourcingRead)
def delete_sourcing(
    record_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> PartSourcingRecord:
    rec = _get_sourcing(db, record_id)
    rec.soft_delete(actor.id)
    db.commit()
    db.refresh(rec)
    return rec


@router.post("/sourcing/{record_id}/raise-ncr", response_model=NcrLinkResult)
def raise_ncr_for_sourcing(
    record_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_RAISE_NCR),
) -> NcrLinkResult:
    """Raise a nonconformance from a suspect sourcing record and link it back."""
    rec = _get_sourcing(db, record_id)
    if rec.ncr_id is not None:
        raise ConflictError("An NCR has already been raised for this sourcing record.")
    desc = (
        f"Suspect/counterfeit part raised from sourcing record {rec.record_number}.\n"
        f"Part: {rec.part_number}\n"
        f"Source type: {rec.source_type.value}\n"
        f"Lot/date code: {rec.lot_date_code or '-'}\n"
        f"Risk level: {rec.risk_level.value}\n"
        f"Certificate of conformance: {'yes' if rec.coc_received else 'no'}\n"
        f"OEM traceability: {'yes' if rec.traceability_to_oem else 'no'}"
    )
    ncr = _create_ncr(
        db,
        actor.id,
        title=f"Suspect counterfeit part: {rec.part_number}",
        description=desc,
        part_number=rec.part_number,
        supplier_id=rec.supplier_id,
    )
    rec.ncr_id = ncr.id
    rec.status = VerificationStatus.SUSPECT
    rec.updated_by = actor.id
    db.commit()
    return NcrLinkResult(ncr_id=ncr.id, ncr_number=ncr.ncr_number)


# ---- Counterfeit alerts (GIDEP / ERAI) ----
@router.get("/alerts", response_model=list[AlertRead])
def list_alerts(
    db: Session = Depends(get_db),
    status_filter: AlertStatus | None = Query(None, alias="status"),
    _: CurrentUser = Depends(_READ),
) -> list[CounterfeitAlert]:
    stmt = select(CounterfeitAlert).where(CounterfeitAlert.is_deleted.is_(False))
    if status_filter:
        stmt = stmt.where(CounterfeitAlert.status == status_filter)
    stmt = stmt.order_by(CounterfeitAlert.id.desc())
    return list(db.execute(stmt).scalars().all())


@router.post("/alerts", response_model=AlertRead, status_code=status.HTTP_201_CREATED)
def create_alert(
    body: AlertCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> CounterfeitAlert:
    alert = CounterfeitAlert(
        **body.model_dump(),
        alert_number=numbering.next_number(db, CounterfeitAlert, "alert_number", "CFA"),
        status=AlertStatus.OPEN,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(alert)
    db.commit()
    db.refresh(alert)
    return alert


def _get_alert(db: Session, alert_id: int) -> CounterfeitAlert:
    alert = db.get(CounterfeitAlert, alert_id)
    if alert is None or alert.is_deleted:
        raise NotFoundError(f"Counterfeit alert {alert_id} not found.")
    return alert


@router.get("/alerts/{alert_id}", response_model=AlertRead)
def get_alert(
    alert_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> CounterfeitAlert:
    return _get_alert(db, alert_id)


@router.patch("/alerts/{alert_id}", response_model=AlertRead)
def update_alert(
    alert_id: int,
    body: AlertUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> CounterfeitAlert:
    alert = _get_alert(db, alert_id)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(alert, key, value)
    alert.updated_by = actor.id
    db.commit()
    db.refresh(alert)
    return alert


@router.delete("/alerts/{alert_id}", response_model=AlertRead)
def delete_alert(
    alert_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> CounterfeitAlert:
    alert = _get_alert(db, alert_id)
    alert.soft_delete(actor.id)
    db.commit()
    db.refresh(alert)
    return alert


@router.post("/alerts/{alert_id}/raise-ncr", response_model=NcrLinkResult)
def raise_ncr_for_alert(
    alert_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_RAISE_NCR),
) -> NcrLinkResult:
    """Raise a nonconformance from a counterfeit alert and link it back."""
    alert = _get_alert(db, alert_id)
    if alert.ncr_id is not None:
        raise ConflictError("An NCR has already been raised for this alert.")
    desc = (
        f"Counterfeit alert {alert.alert_number} ({alert.source.value.upper()}).\n"
        f"Reference: {alert.external_ref or '-'}\n"
        f"Affected part numbers: {alert.part_numbers or '-'}\n"
        f"Impact assessment: {alert.impact_assessment or '-'}"
    )
    ncr = _create_ncr(
        db,
        actor.id,
        title=f"Counterfeit alert: {alert.title}",
        description=desc,
    )
    alert.ncr_id = ncr.id
    alert.updated_by = actor.id
    db.commit()
    return NcrLinkResult(ncr_id=ncr.id, ncr_number=ncr.ncr_number)
