"""In-app notification API, scoped to the authenticated user."""

from __future__ import annotations

from fastapi import APIRouter, Depends, Query
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.api.deps import Pagination, get_current_user, pagination_params
from app.core.database import get_db
from app.core.exceptions import NotFoundError
from app.models.user import Notification
from app.schemas.auth import CurrentUser
from app.schemas.common import Page
from app.schemas.notification import NotificationRead, UnreadCount
from app.services.crud import page_meta, paginate

router = APIRouter(prefix="/notifications", tags=["notifications"])


@router.get("", response_model=Page[NotificationRead])
def list_notifications(
    db: Session = Depends(get_db),
    pagination: Pagination = Depends(pagination_params),
    unread_only: bool = Query(False),
    user: CurrentUser = Depends(get_current_user),
) -> Page[NotificationRead]:
    stmt = select(Notification).where(Notification.user_id == user.id)
    if unread_only:
        stmt = stmt.where(Notification.is_read.is_(False))
    stmt = stmt.order_by(Notification.created_at.desc())
    items, total = paginate(db, stmt, Notification, pagination)
    return Page[NotificationRead](
        items=[NotificationRead.model_validate(n) for n in items],
        **page_meta(total, pagination),
    )


@router.get("/unread-count", response_model=UnreadCount)
def unread_count(
    db: Session = Depends(get_db),
    user: CurrentUser = Depends(get_current_user),
) -> UnreadCount:
    count = db.execute(
        select(func.count())
        .select_from(Notification)
        .where(Notification.user_id == user.id, Notification.is_read.is_(False))
    ).scalar_one()
    return UnreadCount(count=int(count))


@router.post("/{notification_id}/read", response_model=NotificationRead)
def mark_read(
    notification_id: int,
    db: Session = Depends(get_db),
    user: CurrentUser = Depends(get_current_user),
) -> NotificationRead:
    notif = db.get(Notification, notification_id)
    if notif is None or notif.user_id != user.id:
        raise NotFoundError(f"Notification {notification_id} not found.")
    notif.is_read = True
    db.commit()
    db.refresh(notif)
    return NotificationRead.model_validate(notif)


@router.post("/read-all")
def mark_all_read(
    db: Session = Depends(get_db),
    user: CurrentUser = Depends(get_current_user),
) -> dict[str, int]:
    rows = (
        db.execute(
            select(Notification).where(
                Notification.user_id == user.id, Notification.is_read.is_(False)
            )
        )
        .scalars()
        .all()
    )
    for n in rows:
        n.is_read = True
    db.commit()
    return {"updated": len(rows)}
