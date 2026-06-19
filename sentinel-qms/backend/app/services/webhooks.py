"""Outbound webhook signing, enqueue, and bounded-retry dispatch.

Design: enqueue is **synchronous and transactional** — when a subscribed event
occurs, a ``WebhookDelivery`` row is written in the same transaction as the
change (so a rolled-back change never emits a phantom event). Actual HTTP
dispatch is decoupled: it runs out-of-band via :func:`dispatch_due`, invoked by
the background scheduler, with exponential backoff and a hard attempt cap. This
keeps the request path fast and makes delivery reliable across restarts.

Payloads are signed with HMAC-SHA256 over the exact JSON bytes sent, using the
webhook's secret. Receivers verify the ``X-Sentinel-Signature`` header.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import logging
import time
import urllib.error
import urllib.request
from datetime import UTC, datetime, timedelta

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.config import settings
from app.core.net_guard import is_public_http_url
from app.models.webhook import Webhook, WebhookDelivery

logger = logging.getLogger("app.webhooks")

_HTTP_TIMEOUT = 5  # seconds
MAX_ATTEMPTS = 6
# Exponential backoff schedule (seconds) indexed by prior attempt count.
_BACKOFF = (30, 120, 300, 900, 3600)
SIGNATURE_HEADER = "X-Sentinel-Signature"


def sign_payload(secret: str, body: bytes) -> str:
    """Return the ``sha256=<hex>`` HMAC signature for ``body`` under ``secret``."""
    digest = hmac.new(secret.encode("utf-8"), body, hashlib.sha256).hexdigest()
    return f"sha256={digest}"


def _backoff_seconds(attempts: int) -> int:
    idx = min(attempts, len(_BACKOFF) - 1)
    return _BACKOFF[idx]


def enqueue_event(
    db: Session,
    *,
    event_type: str,
    payload: dict,
) -> int:
    """Create a pending delivery for every active webhook subscribed to the event.

    Adds rows to the current session (no commit) so emission is atomic with the
    caller's transaction. Returns the number of deliveries queued. Cheap when no
    webhooks are configured (a single indexed query returning nothing).
    """
    if not settings.WEBHOOKS_ENABLED:
        return 0
    hooks = db.execute(select(Webhook).where(Webhook.active.is_(True))).scalars().all()
    if not hooks:
        return 0
    now = datetime.now(UTC)
    queued = 0
    for hook in hooks:
        if not hook.subscribes_to(event_type):
            continue
        db.add(
            WebhookDelivery(
                webhook_id=hook.id,
                event_type=event_type,
                payload=payload,
                status="pending",
                attempts=0,
                next_attempt_at=now,
            )
        )
        queued += 1
    return queued


def _post(url: str, body: bytes, headers: dict[str, str]) -> tuple[int, str]:
    """POST raw bytes; return (status_code, detail). 0 status => transport error."""
    req = urllib.request.Request(url, data=body, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=_HTTP_TIMEOUT) as resp:  # noqa: S310
            return resp.status, "ok"
    except urllib.error.HTTPError as exc:  # noqa: PERF203
        return exc.code, f"HTTP {exc.code}"
    except Exception as exc:  # noqa: BLE001 — never raise to the dispatcher
        return 0, str(exc) or exc.__class__.__name__


def _deliver_one(db: Session, delivery: WebhookDelivery, hook: Webhook) -> bool:
    """Attempt a single delivery, update its row, and return success."""
    body = json.dumps(
        {
            "event": delivery.event_type,
            "delivery_id": delivery.id,
            "created_at": delivery.created_at.isoformat() if delivery.created_at else None,
            "data": delivery.payload,
        },
        default=str,
    ).encode("utf-8")
    now = datetime.now(UTC)
    delivery.attempts += 1

    if not is_public_http_url(hook.url):
        # Fail-closed against SSRF; don't keep retrying an unroutable target.
        delivery.status = "dead"
        delivery.last_error = "blocked: webhook URL must resolve to a public address"
        delivery.next_attempt_at = None
        return False

    headers = {
        "Content-Type": "application/json",
        "User-Agent": "Sentinel-QMS-Webhook/1",
        "X-Sentinel-Event": delivery.event_type,
        "X-Sentinel-Delivery": str(delivery.id),
        "X-Sentinel-Timestamp": str(int(now.timestamp())),
        SIGNATURE_HEADER: sign_payload(hook.secret, body),
    }
    started = time.perf_counter()
    code, detail = _post(hook.url, body, headers)
    delivery.duration_ms = int((time.perf_counter() - started) * 1000)
    delivery.last_status_code = code or None

    if 200 <= code < 300:
        delivery.status = "success"
        delivery.delivered_at = now
        delivery.last_error = None
        delivery.next_attempt_at = None
        return True

    # Failure: schedule a retry or give up once the cap is reached.
    delivery.last_error = detail
    if delivery.attempts >= MAX_ATTEMPTS:
        delivery.status = "dead"
        delivery.next_attempt_at = None
    else:
        delivery.status = "failed"
        delivery.next_attempt_at = now + timedelta(seconds=_backoff_seconds(delivery.attempts))
    return False


def attempt_delivery(db: Session, delivery_id: int) -> bool:
    """Attempt one specific delivery now and commit. Returns success.

    Used by the manual test/redeliver endpoints for deterministic behavior.
    """
    delivery = db.get(WebhookDelivery, delivery_id)
    if delivery is None:
        return False
    hook = db.get(Webhook, delivery.webhook_id)
    if hook is None or not hook.active:
        delivery.status = "dead"
        delivery.last_error = "webhook removed or inactive"
        delivery.next_attempt_at = None
        db.commit()
        return False
    ok = _deliver_one(db, delivery, hook)
    db.commit()
    return ok


def dispatch_due(db: Session, *, limit: int = 50) -> dict:
    """Dispatch all due (pending/failed) deliveries whose next attempt has passed.

    Idempotent and safe to run from every worker — each delivery row is processed
    and committed individually. Returns a small summary.
    """
    if not settings.WEBHOOKS_ENABLED:
        return {"attempted": 0, "succeeded": 0, "failed": 0}
    now = datetime.now(UTC)
    due = (
        db.execute(
            select(WebhookDelivery)
            .where(
                WebhookDelivery.status.in_(("pending", "failed")),
                WebhookDelivery.next_attempt_at.is_not(None),
                WebhookDelivery.next_attempt_at <= now,
            )
            .order_by(WebhookDelivery.id.asc())
            .limit(limit)
        )
        .scalars()
        .all()
    )
    attempted = succeeded = failed = 0
    for delivery in due:
        hook = db.get(Webhook, delivery.webhook_id)
        if hook is None or not hook.active:
            delivery.status = "dead"
            delivery.last_error = "webhook removed or inactive"
            delivery.next_attempt_at = None
            db.commit()
            continue
        attempted += 1
        ok = _deliver_one(db, delivery, hook)
        succeeded += int(ok)
        failed += int(not ok)
        db.commit()
    return {"attempted": attempted, "succeeded": succeeded, "failed": failed}
