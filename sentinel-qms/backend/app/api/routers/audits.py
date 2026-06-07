"""Audit management endpoints: CRUD + findings + checklist + link finding->CAPA."""

from __future__ import annotations

from fastapi import APIRouter, Depends, Query, Request, status
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.api.deps import (
    Pagination,
    SortParams,
    pagination_params,
    require_page,
    require_perm,
    sort_params,
)
from app.core import audit as audit_log
from app.core.database import get_db
from app.core.exceptions import NotFoundError
from app.models.audit_mgmt import (
    Audit,
    AuditChecklistItem,
    AuditFinding,
    AuditStatus,
    AuditType,
    FindingStatus,
)
from app.models.capa import Capa
from app.schemas.audit_mgmt import (
    AuditCreate,
    AuditList,
    AuditRead,
    AuditUpdate,
    ChecklistItemCreate,
    ChecklistItemRead,
    FindingCreate,
    FindingLinkCapa,
    FindingRead,
    FindingUpdate,
)
from app.schemas.auth import CurrentUser
from app.schemas.common import Page
from app.services import numbering
from app.services.crud import (
    apply_sort,
    base_select,
    get_or_404,
    page_meta,
    paginate,
    request_context,
)

router = APIRouter(prefix="/audits", tags=["audits"])

ENTITY = "audit"


@router.get("", response_model=Page[AuditList])
def list_audits(
    db: Session = Depends(get_db),
    pagination: Pagination = Depends(pagination_params),
    sort: SortParams = Depends(sort_params),
    status_filter: AuditStatus | None = Query(None, alias="status"),
    audit_type: AuditType | None = Query(None),
    _: CurrentUser = Depends(require_page("audits", "view")),
) -> Page[AuditList]:
    stmt = base_select(Audit)
    if status_filter:
        stmt = stmt.where(Audit.status == status_filter)
    if audit_type:
        stmt = stmt.where(Audit.audit_type == audit_type)
    stmt = apply_sort(stmt, Audit, sort)
    items, total = paginate(db, stmt, Audit, pagination)
    return Page[AuditList](items=items, **page_meta(total, pagination))


@router.post("", response_model=AuditRead, status_code=status.HTTP_201_CREATED)
def create_audit(
    body: AuditCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("audits.create")),
) -> Audit:
    rec = Audit(
        **body.model_dump(),
        audit_number=numbering.next_number(db, Audit, "audit_number", "AUD"),
        status=AuditStatus.PLANNED,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(rec)
    db.flush()
    audit_log.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create",
        entity_type=ENTITY,
        entity_id=rec.id,
        after=rec,
        **request_context(request),
    )
    db.commit()
    db.refresh(rec)
    return rec


@router.get("/{audit_id}", response_model=AuditRead)
def get_audit(
    audit_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_page("audits", "view")),
) -> Audit:
    return get_or_404(db, Audit, audit_id, name="Audit")


@router.patch("/{audit_id}", response_model=AuditRead)
def update_audit(
    audit_id: int,
    body: AuditUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("audits.edit")),
) -> Audit:
    rec = get_or_404(db, Audit, audit_id, name="Audit")
    before = audit_log.snapshot(rec)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(rec, key, value)
    rec.updated_by = actor.id
    db.flush()
    audit_log.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type=ENTITY,
        entity_id=rec.id,
        before=before,
        after=rec,
        **request_context(request),
    )
    db.commit()
    db.refresh(rec)
    return rec


@router.post(
    "/{audit_id}/findings", response_model=FindingRead, status_code=status.HTTP_201_CREATED
)
def add_finding(
    audit_id: int,
    body: FindingCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("audits.conduct")),
) -> AuditFinding:
    rec = get_or_404(db, Audit, audit_id, name="Audit")
    seq = (
        db.execute(
            select(func.count()).select_from(AuditFinding).where(AuditFinding.audit_id == audit_id)
        ).scalar_one()
        + 1
    )
    finding = AuditFinding(
        audit_id=rec.id,
        finding_number=f"{rec.audit_number}-F{seq:02d}",
        **body.model_dump(),
        status=FindingStatus.OPEN,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(finding)
    db.flush()
    audit_log.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="add_finding",
        entity_type=ENTITY,
        entity_id=rec.id,
        after={"finding_number": finding.finding_number, "type": finding.finding_type.value},
        **request_context(request),
    )
    db.commit()
    db.refresh(finding)
    return finding


@router.patch("/findings/{finding_id}", response_model=FindingRead)
def update_finding(
    finding_id: int,
    body: FindingUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("audits.conduct")),
) -> AuditFinding:
    finding = db.get(AuditFinding, finding_id)
    if finding is None:
        raise NotFoundError(f"Finding {finding_id} not found.")
    before = audit_log.snapshot(finding)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(finding, key, value)
    finding.updated_by = actor.id
    db.flush()
    audit_log.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update_finding",
        entity_type=ENTITY,
        entity_id=finding.audit_id,
        before=before,
        after=finding,
        **request_context(request),
    )
    db.commit()
    db.refresh(finding)
    return finding


@router.post("/findings/{finding_id}/link-capa", response_model=FindingRead)
def link_finding_to_capa(
    finding_id: int,
    body: FindingLinkCapa,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("audits.edit")),
) -> AuditFinding:
    finding = db.get(AuditFinding, finding_id)
    if finding is None:
        raise NotFoundError(f"Finding {finding_id} not found.")
    capa = db.get(Capa, body.capa_id)
    if capa is None:
        raise NotFoundError(f"CAPA {body.capa_id} not found.")
    finding.capa_id = capa.id
    finding.status = FindingStatus.RESPONSE_SUBMITTED
    finding.updated_by = actor.id
    audit_log.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="link_capa",
        entity_type=ENTITY,
        entity_id=finding.audit_id,
        after={"finding_id": finding.id, "capa_id": capa.id},
        **request_context(request),
    )
    db.commit()
    db.refresh(finding)
    return finding


@router.post(
    "/{audit_id}/checklist", response_model=ChecklistItemRead, status_code=status.HTTP_201_CREATED
)
def add_checklist_item(
    audit_id: int,
    body: ChecklistItemCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("audits.conduct")),
) -> AuditChecklistItem:
    get_or_404(db, Audit, audit_id, name="Audit")
    item = AuditChecklistItem(audit_id=audit_id, **body.model_dump())
    db.add(item)
    db.commit()
    db.refresh(item)
    return item
