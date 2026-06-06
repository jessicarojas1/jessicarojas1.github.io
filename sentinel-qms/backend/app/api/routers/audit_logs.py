"""Audit-trail viewer API (admin only)."""
from __future__ import annotations

from fastapi import APIRouter, Depends, Query
from sqlalchemy.orm import Session

from app.api.deps import Pagination, pagination_params, require_page
from app.core.database import get_db
from app.models.user import AuditLog
from app.schemas.auth import CurrentUser
from app.schemas.common import AuditLogRead, Page
from app.services.crud import page_meta, paginate

router = APIRouter(prefix="/audit-logs", tags=["audit-logs"])


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
