"""CAPA endpoints: CRUD + 8D actions + status workflow + effectiveness close-out."""

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
from app.core.exceptions import NotFoundError, WorkflowError
from app.models.capa import (
    Capa,
    CapaAction,
    CapaActionStatus,
    CapaStatus,
)
from app.models.nonconformance import Nonconformance
from app.schemas.auth import CurrentUser
from app.schemas.capa import (
    CapaActionCreate,
    CapaActionRead,
    CapaActionUpdate,
    CapaClose,
    CapaCreate,
    CapaEffectivenessVerify,
    CapaList,
    CapaRead,
    CapaStatusChange,
    CapaUpdate,
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
from app.services.workflow import StateMachine, require_states

router = APIRouter(prefix="/capa", tags=["capa"])

ENTITY = "capa"

OPEN_STATES = {
    CapaStatus.OPEN,
    CapaStatus.CONTAINMENT,
    CapaStatus.ROOT_CAUSE,
    CapaStatus.ACTION_PLAN,
    CapaStatus.IMPLEMENTATION,
    CapaStatus.VERIFICATION,
}

CAPA_FSM = StateMachine(
    {
        CapaStatus.OPEN: {CapaStatus.CONTAINMENT, CapaStatus.ROOT_CAUSE, CapaStatus.CANCELLED},
        CapaStatus.CONTAINMENT: {CapaStatus.ROOT_CAUSE, CapaStatus.CANCELLED},
        CapaStatus.ROOT_CAUSE: {CapaStatus.ACTION_PLAN, CapaStatus.CANCELLED},
        CapaStatus.ACTION_PLAN: {CapaStatus.IMPLEMENTATION, CapaStatus.CANCELLED},
        CapaStatus.IMPLEMENTATION: {CapaStatus.VERIFICATION, CapaStatus.CANCELLED},
        CapaStatus.VERIFICATION: {CapaStatus.CLOSED, CapaStatus.IMPLEMENTATION},
        CapaStatus.CLOSED: set(),
        CapaStatus.CANCELLED: set(),
    },
    name="CAPA",
)


@router.get("", response_model=Page[CapaList])
def list_capas(
    db: Session = Depends(get_db),
    pagination: Pagination = Depends(pagination_params),
    sort: SortParams = Depends(sort_params),
    status_filter: CapaStatus | None = Query(None, alias="status"),
    owner_id: int | None = Query(None),
    overdue: bool | None = Query(None),
    search: str | None = Query(None),
    _: CurrentUser = Depends(require_page("capa", "view")),
) -> Page[CapaList]:
    stmt = base_select(Capa)
    if status_filter:
        stmt = stmt.where(Capa.status == status_filter)
    if owner_id:
        stmt = stmt.where(Capa.owner_id == owner_id)
    if overdue:
        from datetime import date

        stmt = stmt.where(
            Capa.due_date.is_not(None),
            Capa.due_date < date.today(),
            Capa.status.in_(OPEN_STATES),
        )
    if search:
        like = f"%{search}%"
        stmt = stmt.where(Capa.capa_number.ilike(like) | Capa.title.ilike(like))
    stmt = apply_sort(stmt, Capa, sort)
    items, total = paginate(db, stmt, Capa, pagination)
    return Page[CapaList](items=items, **page_meta(total, pagination))


@router.get("/export.csv")
def export_capas_csv(
    request: Request,
    db: Session = Depends(get_db),
    status_filter: CapaStatus | None = Query(None, alias="status"),
    owner_id: int | None = Query(None),
    overdue: bool | None = Query(None),
    search: str | None = Query(None),
    actor: CurrentUser = Depends(require_page("capa", "view")),
) -> Response:
    """Stream the filtered CAPA list as a CSV attachment (max 50,000 rows)."""
    stmt = base_select(Capa)
    if status_filter:
        stmt = stmt.where(Capa.status == status_filter)
    if owner_id:
        stmt = stmt.where(Capa.owner_id == owner_id)
    if overdue:
        from datetime import date

        stmt = stmt.where(
            Capa.due_date.is_not(None),
            Capa.due_date < date.today(),
            Capa.status.in_(OPEN_STATES),
        )
    if search:
        like = f"%{search}%"
        stmt = stmt.where(Capa.capa_number.ilike(like) | Capa.title.ilike(like))
    stmt = stmt.order_by(Capa.id.desc()).limit(50_000)
    rows = db.execute(stmt).scalars().all()

    columns = [
        "capa_number",
        "title",
        "capa_type",
        "status",
        "owner_id",
        "root_cause_method",
        "due_date",
        "created_at",
        "closed_at",
    ]
    buf = io.StringIO()
    writer = csv.writer(buf)
    writer.writerow(columns)
    for capa in rows:
        writer.writerow(
            [
                capa.capa_number,
                capa.title,
                capa.capa_type.value if capa.capa_type else "",
                capa.status.value if capa.status else "",
                capa.owner_id if capa.owner_id is not None else "",
                capa.root_cause_method or "",
                capa.due_date.isoformat() if capa.due_date else "",
                capa.created_at.isoformat() if capa.created_at else "",
                capa.closed_at.isoformat() if capa.closed_at else "",
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
        headers={"Content-Disposition": 'attachment; filename="capas.csv"'},
    )


@router.post("", response_model=CapaRead, status_code=status.HTTP_201_CREATED)
def create_capa(
    body: CapaCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("capa.create")),
) -> Capa:
    data = body.model_dump()
    ncr_id = data.pop("nonconformance_id", None)
    capa = Capa(
        **data,
        capa_number=numbering.next_number(db, Capa, "capa_number", "CAPA"),
        status=CapaStatus.OPEN,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(capa)
    db.flush()

    # Link originating NCR (bi-directional) if provided.
    if ncr_id is not None:
        ncr = db.get(Nonconformance, ncr_id)
        if ncr is None:
            raise NotFoundError(f"NCR {ncr_id} not found.")
        ncr.capa_id = capa.id

    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create",
        entity_type=ENTITY,
        entity_id=capa.id,
        after=capa,
        **request_context(request),
    )
    if capa.owner_id:
        notify_assignment(
            db,
            user_id=capa.owner_id,
            title=f"CAPA {capa.capa_number} assigned to you",
            message=capa.title,
            entity_type=ENTITY,
            entity_id=capa.id,
        )
    db.commit()
    db.refresh(capa)
    return capa


@router.get("/{capa_id}", response_model=CapaRead)
def get_capa(
    capa_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_page("capa", "view")),
) -> Capa:
    return get_or_404(db, Capa, capa_id, name="CAPA")


@router.patch("/{capa_id}", response_model=CapaRead)
def update_capa(
    capa_id: int,
    body: CapaUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("capa.edit")),
) -> Capa:
    capa = get_or_404(db, Capa, capa_id, name="CAPA")
    if capa.status in (CapaStatus.CLOSED, CapaStatus.CANCELLED):
        raise WorkflowError("Cannot edit a closed or cancelled CAPA.")
    before = audit.snapshot(capa)
    prev_owner = capa.owner_id
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(capa, key, value)
    capa.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type=ENTITY,
        entity_id=capa.id,
        before=before,
        after=capa,
        **request_context(request),
    )
    if capa.owner_id and capa.owner_id != prev_owner:
        notify_assignment(
            db,
            user_id=capa.owner_id,
            title=f"CAPA {capa.capa_number} assigned to you",
            message=capa.title,
            entity_type=ENTITY,
            entity_id=capa.id,
        )
    db.commit()
    db.refresh(capa)
    return capa


@router.post("/{capa_id}/status", response_model=CapaRead)
def change_status(
    capa_id: int,
    body: CapaStatusChange,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("capa.edit")),
) -> Capa:
    capa = get_or_404(db, Capa, capa_id, name="CAPA")
    CAPA_FSM.assert_transition(capa.status, body.status)

    # Gate progression on required 8D content.
    if body.status == CapaStatus.ACTION_PLAN and not capa.d4_root_cause:
        raise WorkflowError("Root cause (D4) must be documented before action planning.")
    if body.status == CapaStatus.VERIFICATION and not capa.d5_corrective_action:
        raise WorkflowError("A corrective action (D5) is required before verification.")

    before = {"status": capa.status.value}
    capa.status = body.status
    capa.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="status_change",
        entity_type=ENTITY,
        entity_id=capa.id,
        before=before,
        after={"status": capa.status.value},
        **request_context(request),
    )
    db.commit()
    db.refresh(capa)
    return capa


@router.post(
    "/{capa_id}/actions", response_model=CapaActionRead, status_code=status.HTTP_201_CREATED
)
def add_action(
    capa_id: int,
    body: CapaActionCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("capa.edit")),
) -> CapaAction:
    capa = get_or_404(db, Capa, capa_id, name="CAPA")
    action = CapaAction(
        capa_id=capa.id,
        **body.model_dump(),
        status=CapaActionStatus.OPEN,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(action)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="add_action",
        entity_type=ENTITY,
        entity_id=capa.id,
        after={"action_id": action.id, "description": action.description},
        **request_context(request),
    )
    db.commit()
    db.refresh(action)
    return action


@router.patch("/{capa_id}/actions/{action_id}", response_model=CapaActionRead)
def update_action(
    capa_id: int,
    action_id: int,
    body: CapaActionUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("capa.edit")),
) -> CapaAction:
    action = db.get(CapaAction, action_id)
    if action is None or action.capa_id != capa_id:
        raise NotFoundError(f"CAPA action {action_id} not found.")
    before = audit.snapshot(action)
    data = body.model_dump(exclude_unset=True)
    if data.get("status") == CapaActionStatus.COMPLETED and action.completed_at is None:
        action.completed_at = datetime.now(UTC)
    for key, value in data.items():
        setattr(action, key, value)
    action.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update_action",
        entity_type=ENTITY,
        entity_id=capa_id,
        before=before,
        after=action,
        **request_context(request),
    )
    db.commit()
    db.refresh(action)
    return action


@router.post("/{capa_id}/verify-effectiveness", response_model=CapaRead)
def verify_effectiveness(
    capa_id: int,
    body: CapaEffectivenessVerify,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("capa.edit")),
) -> Capa:
    capa = get_or_404(db, Capa, capa_id, name="CAPA")
    require_states(capa.status, {CapaStatus.VERIFICATION}, action="verify effectiveness")
    capa.effectiveness_verified = body.effective
    capa.effectiveness_notes = body.notes
    capa.effectiveness_verified_by = actor.id
    capa.effectiveness_verified_at = datetime.now(UTC)
    capa.updated_by = actor.id
    if not body.effective:
        # Effectiveness failed — return to implementation for further action.
        capa.status = CapaStatus.IMPLEMENTATION
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="verify_effectiveness",
        entity_type=ENTITY,
        entity_id=capa.id,
        after={"effective": body.effective, "status": capa.status.value},
        **request_context(request),
    )
    db.commit()
    db.refresh(capa)
    return capa


@router.post(
    "/{capa_id}/close",
    response_model=CapaRead,
    dependencies=[Depends(require_perm("capa.close"))],
)
def close_capa(
    capa_id: int,
    body: CapaClose,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_page("capa", "edit")),
) -> Capa:
    """Close-out requires verified effectiveness and an e-signature (Part 11)."""
    capa = get_or_404(db, Capa, capa_id, name="CAPA")
    require_states(capa.status, {CapaStatus.VERIFICATION}, action="close CAPA")
    if not capa.effectiveness_verified:
        raise WorkflowError("Effectiveness must be verified before closing the CAPA.")

    sig = create_signature(
        db,
        actor=actor,
        entity_type=ENTITY,
        entity_id=capa.id,
        payload=body.signature,
    )
    capa.d8_closure = body.d8_closure
    capa.status = CapaStatus.CLOSED
    capa.closed_at = datetime.now(UTC)
    capa.closure_signature_id = sig.id
    capa.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="close",
        entity_type=ENTITY,
        entity_id=capa.id,
        after={"status": capa.status.value, "signature_id": sig.id},
        **request_context(request),
    )
    db.commit()
    db.refresh(capa)
    return capa
