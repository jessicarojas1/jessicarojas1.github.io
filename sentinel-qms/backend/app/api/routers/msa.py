"""MSA / Gage R&R study endpoints.

The acceptability ``result`` is derived from %GR&R per the AIAG rule of thumb
(<10% acceptable, 10-30% marginal, >30% unacceptable). Reads require
calibration:read, writes calibration:write.
"""

from __future__ import annotations

from fastapi import APIRouter, Depends, Query, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.exceptions import NotFoundError
from app.core.rbac import Permission, require_permission
from app.models.msa import MsaResult, MsaStudy
from app.schemas.auth import CurrentUser
from app.schemas.msa import MsaCreate, MsaRead, MsaUpdate
from app.services import numbering

router = APIRouter(prefix="/msa-studies", tags=["msa"])

_READ = require_permission(Permission.CALIBRATION_READ)
_WRITE = require_permission(Permission.CALIBRATION_WRITE)


def _result_for(grr_percent: float | None) -> MsaResult:
    if grr_percent is None:
        return MsaResult.PENDING
    if grr_percent < 10:
        return MsaResult.ACCEPTABLE
    if grr_percent <= 30:
        return MsaResult.MARGINAL
    return MsaResult.UNACCEPTABLE


@router.get("", response_model=list[MsaRead])
def list_studies(
    db: Session = Depends(get_db),
    result_filter: MsaResult | None = Query(None, alias="result"),
    _: CurrentUser = Depends(_READ),
) -> list[MsaStudy]:
    stmt = select(MsaStudy).where(MsaStudy.is_deleted.is_(False))
    if result_filter:
        stmt = stmt.where(MsaStudy.result == result_filter)
    stmt = stmt.order_by(MsaStudy.id.desc())
    return list(db.execute(stmt).scalars().all())


@router.post("", response_model=MsaRead, status_code=status.HTTP_201_CREATED)
def create_study(
    body: MsaCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> MsaStudy:
    study = MsaStudy(
        **body.model_dump(),
        study_number=numbering.next_number(db, MsaStudy, "study_number", "MSA"),
        result=_result_for(body.grr_percent),
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(study)
    db.commit()
    db.refresh(study)
    return study


def _get(db: Session, study_id: int) -> MsaStudy:
    study = db.get(MsaStudy, study_id)
    if study is None or study.is_deleted:
        raise NotFoundError(f"MSA study {study_id} not found.")
    return study


@router.get("/{study_id}", response_model=MsaRead)
def get_study(
    study_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> MsaStudy:
    return _get(db, study_id)


@router.patch("/{study_id}", response_model=MsaRead)
def update_study(
    study_id: int,
    body: MsaUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> MsaStudy:
    study = _get(db, study_id)
    data = body.model_dump(exclude_unset=True)
    for key, value in data.items():
        setattr(study, key, value)
    if "grr_percent" in data:
        study.result = _result_for(study.grr_percent)
    study.updated_by = actor.id
    db.commit()
    db.refresh(study)
    return study


@router.delete("/{study_id}", response_model=MsaRead)
def delete_study(
    study_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> MsaStudy:
    study = _get(db, study_id)
    study.soft_delete(actor.id)
    db.commit()
    db.refresh(study)
    return study
