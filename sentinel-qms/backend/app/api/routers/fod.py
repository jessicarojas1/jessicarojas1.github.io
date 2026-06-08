"""FOD (Foreign Object Debris) program endpoints (AS9146).

A FOD-zone registry plus a FOD event log. Reads require inspection:read,
writes inspection:write; raising an NCR requires ncr:write.
"""

from __future__ import annotations

from datetime import UTC, date, datetime

from fastapi import APIRouter, Depends, Query, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.exceptions import ConflictError, NotFoundError
from app.core.rbac import Permission, require_permission
from app.models.fod import FodEvent, FodStatus, FodZone
from app.models.nonconformance import NcSeverity, NcStatus, Nonconformance
from app.schemas.auth import CurrentUser
from app.schemas.fod import (
    FodEventCreate,
    FodEventRead,
    FodEventUpdate,
    FodNcrLinkResult,
    FodZoneCreate,
    FodZoneRead,
    FodZoneUpdate,
)
from app.services import numbering

router = APIRouter(prefix="/fod", tags=["fod"])

_READ = require_permission(Permission.INSPECTION_READ)
_WRITE = require_permission(Permission.INSPECTION_WRITE)
_RAISE_NCR = require_permission(Permission.NCR_WRITE)


# ---- Zones ----
@router.get("/zones", response_model=list[FodZoneRead])
def list_zones(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> list[FodZone]:
    stmt = select(FodZone).where(FodZone.is_deleted.is_(False)).order_by(FodZone.code)
    return list(db.execute(stmt).scalars().all())


@router.post("/zones", response_model=FodZoneRead, status_code=status.HTTP_201_CREATED)
def create_zone(
    body: FodZoneCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> FodZone:
    if db.execute(select(FodZone).where(FodZone.code == body.code)).scalar_one_or_none():
        raise ConflictError(f"A FOD zone with code '{body.code}' already exists.")
    zone = FodZone(**body.model_dump(), created_by=actor.id, updated_by=actor.id)
    db.add(zone)
    db.commit()
    db.refresh(zone)
    return zone


def _get_zone(db: Session, zone_id: int) -> FodZone:
    zone = db.get(FodZone, zone_id)
    if zone is None or zone.is_deleted:
        raise NotFoundError(f"FOD zone {zone_id} not found.")
    return zone


@router.patch("/zones/{zone_id}", response_model=FodZoneRead)
def update_zone(
    zone_id: int,
    body: FodZoneUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> FodZone:
    zone = _get_zone(db, zone_id)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(zone, key, value)
    zone.updated_by = actor.id
    db.commit()
    db.refresh(zone)
    return zone


@router.delete("/zones/{zone_id}", response_model=FodZoneRead)
def delete_zone(
    zone_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> FodZone:
    zone = _get_zone(db, zone_id)
    zone.soft_delete(actor.id)
    db.commit()
    db.refresh(zone)
    return zone


# ---- Events ----
@router.get("/events", response_model=list[FodEventRead])
def list_events(
    db: Session = Depends(get_db),
    status_filter: FodStatus | None = Query(None, alias="status"),
    _: CurrentUser = Depends(_READ),
) -> list[FodEvent]:
    stmt = select(FodEvent).where(FodEvent.is_deleted.is_(False))
    if status_filter:
        stmt = stmt.where(FodEvent.status == status_filter)
    stmt = stmt.order_by(FodEvent.id.desc())
    return list(db.execute(stmt).scalars().all())


@router.post("/events", response_model=FodEventRead, status_code=status.HTTP_201_CREATED)
def create_event(
    body: FodEventCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> FodEvent:
    event = FodEvent(
        **body.model_dump(),
        event_number=numbering.next_number(db, FodEvent, "event_number", "FOD"),
        status=FodStatus.OPEN,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(event)
    db.commit()
    db.refresh(event)
    return event


def _get_event(db: Session, event_id: int) -> FodEvent:
    event = db.get(FodEvent, event_id)
    if event is None or event.is_deleted:
        raise NotFoundError(f"FOD event {event_id} not found.")
    return event


@router.get("/events/{event_id}", response_model=FodEventRead)
def get_event(
    event_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> FodEvent:
    return _get_event(db, event_id)


@router.patch("/events/{event_id}", response_model=FodEventRead)
def update_event(
    event_id: int,
    body: FodEventUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> FodEvent:
    event = _get_event(db, event_id)
    data = body.model_dump(exclude_unset=True)
    for key, value in data.items():
        setattr(event, key, value)
    if data.get("status") == FodStatus.CLOSED and event.closed_at is None:
        event.closed_at = datetime.now(UTC)
    event.updated_by = actor.id
    db.commit()
    db.refresh(event)
    return event


@router.delete("/events/{event_id}", response_model=FodEventRead)
def delete_event(
    event_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> FodEvent:
    event = _get_event(db, event_id)
    event.soft_delete(actor.id)
    db.commit()
    db.refresh(event)
    return event


@router.post("/events/{event_id}/raise-ncr", response_model=FodNcrLinkResult)
def raise_ncr_for_event(
    event_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_RAISE_NCR),
) -> FodNcrLinkResult:
    """Raise a nonconformance from a FOD event and link it back."""
    event = _get_event(db, event_id)
    if event.ncr_id is not None:
        raise ConflictError("An NCR has already been raised for this FOD event.")
    desc = (
        f"FOD event {event.event_number}.\n"
        f"Object: {event.object_type or '-'}\n"
        f"Location: {event.location or '-'}\n"
        f"{event.description or ''}"
    )
    ncr = Nonconformance(
        ncr_number=numbering.next_number(db, Nonconformance, "ncr_number", "NCR"),
        title=f"FOD: {event.title}"[:512],
        description=desc,
        severity=NcSeverity.MAJOR,
        status=NcStatus.OPEN,
        source="fod",
        detected_at=event.discovered_date or date.today(),
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(ncr)
    db.flush()
    event.ncr_id = ncr.id
    event.updated_by = actor.id
    db.commit()
    return FodNcrLinkResult(ncr_id=ncr.id, ncr_number=ncr.ncr_number)
