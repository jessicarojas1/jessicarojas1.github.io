"""Standards & framework coverage-mapping endpoints.

Reads are open to any authenticated user; writes require USER_MANAGE (admin),
consistent with other configuration surfaces.
"""

from __future__ import annotations

from fastapi import APIRouter, Depends, status
from sqlalchemy import select
from sqlalchemy.orm import Session, selectinload

from app.api.deps import get_current_user
from app.core.database import get_db
from app.core.exceptions import ConflictError, NotFoundError
from app.core.rbac import Permission, require_permission
from app.models.standard import CoverageStatus, Standard, StandardRequirement
from app.schemas.auth import CurrentUser
from app.schemas.standard import (
    CoverageSummary,
    RequirementCreate,
    RequirementRead,
    RequirementUpdate,
    StandardCreate,
    StandardList,
    StandardRead,
    StandardUpdate,
)

router = APIRouter(prefix="/standards", tags=["standards"])


def _summary(reqs: list[StandardRequirement]) -> CoverageSummary:
    total = len(reqs)
    covered = sum(1 for r in reqs if r.coverage_status == CoverageStatus.COVERED)
    partial = sum(1 for r in reqs if r.coverage_status == CoverageStatus.PARTIAL)
    gap = sum(1 for r in reqs if r.coverage_status == CoverageStatus.GAP)
    na = sum(1 for r in reqs if r.coverage_status == CoverageStatus.NOT_APPLICABLE)
    applicable = total - na
    # Partial credit at 50% so the bar reflects real audit readiness.
    pct = round((covered + 0.5 * partial) / applicable * 100, 1) if applicable else 100.0
    return CoverageSummary(
        total=total, covered=covered, partial=partial, gap=gap, not_applicable=na, coverage_pct=pct
    )


def _to_list(s: Standard) -> dict:
    return {
        "id": s.id,
        "code": s.code,
        "name": s.name,
        "description": s.description,
        "is_active": s.is_active,
        "coverage": _summary(list(s.requirements)),
    }


def _to_read(s: Standard) -> dict:
    return {**_to_list(s), "requirements": list(s.requirements)}


def _get_standard(db: Session, standard_id: int) -> Standard:
    s = db.get(Standard, standard_id)
    if s is None:
        raise NotFoundError(f"Standard {standard_id} not found.")
    return s


@router.get("", response_model=list[StandardList])
def list_standards(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(get_current_user),
) -> list[dict]:
    rows = (
        db.execute(
            select(Standard).options(selectinload(Standard.requirements)).order_by(Standard.code)
        )
        .scalars()
        .all()
    )
    return [_to_list(s) for s in rows]


@router.post("", response_model=StandardRead, status_code=status.HTTP_201_CREATED)
def create_standard(
    body: StandardCreate,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.USER_MANAGE)),
) -> dict:
    if db.execute(select(Standard).where(Standard.code == body.code)).scalar_one_or_none():
        raise ConflictError(f"A standard with code '{body.code}' already exists.")
    s = Standard(**body.model_dump())
    db.add(s)
    db.commit()
    db.refresh(s)
    return _to_read(s)


@router.get("/{standard_id}", response_model=StandardRead)
def get_standard(
    standard_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(get_current_user),
) -> dict:
    return _to_read(_get_standard(db, standard_id))


@router.patch("/{standard_id}", response_model=StandardRead)
def update_standard(
    standard_id: int,
    body: StandardUpdate,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.USER_MANAGE)),
) -> dict:
    s = _get_standard(db, standard_id)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(s, key, value)
    db.commit()
    db.refresh(s)
    return _to_read(s)


@router.delete("/{standard_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_standard(
    standard_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.USER_MANAGE)),
) -> None:
    db.delete(_get_standard(db, standard_id))
    db.commit()


@router.post(
    "/{standard_id}/requirements",
    response_model=RequirementRead,
    status_code=status.HTTP_201_CREATED,
)
def add_requirement(
    standard_id: int,
    body: RequirementCreate,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.USER_MANAGE)),
) -> StandardRequirement:
    _get_standard(db, standard_id)
    req = StandardRequirement(standard_id=standard_id, **body.model_dump())
    db.add(req)
    db.commit()
    db.refresh(req)
    return req


@router.patch("/requirements/{requirement_id}", response_model=RequirementRead)
def update_requirement(
    requirement_id: int,
    body: RequirementUpdate,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.USER_MANAGE)),
) -> StandardRequirement:
    req = db.get(StandardRequirement, requirement_id)
    if req is None:
        raise NotFoundError(f"Requirement {requirement_id} not found.")
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(req, key, value)
    db.commit()
    db.refresh(req)
    return req


@router.delete("/requirements/{requirement_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_requirement(
    requirement_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.USER_MANAGE)),
) -> None:
    req = db.get(StandardRequirement, requirement_id)
    if req is None:
        raise NotFoundError(f"Requirement {requirement_id} not found.")
    db.delete(req)
    db.commit()
