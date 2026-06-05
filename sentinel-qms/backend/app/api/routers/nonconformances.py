"""Nonconformance (NCR) endpoints: CRUD + status workflow + MRB disposition."""
from __future__ import annotations

from datetime import datetime, timezone

from fastapi import APIRouter, Depends, Query, Request, status
from sqlalchemy.orm import Session

from app.api.deps import (
    Pagination,
    SortParams,
    pagination_params,
    sort_params,
)
from app.core import audit
from app.core.database import get_db
from app.core.rbac import Permission, require_permission
from app.models.nonconformance import (
    NcSeverity,
    NcStatus,
    Nonconformance,
    NonconformanceDisposition,
)
from app.schemas.auth import CurrentUser
from app.schemas.common import Page
from app.schemas.nonconformance import (
    DispositionCreate,
    NcStatusChange,
    NonconformanceCreate,
    NonconformanceList,
    NonconformanceRead,
    NonconformanceUpdate,
)
from app.services import numbering
from app.services.crud import (
    apply_sort,
    base_select,
    get_or_404,
    page_meta,
    paginate,
    request_context,
)
from app.services.notifications import notify_user
from app.services.signatures import create_signature
from app.services.workflow import StateMachine

router = APIRouter(prefix="/nonconformances", tags=["nonconformances"])

ENTITY = "nonconformance"

NCR_FSM = StateMachine(
    {
        NcStatus.OPEN: {NcStatus.UNDER_REVIEW, NcStatus.VOID},
        NcStatus.UNDER_REVIEW: {NcStatus.DISPOSITIONED, NcStatus.OPEN, NcStatus.VOID},
        NcStatus.DISPOSITIONED: {NcStatus.CLOSED, NcStatus.UNDER_REVIEW},
        NcStatus.CLOSED: set(),
        NcStatus.VOID: set(),
    },
    name="NCR",
)


@router.get("", response_model=Page[NonconformanceList])
def list_ncrs(
    db: Session = Depends(get_db),
    pagination: Pagination = Depends(pagination_params),
    sort: SortParams = Depends(sort_params),
    status_filter: NcStatus | None = Query(None, alias="status"),
    severity: NcSeverity | None = Query(None),
    supplier_id: int | None = Query(None),
    search: str | None = Query(None, description="Match NCR number or title"),
    _: CurrentUser = Depends(require_permission(Permission.NCR_READ)),
) -> Page[NonconformanceList]:
    stmt = base_select(Nonconformance)
    if status_filter:
        stmt = stmt.where(Nonconformance.status == status_filter)
    if severity:
        stmt = stmt.where(Nonconformance.severity == severity)
    if supplier_id:
        stmt = stmt.where(Nonconformance.supplier_id == supplier_id)
    if search:
        like = f"%{search}%"
        stmt = stmt.where(
            Nonconformance.ncr_number.ilike(like) | Nonconformance.title.ilike(like)
        )
    stmt = apply_sort(stmt, Nonconformance, sort)
    items, total = paginate(db, stmt, Nonconformance, pagination)
    return Page[NonconformanceList](items=items, **page_meta(total, pagination))


@router.post("", response_model=NonconformanceRead, status_code=status.HTTP_201_CREATED)
def create_ncr(
    body: NonconformanceCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.NCR_WRITE)),
) -> Nonconformance:
    ncr = Nonconformance(
        **body.model_dump(),
        ncr_number=numbering.next_number(db, Nonconformance, "ncr_number", "NCR"),
        status=NcStatus.OPEN,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(ncr)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create",
        entity_type=ENTITY,
        entity_id=ncr.id,
        after=ncr,
        **request_context(request),
    )
    if ncr.assigned_to:
        notify_user(
            db,
            user_id=ncr.assigned_to,
            title=f"NCR {ncr.ncr_number} assigned to you",
            body=ncr.title,
            category="ncr",
            entity_type=ENTITY,
            entity_id=ncr.id,
        )
    db.commit()
    db.refresh(ncr)
    return ncr


@router.get("/{ncr_id}", response_model=NonconformanceRead)
def get_ncr(
    ncr_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.NCR_READ)),
) -> Nonconformance:
    return get_or_404(db, Nonconformance, ncr_id, name="NCR")


@router.patch("/{ncr_id}", response_model=NonconformanceRead)
def update_ncr(
    ncr_id: int,
    body: NonconformanceUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.NCR_WRITE)),
) -> Nonconformance:
    ncr = get_or_404(db, Nonconformance, ncr_id, name="NCR")
    before = audit.snapshot(ncr)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(ncr, key, value)
    ncr.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type=ENTITY,
        entity_id=ncr.id,
        before=before,
        after=ncr,
        **request_context(request),
    )
    db.commit()
    db.refresh(ncr)
    return ncr


@router.post("/{ncr_id}/status", response_model=NonconformanceRead)
def change_status(
    ncr_id: int,
    body: NcStatusChange,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.NCR_WRITE)),
) -> Nonconformance:
    ncr = get_or_404(db, Nonconformance, ncr_id, name="NCR")
    NCR_FSM.assert_transition(ncr.status, body.status)
    before = {"status": ncr.status.value}
    ncr.status = body.status
    if body.status == NcStatus.CLOSED:
        ncr.closed_at = datetime.now(timezone.utc)
    ncr.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="status_change",
        entity_type=ENTITY,
        entity_id=ncr.id,
        before=before,
        after={"status": ncr.status.value},
        **request_context(request),
    )
    db.commit()
    db.refresh(ncr)
    return ncr


@router.post(
    "/{ncr_id}/dispositions",
    response_model=NonconformanceRead,
    status_code=status.HTTP_201_CREATED,
)
def add_disposition(
    ncr_id: int,
    body: DispositionCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.NCR_DISPOSITION)),
) -> Nonconformance:
    """Record an MRB disposition with a captured 21 CFR Part 11 e-signature."""
    ncr = get_or_404(db, Nonconformance, ncr_id, name="NCR")
    if ncr.status in (NcStatus.CLOSED, NcStatus.VOID):
        from app.core.exceptions import WorkflowError

        raise WorkflowError("Cannot disposition a closed or void NCR.")

    sig = create_signature(
        db,
        actor=actor,
        entity_type=ENTITY,
        entity_id=ncr.id,
        payload=body.signature,
    )
    disp = NonconformanceDisposition(
        nonconformance_id=ncr.id,
        disposition_type=body.disposition_type,
        justification=body.justification,
        mrb_members=body.mrb_members,
        customer_approval_required=body.customer_approval_required,
        customer_approved=body.customer_approved,
        decided_by=actor.id,
        signature_id=sig.id,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(disp)
    if ncr.status in (NcStatus.OPEN, NcStatus.UNDER_REVIEW):
        ncr.status = NcStatus.DISPOSITIONED
    ncr.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="disposition",
        entity_type=ENTITY,
        entity_id=ncr.id,
        after={
            "disposition_type": disp.disposition_type.value,
            "signature_id": sig.id,
        },
        **request_context(request),
    )
    db.commit()
    db.refresh(ncr)
    return ncr


@router.delete("/{ncr_id}", response_model=NonconformanceRead)
def soft_delete_ncr(
    ncr_id: int,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.NCR_WRITE)),
) -> Nonconformance:
    """Soft-delete only — controlled records are never hard-deleted."""
    ncr = get_or_404(db, Nonconformance, ncr_id, name="NCR")
    ncr.soft_delete(actor.id)
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="soft_delete",
        entity_type=ENTITY,
        entity_id=ncr.id,
        **request_context(request),
    )
    db.commit()
    db.refresh(ncr)
    return ncr
