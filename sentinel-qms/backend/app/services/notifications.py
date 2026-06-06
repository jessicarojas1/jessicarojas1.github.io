"""Notification creation plus best-effort email / Microsoft Teams dispatch."""
from __future__ import annotations

import json
import logging
import smtplib
import urllib.request
from email.message import EmailMessage

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.config import settings
from app.core.rbac import Role
from app.models.user import Notification, Role as RoleModel, User
from app.schemas.notification import notification_url

logger = logging.getLogger("app.notifications")

_HTTP_TIMEOUT = 5  # seconds — keep dispatch from blocking the request


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


def notify_assignment(
    db: Session,
    *,
    user_id: int,
    title: str,
    message: str | None = None,
    entity_type: str | None = None,
    entity_id: str | int | None = None,
) -> Notification:
    """Create an in-app notification and best-effort dispatch to email + Teams.

    External dispatch is wrapped so a misconfigured/unreachable channel can never
    break the calling request (assignment must still succeed).
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

    if settings.TEAMS_WEBHOOK_URL:
        _dispatch_teams(title, message, link)

    if settings.SMTP_HOST:
        recipient = db.get(User, user_id)
        if recipient is not None and recipient.email:
            _dispatch_email(recipient.email, title, message, link)

    return notif


def _dispatch_teams(title: str, message: str | None, link: str | None) -> None:
    """POST a simple MessageCard to a Teams incoming webhook (stdlib only)."""
    try:
        card: dict = {
            "@type": "MessageCard",
            "@context": "http://schema.org/extensions",
            "summary": title,
            "title": title,
            "text": message or "",
        }
        if link:
            card["potentialAction"] = [
                {
                    "@type": "OpenUri",
                    "name": "Open in Sentinel QMS",
                    "targets": [{"os": "default", "uri": link}],
                }
            ]
        data = json.dumps(card).encode("utf-8")
        req = urllib.request.Request(
            settings.TEAMS_WEBHOOK_URL,
            data=data,
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        urllib.request.urlopen(req, timeout=_HTTP_TIMEOUT)  # noqa: S310
    except Exception:  # noqa: BLE001 — best-effort; never raise to the caller
        logger.warning("teams_dispatch_failed", exc_info=True)


def _dispatch_email(to_email: str, title: str, message: str | None, link: str | None) -> None:
    """Send a plaintext email via stdlib smtplib (best-effort)."""
    try:
        msg = EmailMessage()
        msg["Subject"] = title
        msg["From"] = settings.SMTP_FROM or settings.SMTP_USERNAME or "noreply@sentinel-qms.local"
        msg["To"] = to_email
        body = message or ""
        if link:
            body = f"{body}\n\n{link}".strip()
        msg.set_content(body or title)

        with smtplib.SMTP(settings.SMTP_HOST, settings.SMTP_PORT, timeout=_HTTP_TIMEOUT) as smtp:
            if settings.SMTP_USE_TLS:
                smtp.starttls()
            if settings.SMTP_USERNAME:
                smtp.login(settings.SMTP_USERNAME, settings.SMTP_PASSWORD)
            smtp.send_message(msg)
    except Exception:  # noqa: BLE001 — best-effort; never raise to the caller
        logger.warning("email_dispatch_failed", exc_info=True)
