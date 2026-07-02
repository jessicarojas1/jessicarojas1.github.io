"""Generic CRUD + listing helpers shared by routers (DRY base)."""

from __future__ import annotations

from datetime import UTC, datetime
from typing import Any, TypeVar

from fastapi import Request
from sqlalchemy import asc, desc, func, select
from sqlalchemy.orm import Session

from app.api.deps import Pagination, SortParams
from app.core import audit
from app.core.exceptions import ConflictError, NotFoundError
from app.schemas.auth import CurrentUser

ModelT = TypeVar("ModelT")


def _as_utc_seconds(value: datetime) -> datetime:
    """Normalize a datetime to timezone-aware UTC truncated to whole seconds.

    Naive datetimes are assumed to already be UTC. Truncating to the second
    avoids sub-second serialization drift between the DB and a client that
    round-trips ``updated_at`` as an ISO string through JSON.
    """
    if value.tzinfo is None:
        value = value.replace(tzinfo=UTC)
    return value.astimezone(UTC).replace(microsecond=0)


def guard_concurrency(obj, expected_updated_at) -> None:  # noqa: ANN001
    """Raise ConflictError if the client's expected updated_at is stale.

    Optimistic-concurrency lost-update guard. No-op when expected_updated_at is
    None (client opted out / legacy client). Compares to the whole second to
    avoid sub-second serialization drift between DB and client.
    """
    if expected_updated_at is None:
        return
    current = getattr(obj, "updated_at", None)
    if current is None:
        return
    if _as_utc_seconds(current) != _as_utc_seconds(expected_updated_at):
        raise ConflictError(
            "This record was modified by someone else since you loaded it. "
            "Reload and reapply your changes.",
            code="stale_write",
        )


def request_context(request: Request) -> dict[str, str | None]:
    """Extract client IP, User-Agent, and request-id for audit logging."""
    client = request.client.host if request.client else None
    fwd = request.headers.get("X-Forwarded-For")
    ip = fwd.split(",")[0].strip() if fwd else client
    ua = request.headers.get("User-Agent")
    return {
        "ip": ip,
        "user_agent": ua[:256] if ua else None,
        "request_id": getattr(request.state, "request_id", None),
    }


def get_or_404(db: Session, model: type[ModelT], pk: int, *, name: str = "Record") -> ModelT:
    obj = db.get(model, pk)
    soft_deleted = getattr(obj, "is_deleted", False) if obj is not None else False
    if obj is None or soft_deleted:
        raise NotFoundError(f"{name} {pk} not found.")
    return obj


def apply_sort(stmt, model, sort: SortParams | None, *, default_col: str = "id"):
    col_name = sort.sort_by if sort and sort.sort_by else default_col
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
    rows = db.execute(stmt.offset(pagination.offset).limit(pagination.limit)).scalars().all()
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
