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
from app.schemas.settings import OrgSettingsRead, OrgSettingsUpdate
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
