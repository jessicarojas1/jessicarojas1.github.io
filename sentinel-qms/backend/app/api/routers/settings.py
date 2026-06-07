"""Organization settings & branding endpoints (singleton row)."""
from __future__ import annotations

from fastapi import APIRouter, Depends, Request
from sqlalchemy.orm import Session

from app.api.deps import get_current_user
from app.core import audit
from app.core.database import get_db
from app.core.rbac import Permission, require_permission
from app.models.settings import OrgSettings
from app.schemas.auth import CurrentUser
from app.schemas.settings import (
    DigestSendRequest,
    DigestSendResult,
    NotificationTestRequest,
    NotificationTestResult,
    OrgSettingsRead,
    OrgSettingsUpdate,
    SlaSweepResult,
)
from app.services import delivery, report_digest, sla
from app.services.crud import request_context

router = APIRouter(prefix="/settings", tags=["settings"])

ENTITY = "org_settings"


def _get_or_create(db: Session) -> OrgSettings:
    """Return the singleton settings row, creating it with defaults if missing."""
    obj = db.get(OrgSettings, 1)
    if obj is None:
        # Fall back to any existing row before creating (defensive).
        obj = db.query(OrgSettings).order_by(OrgSettings.id.asc()).first()
    if obj is None:
        obj = OrgSettings(id=1)
        db.add(obj)
        db.commit()
        db.refresh(obj)
    return obj


@router.get("", response_model=OrgSettingsRead)
def get_settings(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(get_current_user),
) -> OrgSettings:
    """Return the organization settings (any authenticated user — branding)."""
    return _get_or_create(db)


@router.put("", response_model=OrgSettingsRead)
def update_settings(
    payload: OrgSettingsUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.USER_MANAGE)),
) -> OrgSettings:
    """Update provided settings fields. Requires USER_MANAGE."""
    obj = _get_or_create(db)
    before = audit.snapshot(obj)

    data = payload.model_dump(exclude_unset=True)
    for key, value in data.items():
        setattr(obj, key, value)
    obj.updated_by = actor.id

    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type=ENTITY,
        entity_id=obj.id,
        before=before,
        after=obj,
        **request_context(request),
    )
    db.commit()
    db.refresh(obj)
    return obj


@router.post("/notifications/test", response_model=NotificationTestResult)
def test_notification_channel(
    payload: NotificationTestRequest,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.USER_MANAGE)),
) -> NotificationTestResult:
    """Synchronously send a test message to one channel. Requires USER_MANAGE.

    Email is sent to the current user's address; Teams/Slack go to the configured
    webhook. Always returns 200 — a send failure is reported as ``ok=false`` with
    a human-readable ``detail`` rather than raising.
    """
    cfg = delivery.resolve_channels(db)
    title = "Sentinel QMS test notification"
    body = (
        f"This is a test {payload.channel} notification triggered by "
        f"{actor.email} from Sentinel QMS settings."
    )

    if payload.channel == "email":
        if not cfg.email_enabled:
            return NotificationTestResult(
                ok=False, detail="Email delivery is disabled in settings"
            )
        ok, detail = delivery.send_email(cfg, actor.email, title, body, None)
    elif payload.channel == "teams":
        ok, detail = delivery.send_teams(cfg, title, body, None)
    else:  # slack
        ok, detail = delivery.send_slack(cfg, title, body, None)

    return NotificationTestResult(ok=ok, detail=detail)


@router.post("/sla/run", response_model=SlaSweepResult)
def run_sla_sweep_now(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.USER_MANAGE)),
) -> SlaSweepResult:
    """Run the SLA escalation sweep immediately. Requires USER_MANAGE.

    Idempotent — only records that have crossed a new threshold since the last
    run are escalated.
    """
    summary = sla.run_sla_sweep(db)
    return SlaSweepResult(**summary)


@router.post("/reports/send-digest", response_model=DigestSendResult)
def send_report_digest_now(
    payload: DigestSendRequest,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.USER_MANAGE)),
) -> DigestSendResult:
    """Send the report digest now to the configured (or supplied) recipients.

    Requires USER_MANAGE. Bypasses the schedule cadence; always returns 200 with
    ``ok`` reflecting whether at least one email was sent.
    """
    result = report_digest.send_digest_now(db, recipients=payload.recipients)
    return DigestSendResult(
        ok=bool(result.get("ok")),
        sent=int(result.get("sent", 0)),
        detail=str(result.get("detail", "")),
    )
