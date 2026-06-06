"""Customer complaint / RMA endpoints: CRUD with NCR/CAPA linkage."""
from __future__ import annotations

from datetime import datetime, timezone

from fastapi import APIRouter, Depends, Query, Request, status
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
from app.models.complaint import Complaint, ComplaintSeverity, ComplaintStatus
from app.schemas.auth import CurrentUser
from app.schemas.common import Page
from app.schemas.complaint import (
    ComplaintCreate,
    ComplaintList,
    ComplaintRead,
    ComplaintUpdate,
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

router = APIRouter(prefix="/complaints", tags=["complaints"])

ENTITY = "complaint"

_CLOSED = {ComplaintStatus.RESOLVED, ComplaintStatus.CLOSED}


@router.get("", response_model=Page[ComplaintList])
def list_complaints(
    db: Session = Depends(get_db),
    pagination: Pagination = Depends(pagination_params),
    sort: SortParams = Depends(sort_params),
    status_filter: ComplaintStatus | None = Query(None, alias="status"),
    severity: ComplaintSeverity | None = Query(None),
    is_rma: bool | None = Query(None),
    _: CurrentUser = Depends(require_page("complaints", "view")),
) -> Page[ComplaintList]:
    stmt = base_select(Complaint)
    if status_filter:
        stmt = stmt.where(Complaint.status == status_filter)
    if severity:
        stmt = stmt.where(Complaint.severity == severity)
    if is_rma is not None:
        stmt = stmt.where(Complaint.is_rma.is_(is_rma))
    stmt = apply_sort(stmt, Complaint, sort)
    items, total = paginate(db, stmt, Complaint, pagination)
    return Page[ComplaintList](items=items, **page_meta(total, pagination))


@router.post("", response_model=ComplaintRead, status_code=status.HTTP_201_CREATED)
def create_complaint(
    body: ComplaintCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("complaints.create")),
) -> Complaint:
    complaint = Complaint(
        **body.model_dump(),
        complaint_number=numbering.next_number(db, Complaint, "complaint_number", "CMP"),
        status=ComplaintStatus.RECEIVED,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(complaint)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create",
        entity_type=ENTITY,
        entity_id=complaint.id,
        after=complaint,
        **request_context(request),
    )
    db.commit()
    db.refresh(complaint)
    return complaint


@router.get("/{complaint_id}", response_model=ComplaintRead)
def get_complaint(
    complaint_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_page("complaints", "view")),
) -> Complaint:
    return get_or_404(db, Complaint, complaint_id, name="Complaint")


@router.patch("/{complaint_id}", response_model=ComplaintRead)
def update_complaint(
    complaint_id: int,
    body: ComplaintUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("complaints.edit")),
) -> Complaint:
    complaint = get_or_404(db, Complaint, complaint_id, name="Complaint")
    before = audit.snapshot(complaint)
    data = body.model_dump(exclude_unset=True)
    new_status = data.get("status")
    for key, value in data.items():
        setattr(complaint, key, value)
    if new_status in _CLOSED and complaint.closed_at is None:
        complaint.closed_at = datetime.now(timezone.utc)
    complaint.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type=ENTITY,
        entity_id=complaint.id,
        before=before,
        after=complaint,
        **request_context(request),
    )
    db.commit()
    db.refresh(complaint)
    return complaint


@router.delete("/{complaint_id}", response_model=ComplaintRead)
def soft_delete_complaint(
    complaint_id: int,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("complaints.edit")),
) -> Complaint:
    complaint = get_or_404(db, Complaint, complaint_id, name="Complaint")
    complaint.soft_delete(actor.id)
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="soft_delete",
        entity_type=ENTITY,
        entity_id=complaint.id,
        **request_context(request),
    )
    db.commit()
    db.refresh(complaint)
    return complaint
