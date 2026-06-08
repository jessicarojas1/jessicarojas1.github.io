"""Page registry + DB-driven role/page permission matrix administration.

Endpoints
---------
* ``GET  /pages``              — canonical page registry (any authenticated user).
* ``GET  /permissions/me``     — the caller's effective level per page (non-"none").
* ``GET  /permissions/roles``  — the full {role: {page: level}} matrix (admin).
* ``PUT  /permissions/roles``  — upsert the matrix (admin); returns it back.

Levels are the ordered strings ``"none" < "view" < "edit"``. An un-provisioned
(role, page) cell is filled with its static default (never hard "none"), so the
editor always shows the *real* effective matrix.
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
from app.core.pages import LEVELS, PAGE_KEYS, PAGES, PageDef
from app.core.permissions import (
    default_level_for,
    effective_levels,
    role_derived_levels,
    user_explicit_levels,
)
from app.core.rbac import Role as RoleEnum
from app.models.permission import RolePagePermission, UserPagePermission
from app.models.user import Role as RoleModel
from app.models.user import User as UserModel
from app.schemas.auth import CurrentUser
from app.services.crud import request_context

router = APIRouter(tags=["permissions"])

_VALID_LEVELS = set(LEVELS)


class RoleMatrixUpdate(BaseModel):
    """Body for PUT /permissions/roles: {role_name: {page_key: level}}."""

    matrix: dict[str, dict[str, str]]


def _full_matrix(db: Session) -> dict[str, dict[str, str]]:
    """Build {role_name: {page_key: level}} for ALL roles, DB value or default."""
    roles = db.execute(select(RoleModel).order_by(RoleModel.name)).scalars().all()

    # DB overrides keyed by (role_id, page_key).
    db_levels: dict[tuple[int, str], str] = {
        (rp.role_id, rp.page_key): rp.level
        for rp in db.execute(select(RolePagePermission)).scalars().all()
    }

    matrix: dict[str, dict[str, str]] = {}
    for role in roles:
        try:
            role_enum = RoleEnum(role.name)
        except ValueError:
            role_enum = None
        row: dict[str, str] = {}
        for page in PAGES:
            key = page["key"]
            override = db_levels.get((role.id, key))
            if override is not None:
                row[key] = override
            elif role_enum is not None:
                row[key] = default_level_for(role_enum, key)
            else:
                row[key] = "none"
        matrix[role.name] = row
    return matrix


@router.get("/pages", response_model=list[PageDef])
def list_pages(_: CurrentUser = Depends(get_current_user)) -> list[PageDef]:
    return PAGES


@router.get("/permissions/me", response_model=dict[str, str])
def my_permissions(
    request: Request,
    db: Session = Depends(get_db),
    user: CurrentUser = Depends(get_current_user),
) -> dict[str, str]:
    levels = effective_levels(db, user)
    return {key: level for key, level in levels.items() if level != "none"}


@router.get("/permissions/roles", response_model=dict[str, dict[str, str]])
def get_role_matrix(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_page("permissions", "view")),
) -> dict[str, dict[str, str]]:
    return _full_matrix(db)


@router.put("/permissions/roles", response_model=dict[str, dict[str, str]])
def update_role_matrix(
    body: RoleMatrixUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_page("permissions", "edit")),
) -> dict[str, dict[str, str]]:
    before = _full_matrix(db)

    roles = {r.name: r for r in db.execute(select(RoleModel)).scalars().all()}

    for role_name, pages in body.matrix.items():
        role = roles.get(role_name)
        if role is None:
            raise NotFoundError(f"Unknown role: {role_name}.")
        for page_key, level in pages.items():
            if page_key not in PAGE_KEYS:
                raise ValidationAppError(f"Unknown page key: {page_key}.")
            if level not in _VALID_LEVELS:
                raise ValidationAppError(
                    f"Invalid level '{level}' (allowed: {', '.join(sorted(_VALID_LEVELS))})."
                )
            existing = db.execute(
                select(RolePagePermission).where(
                    RolePagePermission.role_id == role.id,
                    RolePagePermission.page_key == page_key,
                )
            ).scalar_one_or_none()
            if existing is None:
                db.add(RolePagePermission(role_id=role.id, page_key=page_key, level=level))
            else:
                existing.level = level

    db.flush()
    after = _full_matrix(db)
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type="role_page_permission",
        entity_id=None,
        before=before,
        after=after,
        **request_context(request),
    )
    db.commit()
    return after


class UserPermissionRow(BaseModel):
    """A user's page-permission breakdown for the admin matrix."""

    id: int
    full_name: str
    email: str
    roles: list[str]
    role_default: dict[str, str]
    explicit: dict[str, str]
    effective: dict[str, str]


class UserOverrideUpdate(BaseModel):
    """Body for PUT /permissions/users/{id}: {page_key: level|"inherit"}."""

    overrides: dict[str, str]


def _user_row(db: Session, user: UserModel) -> UserPermissionRow:
    role_default = role_derived_levels(db, user)
    explicit = user_explicit_levels(db, user.id)
    effective = {**role_default, **explicit}
    return UserPermissionRow(
        id=user.id,
        full_name=user.full_name,
        email=user.email,
        roles=list(user.role_names),
        role_default=role_default,
        explicit=explicit,
        effective=effective,
    )


@router.get("/permissions/users", response_model=list[UserPermissionRow])
def get_user_matrix(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_page("permissions", "view")),
) -> list[UserPermissionRow]:
    users = db.execute(select(UserModel).order_by(UserModel.full_name)).scalars().all()
    return [_user_row(db, u) for u in users]


@router.put("/permissions/users/{user_id}", response_model=UserPermissionRow)
def update_user_overrides(
    user_id: int,
    body: UserOverrideUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_page("permissions", "edit")),
) -> UserPermissionRow:
    user = db.get(UserModel, user_id)
    if user is None:
        raise NotFoundError(f"Unknown user: {user_id}.")

    before = _user_row(db, user).model_dump()

    for page_key, level in body.overrides.items():
        if page_key not in PAGE_KEYS:
            raise ValidationAppError(f"Unknown page key: {page_key}.")
        existing = db.execute(
            select(UserPagePermission).where(
                UserPagePermission.user_id == user_id,
                UserPagePermission.page_key == page_key,
            )
        ).scalar_one_or_none()
        if level == "inherit":
            if existing is not None:
                db.delete(existing)
            continue
        if level not in _VALID_LEVELS:
            raise ValidationAppError(
                f"Invalid level '{level}' (allowed: inherit, {', '.join(sorted(_VALID_LEVELS))})."
            )
        if existing is None:
            db.add(UserPagePermission(user_id=user_id, page_key=page_key, level=level))
        else:
            existing.level = level

    db.flush()
    db.refresh(user)
    after_row = _user_row(db, user)
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type="user_page_permission",
        entity_id=user_id,
        before=before,
        after=after_row.model_dump(),
        **request_context(request),
    )
    db.commit()
    return after_row
