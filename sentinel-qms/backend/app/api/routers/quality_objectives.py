"""Quality Objectives & KPIs endpoints (AS9100/ISO 9001 clause 6.2).

Reads require quality_objective:read, writes quality_objective:write.
"""

from __future__ import annotations

from datetime import UTC, datetime

from fastapi import APIRouter, Depends, Query, status
from sqlalchemy import select
from sqlalchemy.orm import Session, selectinload

from app.core.database import get_db
from app.core.exceptions import NotFoundError
from app.core.rbac import Permission, Role, require_permission
from app.models.quality_objective import (
    ObjectiveStatus,
    QualityObjective,
    QualityObjectiveMeasurement,
)
from app.models.user import User
from app.schemas.auth import CurrentUser
from app.schemas.quality_objective import (
    MeasurementCreate,
    MeasurementRead,
    QualityObjectiveCreate,
    QualityObjectiveList,
    QualityObjectiveRead,
    QualityObjectiveUpdate,
    attainment_pct,
)
from app.services import notifications, numbering

router = APIRouter(prefix="/quality-objectives", tags=["quality-objectives"])

_READ = require_permission(Permission.QOBJECTIVE_READ)
_WRITE = require_permission(Permission.QOBJECTIVE_WRITE)

# Attainment below this percent (of target) flags an objective as at-risk.
_AT_RISK_THRESHOLD = 85.0


def _owner_name(db: Session, owner_id: int | None) -> str | None:
    if owner_id is None:
        return None
    owner = db.get(User, owner_id)
    return owner.full_name if owner is not None else None


def _to_list(obj: QualityObjective, db: Session) -> dict:
    return {
        "id": obj.id,
        "objective_number": obj.objective_number,
        "title": obj.title,
        "category": obj.category,
        "owner_id": obj.owner_id,
        "owner_name": _owner_name(db, obj.owner_id),
        "target_value": obj.target_value,
        "baseline_value": obj.baseline_value,
        "current_value": obj.current_value,
        "unit": obj.unit,
        "direction": obj.direction,
        "cadence": obj.cadence,
        "status": obj.status,
        "target_date": obj.target_date,
        "clause_ref": obj.clause_ref,
        "attainment_pct": attainment_pct(obj.current_value, obj.target_value, obj.direction),
    }


def _to_read(obj: QualityObjective, db: Session) -> dict:
    return {
        **_to_list(obj, db),
        "description": obj.description,
        "measurements": list(obj.measurements),
    }


def _get(db: Session, objective_id: int) -> QualityObjective:
    obj = db.get(QualityObjective, objective_id)
    if obj is None or obj.is_deleted:
        raise NotFoundError(f"Quality objective {objective_id} not found.")
    return obj


@router.get("", response_model=list[QualityObjectiveList])
def list_objectives(
    db: Session = Depends(get_db),
    status_filter: ObjectiveStatus | None = Query(None, alias="status"),
    _: CurrentUser = Depends(_READ),
) -> list[dict]:
    stmt = (
        select(QualityObjective)
        .options(selectinload(QualityObjective.measurements))
        .where(QualityObjective.is_deleted.is_(False))
    )
    if status_filter:
        stmt = stmt.where(QualityObjective.status == status_filter)
    stmt = stmt.order_by(QualityObjective.id.desc())
    return [_to_list(o, db) for o in db.execute(stmt).scalars().all()]


@router.post("", response_model=QualityObjectiveRead, status_code=status.HTTP_201_CREATED)
def create_objective(
    body: QualityObjectiveCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    obj = QualityObjective(
        **body.model_dump(),
        objective_number=numbering.next_number(db, QualityObjective, "objective_number", "QO"),
        status=ObjectiveStatus.ACTIVE,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(obj)
    db.commit()
    db.refresh(obj)
    return _to_read(obj, db)


@router.get("/{objective_id}", response_model=QualityObjectiveRead)
def get_objective(
    objective_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> dict:
    return _to_read(_get(db, objective_id), db)


@router.patch("/{objective_id}", response_model=QualityObjectiveRead)
def update_objective(
    objective_id: int,
    body: QualityObjectiveUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    obj = _get(db, objective_id)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(obj, key, value)
    obj.updated_by = actor.id
    db.commit()
    db.refresh(obj)
    return _to_read(obj, db)


@router.delete("/{objective_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_objective(
    objective_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> None:
    _get(db, objective_id).soft_delete(actor.id)
    db.commit()


@router.post(
    "/{objective_id}/measurements",
    response_model=MeasurementRead,
    status_code=status.HTTP_201_CREATED,
)
def add_measurement(
    objective_id: int,
    body: MeasurementCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> QualityObjectiveMeasurement:
    obj = _get(db, objective_id)
    m = QualityObjectiveMeasurement(
        objective_id=obj.id,
        **body.model_dump(),
        recorded_at=datetime.now(UTC),
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(m)
    # Recording an actual updates the objective's current value (latest reading).
    obj.current_value = body.value
    obj.updated_by = actor.id

    # Re-derive RAG status from attainment; alert the owner/quality on a newly
    # at-risk objective so it gets attention before the target date.
    was_at_risk = obj.status == ObjectiveStatus.AT_RISK
    pct = attainment_pct(obj.current_value, obj.target_value, obj.direction)
    if pct is not None:
        if pct >= 100:
            obj.status = ObjectiveStatus.MET
        elif pct < _AT_RISK_THRESHOLD:
            obj.status = ObjectiveStatus.AT_RISK
        elif obj.status in (ObjectiveStatus.AT_RISK, ObjectiveStatus.MET):
            obj.status = ObjectiveStatus.ACTIVE
    db.commit()
    db.refresh(m)

    if pct is not None and pct < _AT_RISK_THRESHOLD and not was_at_risk:
        _notify_at_risk(db, obj, pct)
        db.commit()
    return m


def _notify_at_risk(db: Session, obj: QualityObjective, pct: float) -> None:
    """Notify the objective owner (or quality team) that it has fallen at-risk."""
    title = f"Quality objective at risk: {obj.objective_number}"
    body = (
        f"{obj.title} — {pct}% of target ({obj.current_value}/{obj.target_value}{obj.unit or ''})."
    )
    if obj.owner_id is not None:
        notifications.notify_user(
            db,
            user_id=obj.owner_id,
            title=title,
            body=body,
            category="quality_objective",
            entity_type="quality_objective",
            entity_id=obj.id,
        )
    else:
        notifications.notify_roles(
            db,
            roles=[Role.QUALITY_MANAGER, Role.QUALITY_ENGINEER],
            title=title,
            body=body,
            category="quality_objective",
            entity_type="quality_objective",
            entity_id=obj.id,
        )
