"""Shared FastAPI dependencies: current user, DB session, pagination."""
from __future__ import annotations

from dataclasses import dataclass

from fastapi import Depends, Query, Request
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.exceptions import AuthenticationError
from app.core.security import ACCESS_TOKEN_TYPE, decode_token, oauth2_scheme
from app.models.user import User
from app.schemas.auth import CurrentUser


def resolve_current_user(request: Request, db: Session) -> CurrentUser:
    """Resolve the principal from the Bearer token; used by rbac + deps.

    Kept as a plain function (not a dependency) so ``app.core.rbac`` can call it
    without creating an import cycle.
    """
    auth = request.headers.get("Authorization", "")
    if not auth.lower().startswith("bearer "):
        raise AuthenticationError("Missing bearer token.")
    token = auth[7:].strip()
    payload = decode_token(token, expected_type=ACCESS_TOKEN_TYPE)
    try:
        user_id = int(payload["sub"])
    except (KeyError, ValueError) as exc:
        raise AuthenticationError("Malformed token subject.") from exc

    user = db.get(User, user_id)
    if user is None or not user.is_active:
        raise AuthenticationError("User not found or inactive.")

    principal = CurrentUser(
        id=user.id,
        email=user.email,
        full_name=user.full_name,
        role_names=user.role_names,
        is_active=user.is_active,
    )
    # Stash on request.state so middleware/audit can read it.
    request.state.user = principal
    return principal


def get_current_user(
    request: Request,
    db: Session = Depends(get_db),
    _token: str | None = Depends(oauth2_scheme),
) -> CurrentUser:
    """FastAPI dependency yielding the authenticated principal."""
    return resolve_current_user(request, db)


def get_db_user(db: Session, user_id: int) -> User | None:
    return db.execute(select(User).where(User.id == user_id)).scalar_one_or_none()


@dataclass
class Pagination:
    page: int
    size: int

    @property
    def offset(self) -> int:
        return (self.page - 1) * self.size

    @property
    def limit(self) -> int:
        return self.size


def pagination_params(
    page: int = Query(1, ge=1, description="1-based page number"),
    size: int = Query(25, ge=1, le=200, description="Items per page (max 200)"),
) -> Pagination:
    return Pagination(page=page, size=size)


@dataclass
class SortParams:
    sort_by: str | None
    order: str  # asc | desc


def sort_params(
    sort_by: str | None = Query(None, description="Column to sort by"),
    order: str = Query("desc", pattern="^(asc|desc)$"),
) -> SortParams:
    return SortParams(sort_by=sort_by, order=order)
