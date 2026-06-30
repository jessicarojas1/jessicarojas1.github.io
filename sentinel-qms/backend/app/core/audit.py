"""Immutable audit-log helper used by every state-changing endpoint."""

from __future__ import annotations

import logging
from datetime import UTC, date, datetime
from decimal import Decimal
from enum import Enum
from typing import Any

from sqlalchemy.orm import Session

from app.models.user import AuditLog

logger = logging.getLogger("app.audit")

# Columns that should never be written into the audit "before"/"after" snapshots.
_REDACT = {"hashed_password", "password", "signed_hash"}


def _jsonable(value: Any) -> Any:
    if value is None or isinstance(value, str | int | float | bool):
        return value
    if isinstance(value, datetime | date):
        return value.isoformat()
    if isinstance(value, Decimal):
        return float(value)
    if isinstance(value, Enum):
        return value.value
    if isinstance(value, list | tuple):
        return [_jsonable(v) for v in value]
    if isinstance(value, dict):
        return {k: _jsonable(v) for k, v in value.items()}
    return str(value)


def snapshot(obj: Any) -> dict | None:
    """Serialize an ORM instance's column values into a redacted dict."""
    if obj is None:
        return None
    try:
        mapper = obj.__mapper__
    except AttributeError:
        return None
    data: dict[str, Any] = {}
    for col in mapper.columns:
        key = col.key
        if key in _REDACT:
            continue
        data[key] = _jsonable(getattr(obj, key, None))
    return data


def record(
    db: Session,
    *,
    actor_id: int | None,
    actor_email: str | None,
    action: str,
    entity_type: str,
    entity_id: str | int | None = None,
    before: dict | Any | None = None,
    after: dict | Any | None = None,
    ip: str | None = None,
    user_agent: str | None = None,
    request_id: str | None = None,
    flush: bool = True,
) -> AuditLog:
    """Append an immutable audit entry.

    ``before``/``after`` may be either raw dicts or ORM instances (auto-snapshotted).
    The entry is added to the session; the caller's transaction commits it so the
    audit record shares the atomicity of the change it describes.
    """
    if before is not None and not isinstance(before, dict):
        before = snapshot(before)
    if after is not None and not isinstance(after, dict):
        after = snapshot(after)

    entry = AuditLog(
        actor_id=actor_id,
        actor_email=actor_email,
        action=action,
        entity_type=entity_type,
        entity_id=str(entity_id) if entity_id is not None else None,
        before=before,
        after=after,
        ip_address=ip,
        user_agent=user_agent[:256] if user_agent else None,
        request_id=request_id,
    )
    db.add(entry)
    if flush:
        db.flush()
    logger.info(
        "audit",
        extra={
            "request_id": request_id or "-",
            "audit_action": action,
            "entity_type": entity_type,
            "entity_id": str(entity_id) if entity_id is not None else None,
            "actor_id": actor_id,
        },
    )
    # Fan regulated actions out to registered webhooks (atomic with this change;
    # actual HTTP dispatch is backgrounded). Best-effort: never break the audit.
    try:
        _emit_webhook_event(
            db,
            action=action,
            entity_type=entity_type,
            entity_id=entity_id,
            actor_id=actor_id,
            actor_email=actor_email,
            after=after,
            request_id=request_id,
        )
    except Exception:  # noqa: BLE001 — webhook emission must never break auditing
        logger.warning("webhook_enqueue_failed", exc_info=True)
    return entry


def _normalize_action(action: str) -> str:
    """Slugify a free-text audit action into a stable event suffix."""
    out = []
    prev_us = False
    for ch in action.strip().lower():
        if ch.isalnum():
            out.append(ch)
            prev_us = False
        elif not prev_us:
            out.append("_")
            prev_us = True
    return "".join(out).strip("_")


def _emit_webhook_event(
    db: Session,
    *,
    action: str,
    entity_type: str,
    entity_id: str | int | None,
    actor_id: int | None,
    actor_email: str | None,
    after: dict | None,
    request_id: str | None,
) -> None:
    from app.services import webhooks

    event_type = f"{entity_type}.{_normalize_action(action)}"
    webhooks.enqueue_event(
        db,
        event_type=event_type,
        payload={
            "event": event_type,
            "entity_type": entity_type,
            "entity_id": str(entity_id) if entity_id is not None else None,
            "action": action,
            "actor": {"id": actor_id, "email": actor_email},
            "request_id": request_id,
            "occurred_at": datetime.now(UTC).isoformat(),
            "record": after,
        },
    )
