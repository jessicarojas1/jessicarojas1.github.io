"""Outbound webhook administration (admin-only).

Register HTTPS endpoints that receive signed QMS lifecycle events, inspect and
redeliver individual deliveries, and send a signed test ping. Requires the
``user:manage`` permission (administrator). The signing secret is returned once
at creation and never exposed again.
"""

from __future__ import annotations

import secrets
from datetime import UTC, datetime

from fastapi import APIRouter, Depends, Request, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.database import get_db
from app.core.exceptions import NotFoundError, ValidationAppError
from app.core.net_guard import is_public_http_url
from app.core.rbac import Permission, require_permission
from app.models.webhook import Webhook, WebhookDelivery
from app.schemas.auth import CurrentUser
from app.schemas.webhook import (
    WebhookCreate,
    WebhookCreated,
    WebhookDeliveryRead,
    WebhookRead,
    WebhookUpdate,
)
from app.services import webhooks as svc

router = APIRouter(prefix="/webhooks", tags=["webhooks"])

_ADMIN = require_permission(Permission.USER_MANAGE)


def _require_public_url(url: str) -> None:
    # Fail-closed against SSRF before we ever store or call the URL.
    if not is_public_http_url(url):
        raise ValidationAppError(
            "Webhook URL must be an https(s) endpoint resolving to a public host."
        )


@router.get("", response_model=list[WebhookRead])
def list_webhooks(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_ADMIN),
) -> list[Webhook]:
    return list(db.execute(select(Webhook).order_by(Webhook.id.desc())).scalars().all())


@router.post("", response_model=WebhookCreated, status_code=status.HTTP_201_CREATED)
def create_webhook(
    body: WebhookCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_ADMIN),
) -> WebhookCreated:
    url = str(body.url)
    _require_public_url(url)
    secret = secrets.token_urlsafe(32)
    hook = Webhook(
        name=body.name.strip(),
        url=url,
        secret=secret,
        event_types=[e.strip() for e in body.event_types if e and e.strip()] or ["*"],
        description=body.description,
        active=body.active,
        created_by=actor.id,
    )
    db.add(hook)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="webhook.create",
        entity_type="webhook",
        entity_id=hook.id,
        after={"name": hook.name, "url": hook.url, "event_types": hook.event_types},
        ip=getattr(request.client, "host", None),
        request_id=getattr(request.state, "request_id", None),
    )
    db.commit()
    db.refresh(hook)
    payload = WebhookRead.model_validate(hook).model_dump()
    return WebhookCreated(**payload, secret=secret)


@router.patch("/{webhook_id}", response_model=WebhookRead)
def update_webhook(
    webhook_id: int,
    body: WebhookUpdate,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_ADMIN),
) -> Webhook:
    hook = db.get(Webhook, webhook_id)
    if hook is None:
        raise NotFoundError(f"Webhook {webhook_id} not found.")
    data = body.model_dump(exclude_unset=True)
    if "url" in data and data["url"] is not None:
        data["url"] = str(data["url"])
        _require_public_url(data["url"])
    if "event_types" in data and data["event_types"] is not None:
        data["event_types"] = [e.strip() for e in data["event_types"] if e and e.strip()] or ["*"]
    for key, value in data.items():
        setattr(hook, key, value)
    db.commit()
    db.refresh(hook)
    return hook


@router.delete("/{webhook_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_webhook(
    webhook_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_ADMIN),
) -> None:
    hook = db.get(Webhook, webhook_id)
    if hook is None:
        raise NotFoundError(f"Webhook {webhook_id} not found.")
    db.delete(hook)
    db.commit()


@router.get("/{webhook_id}/deliveries", response_model=list[WebhookDeliveryRead])
def list_deliveries(
    webhook_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_ADMIN),
) -> list[WebhookDelivery]:
    hook = db.get(Webhook, webhook_id)
    if hook is None:
        raise NotFoundError(f"Webhook {webhook_id} not found.")
    stmt = (
        select(WebhookDelivery)
        .where(WebhookDelivery.webhook_id == webhook_id)
        .order_by(WebhookDelivery.id.desc())
        .limit(100)
    )
    return list(db.execute(stmt).scalars().all())


@router.post("/{webhook_id}/test", response_model=WebhookDeliveryRead)
def send_test(
    webhook_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_ADMIN),
) -> WebhookDelivery:
    """Queue and immediately attempt a signed ``webhook.ping`` delivery."""
    hook = db.get(Webhook, webhook_id)
    if hook is None:
        raise NotFoundError(f"Webhook {webhook_id} not found.")
    delivery = WebhookDelivery(
        webhook_id=hook.id,
        event_type="webhook.ping",
        payload={
            "event": "webhook.ping",
            "message": "Test delivery from Sentinel QMS",
            "by": actor.email,
            "occurred_at": datetime.now(UTC).isoformat(),
        },
        status="pending",
        attempts=0,
        next_attempt_at=datetime.now(UTC),
    )
    db.add(delivery)
    db.commit()
    # Attempt now; the scheduler remains the retry safety net on failure.
    svc.attempt_delivery(db, delivery.id)
    db.refresh(delivery)
    return delivery


@router.post("/deliveries/{delivery_id}/redeliver", response_model=WebhookDeliveryRead)
def redeliver(
    delivery_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_ADMIN),
) -> WebhookDelivery:
    """Re-queue a past delivery for another attempt (now)."""
    delivery = db.get(WebhookDelivery, delivery_id)
    if delivery is None:
        raise NotFoundError(f"Delivery {delivery_id} not found.")
    delivery.status = "pending"
    delivery.next_attempt_at = datetime.now(UTC)
    db.commit()
    svc.attempt_delivery(db, delivery.id)
    db.refresh(delivery)
    return delivery
