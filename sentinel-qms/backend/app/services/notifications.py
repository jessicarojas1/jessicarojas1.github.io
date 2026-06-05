"""Notification creation with an email-delivery stub."""
from __future__ import annotations

import logging

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.rbac import Role
from app.models.user import Notification, Role as RoleModel, User

logger = logging.getLogger("app.notifications")


def notify_user(
    db: Session,
    *,
    user_id: int,
    title: str,
    body: str | None = None,
    category: str = "general",
    entity_type: str | None = None,
    entity_id: str | int | None = None,
    send_email: bool = True,
) -> Notification:
    notif = Notification(
        user_id=user_id,
        title=title,
        body=body,
        category=category,
        entity_type=entity_type,
        entity_id=str(entity_id) if entity_id is not None else None,
    )
    db.add(notif)
    db.flush()
    if send_email:
        _send_email_stub(db, user_id, title, body)
    return notif


def notify_roles(
    db: Session,
    *,
    roles: list[Role],
    title: str,
    body: str | None = None,
    category: str = "general",
    entity_type: str | None = None,
    entity_id: str | int | None = None,
) -> list[Notification]:
    """Create a notification for every active user holding one of ``roles``."""
    role_names = [r.value for r in roles]
    users = (
        db.execute(
            select(User)
            .join(User.roles)
            .where(RoleModel.name.in_(role_names), User.is_active.is_(True))
            .distinct()
        )
        .scalars()
        .all()
    )
    created: list[Notification] = []
    for u in users:
        created.append(
            notify_user(
                db,
                user_id=u.id,
                title=title,
                body=body,
                category=category,
                entity_type=entity_type,
                entity_id=entity_id,
            )
        )
    return created


def _send_email_stub(db: Session, user_id: int, title: str, body: str | None) -> None:
    """Stub: in production, enqueue to SES / Azure Communication Services.

    Intentionally logs only — no external network calls in this deployment.
    """
    logger.info(
        "email_stub",
        extra={"user_id": user_id, "subject": title, "has_body": bool(body)},
    )
