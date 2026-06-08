"""Granular (module x action) IAM administration endpoints.

This is the ADDITIVE granular permission layer (see :mod:`app.core.iam`) that
layers on top of the page-level permission model, which keeps working unchanged.
Granular permissions are strings ``"<module>.<action>"``; effective permissions
for a user = role defaults UNION the user's explicit grants.

Endpoints
---------
* ``GET  /iam/catalog``        — the module/action catalog (any authenticated user).
* ``GET  /iam/me``             — the caller's effective granular permissions.
* ``GET  /iam/users``          — per-user breakdown (require_page permissions/view).
* ``PUT  /iam/users/{id}``     — replace a user's EXPLICIT grants (permissions/edit).
"""

from __future__ import annotations

from fastapi import APIRouter, Depends, Request
from pydantic import BaseModel
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.api.deps import get_current_user, require_page
from app.core import audit
from app.core.database import get_db
from app.core.exceptions import NotFoundError, ValidationAppError
from app.core.iam import (
    MODULES,
    PERMISSION_SET,
    ModuleDef,
    effective_permissions,
    role_default_permissions,
    user_grant_rows,
)
from app.models.iam import UserPermissionGrant
from app.models.user import User as UserModel
from app.schemas.auth import CurrentUser
from app.services.crud import request_context

router = APIRouter(tags=["iam"])


class CatalogResponse(BaseModel):
    modules: list[ModuleDef]


class MyPermissionsResponse(BaseModel):
    permissions: list[str]


class IamUserRow(BaseModel):
    """A user's granular-permission breakdown for the admin IAM editor."""

    id: int
    full_name: str
    email: str
    roles: list[str]
    role_default: list[str]
    explicit: list[str]
    denied: list[str]
    effective: list[str]


class UserGrantsUpdate(BaseModel):
    """Body for PUT /iam/users/{id}: the FULL set of explicit grants + denies
    (replace). ``denied`` removes a permission the user's role would grant."""

    granted: list[str]
    denied: list[str] = []


def _user_row(db: Session, user: UserModel) -> IamUserRow:
    role_names = list(user.role_names)
    role_default = role_default_permissions(role_names)
    explicit, denied = user_grant_rows(db, user.id)
    effective = (role_default | explicit) - denied
    return IamUserRow(
        id=user.id,
        full_name=user.full_name,
        email=user.email,
        roles=role_names,
        role_default=sorted(role_default),
        explicit=sorted(explicit),
        denied=sorted(denied),
        effective=sorted(effective),
    )


@router.get("/iam/catalog", response_model=CatalogResponse)
def get_catalog(
    _: CurrentUser = Depends(get_current_user),
) -> CatalogResponse:
    return CatalogResponse(modules=MODULES)


@router.get("/iam/me", response_model=MyPermissionsResponse)
def my_permissions(
    request: Request,
    db: Session = Depends(get_db),
    user: CurrentUser = Depends(get_current_user),
) -> MyPermissionsResponse:
    return MyPermissionsResponse(permissions=sorted(effective_permissions(db, user)))


@router.get("/iam/users", response_model=list[IamUserRow])
def list_iam_users(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_page("permissions", "view")),
) -> list[IamUserRow]:
    users = db.execute(select(UserModel).order_by(UserModel.full_name)).scalars().all()
    return [_user_row(db, u) for u in users]


@router.put("/iam/users/{user_id}", response_model=IamUserRow)
def update_iam_user(
    user_id: int,
    body: UserGrantsUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_page("permissions", "edit")),
) -> IamUserRow:
    user = db.get(UserModel, user_id)
    if user is None:
        raise NotFoundError(f"Unknown user: {user_id}.")

    granted = set(body.granted)
    denied = set(body.denied)
    for perm in granted | denied:
        if perm not in PERMISSION_SET:
            raise ValidationAppError(f"Unknown permission: {perm}.")
    # A permission can't be simultaneously granted and denied.
    conflict = granted & denied
    if conflict:
        raise ValidationAppError(f"Permission both granted and denied: {sorted(conflict)}.")

    # desired maps each explicit permission -> deny flag.
    desired: dict[str, bool] = dict.fromkeys(granted, False)
    desired.update(dict.fromkeys(denied, True))

    before = _user_row(db, user).model_dump()

    current_rows = (
        db.execute(select(UserPermissionGrant).where(UserPermissionGrant.user_id == user_id))
        .scalars()
        .all()
    )
    current = {r.permission: r for r in current_rows}

    # Upsert desired rows (insert new, flip deny on changed).
    for perm, deny in desired.items():
        row = current.get(perm)
        if row is None:
            db.add(UserPermissionGrant(user_id=user_id, permission=perm, deny=deny))
        elif row.deny != deny:
            row.deny = deny
    # Delete rows no longer desired.
    for perm, row in current.items():
        if perm not in desired:
            db.delete(row)

    db.flush()
    db.refresh(user)
    after_row = _user_row(db, user)
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type="user_permission_grant",
        entity_id=user_id,
        before=before,
        after=after_row.model_dump(),
        **request_context(request),
    )
    db.commit()
    return after_row
