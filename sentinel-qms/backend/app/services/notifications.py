"""Notification creation plus best-effort multi-channel dispatch.

In-app :class:`Notification` rows are always created; outbound delivery (Email +
Microsoft Teams + Slack) is fanned out, in the background, via
:mod:`app.services.delivery` so a misconfigured/unreachable channel can never
break the calling request.
"""

from __future__ import annotations

import logging

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.rbac import Role
from app.models.user import Notification, User
from app.models.user import Role as RoleModel
from app.schemas.notification import notification_url
from app.services import delivery

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
        recipient = db.get(User, user_id)
        # Honor the recipient's opt-outs: a muted category still creates the
        # in-app record (nothing critical is silently lost) but skips email/chat.
        prefs = getattr(recipient, "notification_prefs", None) or {}
        if category in set(prefs.get("muted_categories", [])):
            return notif
        cfg = delivery.resolve_channels(db)
        link = notification_url(entity_type, str(entity_id) if entity_id is not None else None)
        delivery.dispatch_notification(
            recipient_email=recipient.email if recipient is not None else None,
            title=title,
            body=body,
            link=link,
            cfg=cfg,
        )
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


def notify_assignment(
    db: Session,
    *,
    user_id: int,
    title: str,
    message: str | None = None,
    entity_type: str | None = None,
    entity_id: str | int | None = None,
) -> Notification:
    """Create an in-app notification and best-effort dispatch to all channels.

    External dispatch is backgrounded and best-effort so a misconfigured or
    unreachable channel can never break the calling request (assignment must
    still succeed).
    """
    notif = Notification(
        user_id=user_id,
        title=title,
        body=message,
        category="assignment",
        entity_type=entity_type,
        entity_id=str(entity_id) if entity_id is not None else None,
    )
    db.add(notif)
    db.flush()

    link = notification_url(entity_type, str(entity_id) if entity_id is not None else None)
    recipient = db.get(User, user_id)
    cfg = delivery.resolve_channels(db)
    delivery.dispatch_notification(
        recipient_email=recipient.email if recipient is not None else None,
        title=title,
        body=message,
        link=link,
        cfg=cfg,
    )

    return notif
