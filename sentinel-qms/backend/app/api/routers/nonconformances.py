"""Nonconformance (NCR) endpoints: CRUD + status workflow + MRB disposition."""

from __future__ import annotations

import csv
import io
from datetime import UTC, datetime

from fastapi import APIRouter, Depends, Query, Request, Response, status
from sqlalchemy.orm import Session

from app.api.deps import (
    Pagination,
    SortParams,
    pagination_params,
    require_page,
    require_perm,
    sort_params,
)
from app.core import audit
from app.core.database import get_db
from app.core.exceptions import ConflictError
from app.models.nonconformance import (
    NcSeverity,
    NcStatus,
    Nonconformance,
    NonconformanceDisposition,
)
from app.schemas.auth import CurrentUser
from app.schemas.capa import CapaLinkResult
from app.schemas.common import Page
from app.schemas.nonconformance import (
    DispositionCreate,
    NcStatusChange,
    NonconformanceCreate,
    NonconformanceList,
    NonconformanceRead,
    NonconformanceUpdate,
)
from app.services import capa_factory, numbering
from app.services.crud import (
    apply_sort,
    base_select,
    get_or_404,
    page_meta,
    paginate,
    request_context,
)
from app.services.notifications import notify_assignment
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
    _: CurrentUser = Depends(require_page("nonconformances", "view")),
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
        stmt = stmt.where(Nonconformance.ncr_number.ilike(like) | Nonconformance.title.ilike(like))
    stmt = apply_sort(stmt, Nonconformance, sort)
    items, total = paginate(db, stmt, Nonconformance, pagination)
    return Page[NonconformanceList](items=items, **page_meta(total, pagination))


@router.get("/export.csv")
def export_ncrs_csv(
    request: Request,
    db: Session = Depends(get_db),
    status_filter: NcStatus | None = Query(None, alias="status"),
    severity: NcSeverity | None = Query(None),
    supplier_id: int | None = Query(None),
    search: str | None = Query(None, description="Match NCR number or title"),
    actor: CurrentUser = Depends(require_page("nonconformances", "view")),
) -> Response:
    """Stream the filtered NCR list as a CSV attachment (max 50,000 rows)."""
    stmt = base_select(Nonconformance)
    if status_filter:
        stmt = stmt.where(Nonconformance.status == status_filter)
    if severity:
        stmt = stmt.where(Nonconformance.severity == severity)
    if supplier_id:
        stmt = stmt.where(Nonconformance.supplier_id == supplier_id)
    if search:
        like = f"%{search}%"
        stmt = stmt.where(Nonconformance.ncr_number.ilike(like) | Nonconformance.title.ilike(like))
    stmt = stmt.order_by(Nonconformance.id.desc()).limit(50_000)
    rows = db.execute(stmt).scalars().all()

    columns = [
        "ncr_number",
        "title",
        "status",
        "severity",
        "source",
        "part_number",
        "detected_at",
        "created_at",
        "closed_at",
        "assigned_to",
    ]
    buf = io.StringIO()
    writer = csv.writer(buf)
    writer.writerow(columns)
    for ncr in rows:
        writer.writerow(
            [
                ncr.ncr_number,
                ncr.title,
                ncr.status.value if ncr.status else "",
                ncr.severity.value if ncr.severity else "",
                ncr.source or "",
                ncr.part_number or "",
                ncr.detected_at.isoformat() if ncr.detected_at else "",
                ncr.created_at.isoformat() if ncr.created_at else "",
                ncr.closed_at.isoformat() if ncr.closed_at else "",
                ncr.assigned_to if ncr.assigned_to is not None else "",
            ]
        )

    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="export",
        entity_type=ENTITY,
        after={"count": len(rows), "format": "csv"},
        **request_context(request),
    )
    db.commit()

    return Response(
        content=buf.getvalue(),
        media_type="text/csv",
        headers={"Content-Disposition": 'attachment; filename="nonconformances.csv"'},
    )


@router.post("", response_model=NonconformanceRead, status_code=status.HTTP_201_CREATED)
def create_ncr(
    body: NonconformanceCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("nonconformances.create")),
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
        notify_assignment(
            db,
            user_id=ncr.assigned_to,
            title=f"NCR {ncr.ncr_number} assigned to you",
            message=ncr.title,
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
    _: CurrentUser = Depends(require_page("nonconformances", "view")),
) -> Nonconformance:
    return get_or_404(db, Nonconformance, ncr_id, name="NCR")


@router.post("/{ncr_id}/create-capa", response_model=CapaLinkResult)
def create_capa_from_ncr(
    ncr_id: int,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("capa.create")),
) -> CapaLinkResult:
    """Open a corrective CAPA pre-filled from this NCR and link them. Conflicts
    if a CAPA is already linked, so no duplicate is created."""
    ncr = get_or_404(db, Nonconformance, ncr_id, name="NCR")
    if ncr.capa_id is not None:
        raise ConflictError("A CAPA is already linked to this NCR.")
    capa = capa_factory.create_linked_capa(
        db,
        actor.id,
        title=f"CAPA for {ncr.ncr_number}: {ncr.title}",
        problem=ncr.description,
        supplier_id=ncr.supplier_id,
    )
    ncr.capa_id = capa.id
    ncr.updated_by = actor.id
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create_capa",
        entity_type=ENTITY,
        entity_id=ncr.id,
        after={"capa_id": capa.id},
        **request_context(request),
    )
    db.commit()
    return CapaLinkResult(capa_id=capa.id, capa_number=capa.capa_number)


@router.patch("/{ncr_id}", response_model=NonconformanceRead)
def update_ncr(
    ncr_id: int,
    body: NonconformanceUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("nonconformances.edit")),
) -> Nonconformance:
    ncr = get_or_404(db, Nonconformance, ncr_id, name="NCR")
    before = audit.snapshot(ncr)
    prev_assignee = ncr.assigned_to
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(ncr, key, value)
    ncr.updated_by = actor.id
    db.flush()
    if ncr.assigned_to and ncr.assigned_to != prev_assignee:
        notify_assignment(
            db,
            user_id=ncr.assigned_to,
            title=f"NCR {ncr.ncr_number} assigned to you",
            message=ncr.title,
            entity_type=ENTITY,
            entity_id=ncr.id,
        )
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
    actor: CurrentUser = Depends(require_perm("nonconformances.edit")),
) -> Nonconformance:
    ncr = get_or_404(db, Nonconformance, ncr_id, name="NCR")
    NCR_FSM.assert_transition(ncr.status, body.status)
    before = {"status": ncr.status.value}
    ncr.status = body.status
    if body.status == NcStatus.CLOSED:
        ncr.closed_at = datetime.now(UTC)
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
    dependencies=[Depends(require_perm("nonconformances.disposition"))],
)
def add_disposition(
    ncr_id: int,
    body: DispositionCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_page("nonconformances", "edit")),
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
    actor: CurrentUser = Depends(require_perm("nonconformances.delete")),
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
