"""Audit-trail viewer API (admin only)."""

from __future__ import annotations

import csv
import io

from fastapi import APIRouter, Depends, Query, Request, Response
from sqlalchemy.orm import Session

from app.api.deps import Pagination, get_current_user, pagination_params, require_page
from app.core import audit
from app.core.database import get_db
from app.core.entity_access import require_entity_view
from app.models.user import AuditLog
from app.schemas.auth import CurrentUser
from app.schemas.common import AuditLogRead, Page
from app.services.crud import page_meta, paginate, request_context

router = APIRouter(prefix="/audit-logs", tags=["audit-logs"])

# Hard cap on exported rows to avoid streaming an unbounded result set.
_EXPORT_MAX_ROWS = 50_000

# Columns emitted in the CSV export, in order.
_EXPORT_COLUMNS = (
    "id",
    "created_at",
    "actor_id",
    "actor_email",
    "action",
    "entity_type",
    "entity_id",
    "ip_address",
    "user_agent",
    "request_id",
)


@router.get("/record", response_model=list[AuditLogRead])
def list_record_audit_logs(
    entity_type: str = Query(..., max_length=64),
    entity_id: str = Query(..., max_length=64),
    limit: int = Query(100, ge=1, le=200),
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(get_current_user),
) -> list[AuditLog]:
    """Record-scoped history: anyone who can view a record can see its audit trail."""
    from sqlalchemy import select

    require_entity_view(db, actor, entity_type)
    stmt = (
        select(AuditLog)
        .where(
            AuditLog.entity_type == entity_type,
            AuditLog.entity_id == entity_id,
        )
        .order_by(AuditLog.created_at.desc(), AuditLog.id.desc())
        .limit(limit)
    )
    return list(db.execute(stmt).scalars().all())


@router.get("", response_model=Page[AuditLogRead])
def list_audit_logs(
    db: Session = Depends(get_db),
    pagination: Pagination = Depends(pagination_params),
    entity_type: str | None = Query(None),
    action: str | None = Query(None),
    actor_email: str | None = Query(None, description="ILIKE match on actor email"),
    entity_id: str | None = Query(None),
    _: CurrentUser = Depends(require_page("audit_trail", "view")),
) -> Page[AuditLogRead]:
    from sqlalchemy import select

    stmt = select(AuditLog)
    if entity_type:
        stmt = stmt.where(AuditLog.entity_type == entity_type)
    if action:
        stmt = stmt.where(AuditLog.action == action)
    if actor_email:
        stmt = stmt.where(AuditLog.actor_email.ilike(f"%{actor_email}%"))
    if entity_id:
        stmt = stmt.where(AuditLog.entity_id == entity_id)
    stmt = stmt.order_by(AuditLog.created_at.desc())
    items, total = paginate(db, stmt, AuditLog, pagination)
    return Page[AuditLogRead](items=items, **page_meta(total, pagination))


@router.get("/export.csv")
def export_audit_logs(
    request: Request,
    db: Session = Depends(get_db),
    entity_type: str | None = Query(None),
    action: str | None = Query(None),
    actor_email: str | None = Query(None, description="ILIKE match on actor email"),
    entity_id: str | None = Query(None),
    actor: CurrentUser = Depends(require_page("audit_trail", "view")),
) -> Response:
    """Export the (filtered) audit trail as a CSV attachment.

    Mirrors the filters and permission of :func:`list_audit_logs` exactly. Capped
    at ``_EXPORT_MAX_ROWS`` rows ordered by id desc. The export action is itself
    recorded in the audit trail before the file is returned.
    """
    from sqlalchemy import select

    stmt = select(AuditLog)
    if entity_type:
        stmt = stmt.where(AuditLog.entity_type == entity_type)
    if action:
        stmt = stmt.where(AuditLog.action == action)
    if actor_email:
        stmt = stmt.where(AuditLog.actor_email.ilike(f"%{actor_email}%"))
    if entity_id:
        stmt = stmt.where(AuditLog.entity_id == entity_id)
    stmt = stmt.order_by(AuditLog.id.desc()).limit(_EXPORT_MAX_ROWS)
    rows = list(db.execute(stmt).scalars().all())

    buf = io.StringIO()
    writer = csv.writer(buf)
    writer.writerow(_EXPORT_COLUMNS)
    for row in rows:
        writer.writerow(
            [
                row.id,
                row.created_at.isoformat() if row.created_at is not None else "",
                row.actor_id if row.actor_id is not None else "",
                row.actor_email or "",
                row.action,
                row.entity_type,
                row.entity_id or "",
                row.ip_address or "",
                row.user_agent or "",
                row.request_id or "",
            ]
        )

    # Exporting the immutable audit trail is itself an auditable event.
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="export",
        entity_type="audit_log",
        after={"rows": len(rows), "capped": len(rows) >= _EXPORT_MAX_ROWS},
        **request_context(request),
    )
    db.commit()

    return Response(
        content=buf.getvalue(),
        media_type="text/csv",
        headers={"Content-Disposition": 'attachment; filename="audit-log.csv"'},
    )
