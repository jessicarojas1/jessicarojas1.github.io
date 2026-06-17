"""FMEA (PFMEA/DFMEA) endpoints — failure modes with RPN (AS9145 / AIAG-VDA).

Reads require fmea:read, writes fmea:write.
"""

from __future__ import annotations

from fastapi import APIRouter, Depends, Query, status
from sqlalchemy import select
from sqlalchemy.orm import Session, selectinload

from app.core.database import get_db
from app.core.exceptions import NotFoundError
from app.core.rbac import Permission, require_permission
from app.models.fmea import Fmea, FmeaItem, FmeaType
from app.models.user import User
from app.schemas.auth import CurrentUser
from app.schemas.fmea import (
    FmeaCreate,
    FmeaItemCreate,
    FmeaItemRead,
    FmeaItemUpdate,
    FmeaList,
    FmeaRead,
    FmeaUpdate,
    action_priority,
    rpn_of,
)
from app.services import numbering

router = APIRouter(prefix="/fmea", tags=["fmea"])

_READ = require_permission(Permission.FMEA_READ)
_WRITE = require_permission(Permission.FMEA_WRITE)


def _owner_name(db: Session, owner_id: int | None) -> str | None:
    if owner_id is None:
        return None
    owner = db.get(User, owner_id)
    return owner.full_name if owner is not None else None


def _item_dict(item: FmeaItem) -> dict:
    rpn = rpn_of(item.severity, item.occurrence, item.detection)
    return {
        "id": item.id,
        "fmea_id": item.fmea_id,
        "function": item.function,
        "failure_mode": item.failure_mode,
        "effect": item.effect,
        "cause": item.cause,
        "controls": item.controls,
        "severity": item.severity,
        "occurrence": item.occurrence,
        "detection": item.detection,
        "recommended_action": item.recommended_action,
        "action_owner_id": item.action_owner_id,
        "target_date": item.target_date,
        "status": item.status,
        "rpn": rpn,
        "action_priority": action_priority(item.severity, rpn),
    }


def _to_list(f: Fmea, db: Session) -> dict:
    rpns = [rpn_of(i.severity, i.occurrence, i.detection) for i in f.items]
    return {
        "id": f.id,
        "fmea_number": f.fmea_number,
        "title": f.title,
        "fmea_type": f.fmea_type,
        "part_number": f.part_number,
        "owner_id": f.owner_id,
        "owner_name": _owner_name(db, f.owner_id),
        "status": f.status,
        "item_count": len(f.items),
        "max_rpn": max(rpns, default=0),
    }


def _to_read(f: Fmea, db: Session) -> dict:
    return {
        **_to_list(f, db),
        "process_ref": f.process_ref,
        "scope": f.scope,
        "items": [_item_dict(i) for i in f.items],
    }


def _get(db: Session, fmea_id: int) -> Fmea:
    f = db.get(Fmea, fmea_id)
    if f is None or f.is_deleted:
        raise NotFoundError(f"FMEA {fmea_id} not found.")
    return f


@router.get("", response_model=list[FmeaList])
def list_fmeas(
    db: Session = Depends(get_db),
    type_filter: FmeaType | None = Query(None, alias="type"),
    _: CurrentUser = Depends(_READ),
) -> list[dict]:
    stmt = (
        select(Fmea)
        .options(selectinload(Fmea.items))
        .where(Fmea.is_deleted.is_(False))
    )
    if type_filter:
        stmt = stmt.where(Fmea.fmea_type == type_filter)
    stmt = stmt.order_by(Fmea.id.desc())
    return [_to_list(f, db) for f in db.execute(stmt).scalars().all()]


@router.post("", response_model=FmeaRead, status_code=status.HTTP_201_CREATED)
def create_fmea(
    body: FmeaCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    f = Fmea(
        **body.model_dump(),
        fmea_number=numbering.next_number(db, Fmea, "fmea_number", "FMEA"),
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(f)
    db.commit()
    db.refresh(f)
    return _to_read(f, db)


@router.get("/{fmea_id}", response_model=FmeaRead)
def get_fmea(
    fmea_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> dict:
    return _to_read(_get(db, fmea_id), db)


@router.patch("/{fmea_id}", response_model=FmeaRead)
def update_fmea(
    fmea_id: int,
    body: FmeaUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    f = _get(db, fmea_id)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(f, key, value)
    f.updated_by = actor.id
    db.commit()
    db.refresh(f)
    return _to_read(f, db)


@router.delete("/{fmea_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_fmea(
    fmea_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> None:
    _get(db, fmea_id).soft_delete(actor.id)
    db.commit()


@router.post("/{fmea_id}/items", response_model=FmeaItemRead, status_code=status.HTTP_201_CREATED)
def add_item(
    fmea_id: int,
    body: FmeaItemCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    _get(db, fmea_id)
    item = FmeaItem(fmea_id=fmea_id, **body.model_dump(), created_by=actor.id, updated_by=actor.id)
    db.add(item)
    db.commit()
    db.refresh(item)
    return _item_dict(item)


@router.patch("/items/{item_id}", response_model=FmeaItemRead)
def update_item(
    item_id: int,
    body: FmeaItemUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    item = db.get(FmeaItem, item_id)
    if item is None:
        raise NotFoundError(f"FMEA item {item_id} not found.")
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(item, key, value)
    item.updated_by = actor.id
    db.commit()
    db.refresh(item)
    return _item_dict(item)


@router.delete("/items/{item_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_item(
    item_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_WRITE),
) -> None:
    item = db.get(FmeaItem, item_id)
    if item is None:
        raise NotFoundError(f"FMEA item {item_id} not found.")
    db.delete(item)
    db.commit()
