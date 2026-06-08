"""Internal audit program endpoints (annual schedule).

Reads require audit:read, writes audit:write.
"""

from __future__ import annotations

from fastapi import APIRouter, Depends, status
from sqlalchemy import select
from sqlalchemy.orm import Session, selectinload

from app.core.database import get_db
from app.core.exceptions import NotFoundError
from app.core.rbac import Permission, require_permission
from app.models.audit_program import AuditProgram, AuditProgramItem, ProgramItemStatus
from app.schemas.audit_program import (
    AuditProgramCreate,
    AuditProgramList,
    AuditProgramRead,
    AuditProgramUpdate,
    ProgramItemCreate,
    ProgramItemRead,
    ProgramItemUpdate,
    ProgramProgress,
)
from app.schemas.auth import CurrentUser

router = APIRouter(prefix="/audit-programs", tags=["audit-programs"])

_READ = require_permission(Permission.AUDIT_READ)
_WRITE = require_permission(Permission.AUDIT_WRITE)


def _progress(items: list[AuditProgramItem]) -> ProgramProgress:
    total = len(items)
    completed = sum(1 for i in items if i.status == ProgramItemStatus.COMPLETED)
    pct = round(completed / total * 100, 1) if total else 0.0
    return ProgramProgress(total=total, completed=completed, completed_pct=pct)


def _to_list(p: AuditProgram) -> dict:
    return {
        "id": p.id,
        "name": p.name,
        "year": p.year,
        "status": p.status,
        "progress": _progress(list(p.items)),
    }


def _to_read(p: AuditProgram) -> dict:
    return {**_to_list(p), "objectives": p.objectives, "items": list(p.items)}


def _get(db: Session, program_id: int) -> AuditProgram:
    p = db.get(AuditProgram, program_id)
    if p is None or p.is_deleted:
        raise NotFoundError(f"Audit program {program_id} not found.")
    return p


@router.get("", response_model=list[AuditProgramList])
def list_programs(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> list[dict]:
    rows = (
        db.execute(
            select(AuditProgram)
            .options(selectinload(AuditProgram.items))
            .where(AuditProgram.is_deleted.is_(False))
            .order_by(AuditProgram.year.desc(), AuditProgram.id.desc())
        )
        .scalars()
        .all()
    )
    return [_to_list(p) for p in rows]


@router.post("", response_model=AuditProgramRead, status_code=status.HTTP_201_CREATED)
def create_program(
    body: AuditProgramCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    p = AuditProgram(**body.model_dump(), created_by=actor.id, updated_by=actor.id)
    db.add(p)
    db.commit()
    db.refresh(p)
    return _to_read(p)


@router.get("/{program_id}", response_model=AuditProgramRead)
def get_program(
    program_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> dict:
    return _to_read(_get(db, program_id))


@router.patch("/{program_id}", response_model=AuditProgramRead)
def update_program(
    program_id: int,
    body: AuditProgramUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    p = _get(db, program_id)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(p, key, value)
    p.updated_by = actor.id
    db.commit()
    db.refresh(p)
    return _to_read(p)


@router.delete("/{program_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_program(
    program_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> None:
    _get(db, program_id).soft_delete(actor.id)
    db.commit()


@router.post(
    "/{program_id}/items", response_model=ProgramItemRead, status_code=status.HTTP_201_CREATED
)
def add_item(
    program_id: int,
    body: ProgramItemCreate,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_WRITE),
) -> AuditProgramItem:
    _get(db, program_id)
    item = AuditProgramItem(program_id=program_id, **body.model_dump())
    db.add(item)
    db.commit()
    db.refresh(item)
    return item


@router.patch("/items/{item_id}", response_model=ProgramItemRead)
def update_item(
    item_id: int,
    body: ProgramItemUpdate,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_WRITE),
) -> AuditProgramItem:
    item = db.get(AuditProgramItem, item_id)
    if item is None:
        raise NotFoundError(f"Program item {item_id} not found.")
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(item, key, value)
    db.commit()
    db.refresh(item)
    return item


@router.delete("/items/{item_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_item(
    item_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_WRITE),
) -> None:
    item = db.get(AuditProgramItem, item_id)
    if item is None:
        raise NotFoundError(f"Program item {item_id} not found.")
    db.delete(item)
    db.commit()
