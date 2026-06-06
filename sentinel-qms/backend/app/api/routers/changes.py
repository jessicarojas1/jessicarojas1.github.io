"""Change order (ECN/ECO) endpoints: CRUD + status workflow + approval."""
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
from app.models.change import ChangeOrder, ChangeStatus, ChangeType
from app.schemas.auth import CurrentUser
from app.schemas.change import (
    ChangeApproval,
    ChangeOrderCreate,
    ChangeOrderList,
    ChangeOrderRead,
    ChangeOrderUpdate,
    ChangeStatusChange,
)
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
from app.services.notifications import notify_assignment
from app.services.signatures import create_signature
from app.services.workflow import StateMachine

router = APIRouter(prefix="/changes", tags=["changes"])

ENTITY = "change_order"

CHANGE_FSM = StateMachine(
    {
        ChangeStatus.DRAFT: {ChangeStatus.SUBMITTED},
        ChangeStatus.SUBMITTED: {ChangeStatus.UNDER_REVIEW, ChangeStatus.DRAFT},
        ChangeStatus.UNDER_REVIEW: {ChangeStatus.APPROVED, ChangeStatus.REJECTED},
        ChangeStatus.APPROVED: {ChangeStatus.IMPLEMENTED},
        ChangeStatus.IMPLEMENTED: {ChangeStatus.CLOSED},
        ChangeStatus.REJECTED: {ChangeStatus.DRAFT},
        ChangeStatus.CLOSED: set(),
    },
    name="Change Order",
)


@router.get("", response_model=Page[ChangeOrderList])
def list_changes(
    db: Session = Depends(get_db),
    pagination: Pagination = Depends(pagination_params),
    sort: SortParams = Depends(sort_params),
    status_filter: ChangeStatus | None = Query(None, alias="status"),
    change_type: ChangeType | None = Query(None),
    _: CurrentUser = Depends(require_page("changes", "view")),
) -> Page[ChangeOrderList]:
    stmt = base_select(ChangeOrder)
    if status_filter:
        stmt = stmt.where(ChangeOrder.status == status_filter)
    if change_type:
        stmt = stmt.where(ChangeOrder.change_type == change_type)
    stmt = apply_sort(stmt, ChangeOrder, sort)
    items, total = paginate(db, stmt, ChangeOrder, pagination)
    return Page[ChangeOrderList](items=items, **page_meta(total, pagination))


@router.post("", response_model=ChangeOrderRead, status_code=status.HTTP_201_CREATED)
def create_change(
    body: ChangeOrderCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_page("changes", "edit")),
) -> ChangeOrder:
    co = ChangeOrder(
        **body.model_dump(),
        change_number=numbering.next_number(db, ChangeOrder, "change_number", "ECN"),
        status=ChangeStatus.DRAFT,
        requested_by=actor.id,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(co)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create",
        entity_type=ENTITY,
        entity_id=co.id,
        after=co,
        **request_context(request),
    )
    if co.owner_id:
        notify_assignment(
            db,
            user_id=co.owner_id,
            title=f"Change {co.change_number} assigned to you",
            message=co.title,
            entity_type=ENTITY,
            entity_id=co.id,
        )
    db.commit()
    db.refresh(co)
    return co


@router.get("/{change_id}", response_model=ChangeOrderRead)
def get_change(
    change_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_page("changes", "view")),
) -> ChangeOrder:
    return get_or_404(db, ChangeOrder, change_id, name="Change order")


@router.patch("/{change_id}", response_model=ChangeOrderRead)
def update_change(
    change_id: int,
    body: ChangeOrderUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_page("changes", "edit")),
) -> ChangeOrder:
    co = get_or_404(db, ChangeOrder, change_id, name="Change order")
    before = audit.snapshot(co)
    prev_owner = co.owner_id
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(co, key, value)
    co.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type=ENTITY,
        entity_id=co.id,
        before=before,
        after=co,
        **request_context(request),
    )
    if co.owner_id and co.owner_id != prev_owner:
        notify_assignment(
            db,
            user_id=co.owner_id,
            title=f"Change {co.change_number} assigned to you",
            message=co.title,
            entity_type=ENTITY,
            entity_id=co.id,
        )
    db.commit()
    db.refresh(co)
    return co


@router.post("/{change_id}/status", response_model=ChangeOrderRead)
def change_status(
    change_id: int,
    body: ChangeStatusChange,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_page("changes", "edit")),
) -> ChangeOrder:
    co = get_or_404(db, ChangeOrder, change_id, name="Change order")
    CHANGE_FSM.assert_transition(co.status, body.status)
    before = {"status": co.status.value}
    co.status = body.status
    if body.status == ChangeStatus.IMPLEMENTED:
        co.implemented_at = datetime.now(timezone.utc)
    co.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="status_change",
        entity_type=ENTITY,
        entity_id=co.id,
        before=before,
        after={"status": co.status.value},
        **request_context(request),
    )
    db.commit()
    db.refresh(co)
    return co


@router.post(
    "/{change_id}/approve",
    response_model=ChangeOrderRead,
    dependencies=[Depends(require_perm("changes.approve"))],
)
def approve_change(
    change_id: int,
    body: ChangeApproval,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_page("changes", "edit")),
) -> ChangeOrder:
    co = get_or_404(db, ChangeOrder, change_id, name="Change order")
    CHANGE_FSM.assert_transition(
        co.status,
        ChangeStatus.APPROVED if body.decision == "approved" else ChangeStatus.REJECTED,
    )
    sig = create_signature(
        db,
        actor=actor,
        entity_type=ENTITY,
        entity_id=co.id,
        payload=body.signature,
    )
    if body.decision == "approved":
        co.status = ChangeStatus.APPROVED
        co.approved_by = actor.id
        co.approved_at = datetime.now(timezone.utc)
        co.signature_id = sig.id
    else:
        co.status = ChangeStatus.REJECTED
    co.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="approve",
        entity_type=ENTITY,
        entity_id=co.id,
        after={"decision": body.decision, "signature_id": sig.id},
        **request_context(request),
    )
    db.commit()
    db.refresh(co)
    return co
