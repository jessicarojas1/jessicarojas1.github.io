"""Generic CRUD + listing helpers shared by routers (DRY base)."""
from __future__ import annotations

from typing import Any, TypeVar

from fastapi import Request
from sqlalchemy import asc, desc, func, select
from sqlalchemy.orm import Session

from app.api.deps import Pagination, SortParams
from app.core import audit
from app.core.exceptions import NotFoundError
from app.schemas.auth import CurrentUser

ModelT = TypeVar("ModelT")


def request_context(request: Request) -> dict[str, str | None]:
    """Extract client IP and request-id for audit logging."""
    client = request.client.host if request.client else None
    fwd = request.headers.get("X-Forwarded-For")
    ip = fwd.split(",")[0].strip() if fwd else client
    return {"ip": ip, "request_id": getattr(request.state, "request_id", None)}


def get_or_404(db: Session, model: type[ModelT], pk: int, *, name: str = "Record") -> ModelT:
    obj = db.get(model, pk)
    soft_deleted = getattr(obj, "is_deleted", False) if obj is not None else False
    if obj is None or soft_deleted:
        raise NotFoundError(f"{name} {pk} not found.")
    return obj


def apply_sort(stmt, model, sort: SortParams | None, *, default_col: str = "id"):
    col_name = (sort.sort_by if sort and sort.sort_by else default_col)
    column = getattr(model, col_name, None)
    if column is None:
        column = getattr(model, default_col)
    direction = desc if (sort and sort.order == "desc") else asc
    if sort is None:
        direction = desc
    return stmt.order_by(direction(column))


def paginate(
    db: Session,
    stmt,
    model,
    pagination: Pagination,
) -> tuple[list[Any], int]:
    """Return (items, total) for a SELECT statement with offset pagination."""
    count_stmt = select(func.count()).select_from(stmt.order_by(None).subquery())
    total = int(db.execute(count_stmt).scalar_one())
    rows = (
        db.execute(stmt.offset(pagination.offset).limit(pagination.limit)).scalars().all()
    )
    return list(rows), total


def page_meta(total: int, pagination: Pagination) -> dict[str, int]:
    pages = (total + pagination.size - 1) // pagination.size if pagination.size else 0
    return {
        "total": total,
        "page": pagination.page,
        "size": pagination.size,
        "pages": pages,
    }


def base_select(model, *, only_active: bool = True):
    """Build a base SELECT that excludes soft-deleted rows when supported."""
    stmt = select(model)
    if only_active and hasattr(model, "is_deleted"):
        stmt = stmt.where(model.is_deleted.is_(False))
    return stmt


def apply_create_audit(
    db: Session,
    request: Request,
    actor: CurrentUser,
    obj,
    *,
    entity_type: str,
) -> None:
    ctx = request_context(request)
    obj.created_by = actor.id
    obj.updated_by = actor.id
    db.add(obj)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create",
        entity_type=entity_type,
        entity_id=getattr(obj, "id", None),
        after=obj,
        **ctx,
    )


def apply_update_audit(
    db: Session,
    request: Request,
    actor: CurrentUser,
    obj,
    before: dict,
    *,
    entity_type: str,
    action: str = "update",
) -> None:
    ctx = request_context(request)
    obj.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action=action,
        entity_type=entity_type,
        entity_id=getattr(obj, "id", None),
        before=before,
        after=obj,
        **ctx,
    )
