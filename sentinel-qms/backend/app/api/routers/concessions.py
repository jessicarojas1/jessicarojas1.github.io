"""Concession / Deviation / Waiver endpoints.

Reads require ncr:read, writes ncr:write (concessions are part of the
nonconformance / MRB disposition domain).
"""

from __future__ import annotations

from datetime import UTC, datetime

from fastapi import APIRouter, Depends, Query, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.exceptions import NotFoundError
from app.core.rbac import Permission, require_permission
from app.models.concession import Concession, ConcessionStatus, ConcessionType
from app.schemas.auth import CurrentUser
from app.schemas.concession import ConcessionCreate, ConcessionRead, ConcessionUpdate
from app.services import numbering

router = APIRouter(prefix="/concessions", tags=["concessions"])

_READ = require_permission(Permission.NCR_READ)
_WRITE = require_permission(Permission.NCR_WRITE)

_CLOSED = {ConcessionStatus.REJECTED, ConcessionStatus.EXPIRED, ConcessionStatus.CLOSED}


@router.get("", response_model=list[ConcessionRead])
def list_concessions(
    db: Session = Depends(get_db),
    status_filter: ConcessionStatus | None = Query(None, alias="status"),
    type_filter: ConcessionType | None = Query(None, alias="type"),
    _: CurrentUser = Depends(_READ),
) -> list[Concession]:
    stmt = select(Concession).where(Concession.is_deleted.is_(False))
    if status_filter:
        stmt = stmt.where(Concession.status == status_filter)
    if type_filter:
        stmt = stmt.where(Concession.concession_type == type_filter)
    stmt = stmt.order_by(Concession.id.desc())
    return list(db.execute(stmt).scalars().all())


@router.post("", response_model=ConcessionRead, status_code=status.HTTP_201_CREATED)
def create_concession(
    body: ConcessionCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> Concession:
    obj = Concession(
        **body.model_dump(),
        concession_number=numbering.next_number(db, Concession, "concession_number", "DEV"),
        status=ConcessionStatus.DRAFT,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(obj)
    db.commit()
    db.refresh(obj)
    return obj


def _get(db: Session, concession_id: int) -> Concession:
    obj = db.get(Concession, concession_id)
    if obj is None or obj.is_deleted:
        raise NotFoundError(f"Concession {concession_id} not found.")
    return obj


@router.get("/{concession_id}", response_model=ConcessionRead)
def get_concession(
    concession_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> Concession:
    return _get(db, concession_id)


@router.patch("/{concession_id}", response_model=ConcessionRead)
def update_concession(
    concession_id: int,
    body: ConcessionUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> Concession:
    obj = _get(db, concession_id)
    data = body.model_dump(exclude_unset=True)
    for key, value in data.items():
        setattr(obj, key, value)
    if data.get("status") in _CLOSED and obj.closed_at is None:
        obj.closed_at = datetime.now(UTC)
    obj.updated_by = actor.id
    db.commit()
    db.refresh(obj)
    return obj


@router.delete("/{concession_id}", response_model=ConcessionRead)
def delete_concession(
    concession_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> Concession:
    obj = _get(db, concession_id)
    obj.soft_delete(actor.id)
    db.commit()
    db.refresh(obj)
    return obj
