"""APQP / PPAP (AS9145) endpoints.

A project walks the five APQP phases and owns the standard PPAP element
checklist (auto-seeded on creation). Reads require inspection:read, writes
inspection:write.
"""

from __future__ import annotations

from fastapi import APIRouter, Depends, Query, status
from sqlalchemy import select
from sqlalchemy.orm import Session, selectinload

from app.core.database import get_db
from app.core.exceptions import NotFoundError
from app.core.rbac import Permission, require_permission
from app.models.apqp import (
    PPAP_ELEMENTS,
    ApqpPhase,
    ApqpProject,
    ApqpStatus,
    PpapElement,
    PpapElementStatus,
)
from app.schemas.apqp import (
    ApqpCreate,
    ApqpList,
    ApqpRead,
    ApqpUpdate,
    PpapElementRead,
    PpapElementUpdate,
    PpapProgress,
)
from app.schemas.auth import CurrentUser
from app.services import numbering

router = APIRouter(prefix="/apqp", tags=["apqp"])

_READ = require_permission(Permission.INSPECTION_READ)
_WRITE = require_permission(Permission.INSPECTION_WRITE)


def _progress(elements: list[PpapElement]) -> PpapProgress:
    total = len(elements)
    applicable = sum(1 for e in elements if e.status != PpapElementStatus.NOT_APPLICABLE)
    approved = sum(1 for e in elements if e.status == PpapElementStatus.APPROVED)
    pct = round(approved / applicable * 100, 1) if applicable else 100.0
    return PpapProgress(total=total, approved=approved, applicable=applicable, approved_pct=pct)


def _to_list(p: ApqpProject) -> dict:
    return {
        "id": p.id,
        "project_number": p.project_number,
        "part_number": p.part_number,
        "part_name": p.part_name,
        "customer": p.customer,
        "supplier_id": p.supplier_id,
        "contract_id": p.contract_id,
        "current_phase": p.current_phase,
        "status": p.status,
        "submission_level": p.submission_level,
        "target_date": p.target_date,
        "ppap": _progress(list(p.elements)),
    }


def _to_read(p: ApqpProject) -> dict:
    return {**_to_list(p), "notes": p.notes, "elements": list(p.elements)}


def _get_project(db: Session, project_id: int) -> ApqpProject:
    p = db.get(ApqpProject, project_id)
    if p is None or p.is_deleted:
        raise NotFoundError(f"APQP project {project_id} not found.")
    return p


@router.get("", response_model=list[ApqpList])
def list_projects(
    db: Session = Depends(get_db),
    status_filter: ApqpStatus | None = Query(None, alias="status"),
    phase: ApqpPhase | None = Query(None),
    _: CurrentUser = Depends(_READ),
) -> list[dict]:
    stmt = (
        select(ApqpProject)
        .options(selectinload(ApqpProject.elements))
        .where(ApqpProject.is_deleted.is_(False))
    )
    if status_filter:
        stmt = stmt.where(ApqpProject.status == status_filter)
    if phase:
        stmt = stmt.where(ApqpProject.current_phase == phase)
    stmt = stmt.order_by(ApqpProject.id.desc())
    return [_to_list(p) for p in db.execute(stmt).scalars().all()]


@router.post("", response_model=ApqpRead, status_code=status.HTTP_201_CREATED)
def create_project(
    body: ApqpCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    project = ApqpProject(
        **body.model_dump(),
        project_number=numbering.next_number(db, ApqpProject, "project_number", "APQP"),
        current_phase=ApqpPhase.PLANNING,
        status=ApqpStatus.ACTIVE,
        created_by=actor.id,
        updated_by=actor.id,
    )
    # Auto-seed the standard PPAP submission package.
    project.elements = [
        PpapElement(element_key=key, name=name, created_by=actor.id, updated_by=actor.id)
        for key, name in PPAP_ELEMENTS
    ]
    db.add(project)
    db.commit()
    db.refresh(project)
    return _to_read(project)


@router.get("/{project_id}", response_model=ApqpRead)
def get_project(
    project_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> dict:
    return _to_read(_get_project(db, project_id))


@router.patch("/{project_id}", response_model=ApqpRead)
def update_project(
    project_id: int,
    body: ApqpUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    project = _get_project(db, project_id)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(project, key, value)
    project.updated_by = actor.id
    db.commit()
    db.refresh(project)
    return _to_read(project)


@router.delete("/{project_id}", response_model=ApqpRead)
def delete_project(
    project_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    project = _get_project(db, project_id)
    project.soft_delete(actor.id)
    db.commit()
    db.refresh(project)
    return _to_read(project)


@router.patch("/elements/{element_id}", response_model=PpapElementRead)
def update_element(
    element_id: int,
    body: PpapElementUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> PpapElement:
    elem = db.get(PpapElement, element_id)
    if elem is None:
        raise NotFoundError(f"PPAP element {element_id} not found.")
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(elem, key, value)
    elem.updated_by = actor.id
    db.commit()
    db.refresh(elem)
    return elem
