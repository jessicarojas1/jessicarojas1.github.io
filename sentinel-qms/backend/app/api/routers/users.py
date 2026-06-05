"""User and role administration (Admin only)."""
from __future__ import annotations

from fastapi import APIRouter, Depends, Request, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.api.deps import Pagination, pagination_params
from app.core import audit
from app.core.database import get_db
from app.core.exceptions import ConflictError, NotFoundError
from app.core.rbac import Permission, require_permission
from app.core.security import hash_password
from app.models.user import Role, User
from app.schemas.auth import CurrentUser, RoleRead, UserCreate, UserRead, UserUpdate
from app.schemas.common import MessageOut, Page
from app.services.crud import page_meta, request_context

router = APIRouter(prefix="/users", tags=["users"])

ENTITY = "user"


def _apply_roles(db: Session, user: User, role_names: list[str]) -> None:
    roles = db.execute(select(Role).where(Role.name.in_(role_names))).scalars().all()
    found = {r.name for r in roles}
    missing = set(role_names) - found
    if missing:
        raise NotFoundError(f"Unknown role(s): {', '.join(sorted(missing))}.")
    user.roles = list(roles)


@router.get("/roles", response_model=list[RoleRead])
def list_roles(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.USER_MANAGE)),
) -> list[Role]:
    return db.execute(select(Role).order_by(Role.name)).scalars().all()


@router.get("", response_model=Page[UserRead])
def list_users(
    db: Session = Depends(get_db),
    pagination: Pagination = Depends(pagination_params),
    _: CurrentUser = Depends(require_permission(Permission.USER_MANAGE)),
) -> Page[UserRead]:
    base = select(User).order_by(User.id.desc())
    total = len(db.execute(select(User.id)).scalars().all())
    rows = db.execute(base.offset(pagination.offset).limit(pagination.limit)).scalars().all()
    return Page[UserRead](items=rows, **page_meta(total, pagination))


@router.post("", response_model=UserRead, status_code=status.HTTP_201_CREATED)
def create_user(
    body: UserCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.USER_MANAGE)),
) -> User:
    email = body.email.lower()
    if db.execute(select(User).where(User.email == email)).scalar_one_or_none():
        raise ConflictError(f"A user with email {email} already exists.")

    user = User(
        email=email,
        full_name=body.full_name,
        employee_id=body.employee_id,
        department=body.department,
        is_active=body.is_active,
        hashed_password=hash_password(body.password),
        created_by=actor.id,
        updated_by=actor.id,
    )
    if body.role_names:
        _apply_roles(db, user, body.role_names)
    db.add(user)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create",
        entity_type=ENTITY,
        entity_id=user.id,
        after={"email": user.email, "roles": user.role_names},
        **request_context(request),
    )
    db.commit()
    db.refresh(user)
    return user


@router.get("/{user_id}", response_model=UserRead)
def get_user(
    user_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.USER_MANAGE)),
) -> User:
    user = db.get(User, user_id)
    if user is None:
        raise NotFoundError(f"User {user_id} not found.")
    return user


@router.patch("/{user_id}", response_model=UserRead)
def update_user(
    user_id: int,
    body: UserUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.USER_MANAGE)),
) -> User:
    user = db.get(User, user_id)
    if user is None:
        raise NotFoundError(f"User {user_id} not found.")

    before = {"roles": user.role_names, "is_active": user.is_active}
    data = body.model_dump(exclude_unset=True)
    role_names = data.pop("role_names", None)
    password = data.pop("password", None)
    for key, value in data.items():
        setattr(user, key, value)
    if password:
        user.hashed_password = hash_password(password)
    if role_names is not None:
        _apply_roles(db, user, role_names)
    user.updated_by = actor.id

    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type=ENTITY,
        entity_id=user.id,
        before=before,
        after={"roles": user.role_names, "is_active": user.is_active},
        **request_context(request),
    )
    db.commit()
    db.refresh(user)
    return user


@router.delete("/{user_id}", response_model=MessageOut)
def deactivate_user(
    user_id: int,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.USER_MANAGE)),
) -> MessageOut:
    """Users are deactivated, never hard-deleted (retain audit linkage)."""
    user = db.get(User, user_id)
    if user is None:
        raise NotFoundError(f"User {user_id} not found.")
    if user.id == actor.id:
        raise ConflictError("You cannot deactivate your own account.")
    user.is_active = False
    user.updated_by = actor.id
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="deactivate",
        entity_type=ENTITY,
        entity_id=user.id,
        **request_context(request),
    )
    db.commit()
    return MessageOut(detail=f"User {user_id} deactivated.")
