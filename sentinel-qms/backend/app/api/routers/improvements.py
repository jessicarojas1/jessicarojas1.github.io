"""Continual Improvement / Kaizen endpoints (AS9100/ISO 9001 clause 10.3).

Reads require improvement:read, writes improvement:write.
"""

from __future__ import annotations

from datetime import UTC, datetime

from fastapi import APIRouter, Depends, Query, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.exceptions import NotFoundError
from app.core.rbac import Permission, require_permission
from app.models.improvement import (
    Improvement,
    ImprovementCategory,
    ImprovementStatus,
)
from app.models.user import User
from app.schemas.auth import CurrentUser
from app.schemas.improvement import ImprovementCreate, ImprovementRead, ImprovementUpdate
from app.services import numbering

router = APIRouter(prefix="/improvements", tags=["improvements"])

_READ = require_permission(Permission.IMPROVEMENT_READ)
_WRITE = require_permission(Permission.IMPROVEMENT_WRITE)


def _owner_name(db: Session, owner_id: int | None) -> str | None:
    if owner_id is None:
        return None
    owner = db.get(User, owner_id)
    return owner.full_name if owner is not None else None


def _to_read(obj: Improvement, db: Session) -> dict:
    return {
        "id": obj.id,
        "improvement_number": obj.improvement_number,
        "title": obj.title,
        "description": obj.description,
        "category": obj.category,
        "source": obj.source,
        "owner_id": obj.owner_id,
        "owner_name": _owner_name(db, obj.owner_id),
        "status": obj.status,
        "priority": obj.priority,
        "estimated_benefit": obj.estimated_benefit,
        "realized_benefit": obj.realized_benefit,
        "target_date": obj.target_date,
        "clause_ref": obj.clause_ref,
    }


def _get(db: Session, improvement_id: int) -> Improvement:
    obj = db.get(Improvement, improvement_id)
    if obj is None or obj.is_deleted:
        raise NotFoundError(f"Improvement {improvement_id} not found.")
    return obj


@router.get("", response_model=list[ImprovementRead])
def list_improvements(
    db: Session = Depends(get_db),
    status_filter: ImprovementStatus | None = Query(None, alias="status"),
    category_filter: ImprovementCategory | None = Query(None, alias="category"),
    _: CurrentUser = Depends(_READ),
) -> list[dict]:
    stmt = select(Improvement).where(Improvement.is_deleted.is_(False))
    if status_filter:
        stmt = stmt.where(Improvement.status == status_filter)
    if category_filter:
        stmt = stmt.where(Improvement.category == category_filter)
    stmt = stmt.order_by(Improvement.id.desc())
    return [_to_read(o, db) for o in db.execute(stmt).scalars().all()]


@router.post("", response_model=ImprovementRead, status_code=status.HTTP_201_CREATED)
def create_improvement(
    body: ImprovementCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    obj = Improvement(
        **body.model_dump(),
        improvement_number=numbering.next_number(db, Improvement, "improvement_number", "KAI"),
        status=ImprovementStatus.IDEA,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(obj)
    db.commit()
    db.refresh(obj)
    return _to_read(obj, db)


@router.get("/{improvement_id}", response_model=ImprovementRead)
def get_improvement(
    improvement_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> dict:
    return _to_read(_get(db, improvement_id), db)


@router.patch("/{improvement_id}", response_model=ImprovementRead)
def update_improvement(
    improvement_id: int,
    body: ImprovementUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    obj = _get(db, improvement_id)
    data = body.model_dump(exclude_unset=True)
    for key, value in data.items():
        setattr(obj, key, value)
    # Stamp completion when transitioning into a terminal "done" state.
    if data.get("status") == ImprovementStatus.DONE and obj.completed_at is None:
        obj.completed_at = datetime.now(UTC)
    obj.updated_by = actor.id
    db.commit()
    db.refresh(obj)
    return _to_read(obj, db)


@router.delete("/{improvement_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_improvement(
    improvement_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> None:
    _get(db, improvement_id).soft_delete(actor.id)
    db.commit()
