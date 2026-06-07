"""Authentication endpoints: login, refresh, me, logout."""

from __future__ import annotations

from datetime import UTC, datetime

from fastapi import APIRouter, Depends, Request
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.api.deps import get_current_user
from app.core import audit
from app.core.config import settings
from app.core.database import get_db
from app.core.exceptions import AuthenticationError
from app.core.security import (
    REFRESH_TOKEN_TYPE,
    create_access_token,
    create_refresh_token,
    decode_token,
    verify_password,
)
from app.models.user import User
from app.schemas.auth import (
    CurrentUser,
    LoginRequest,
    Token,
    TokenRefreshRequest,
    UserRead,
)
from app.schemas.common import MessageOut
from app.services.crud import request_context

router = APIRouter(prefix="/auth", tags=["auth"])


def _issue_tokens(user: User) -> Token:
    access = create_access_token(str(user.id), user.role_names, email=user.email)
    refresh = create_refresh_token(str(user.id))
    return Token(
        access_token=access,
        refresh_token=refresh,
        expires_in=settings.ACCESS_TOKEN_EXPIRE_MINUTES * 60,
    )


@router.post("/login", response_model=Token)
def login(
    request: Request,
    body: LoginRequest,
    db: Session = Depends(get_db),
) -> Token:
    """Password-grant login. ``username`` is the user's email address."""
    identifier = body.username.lower()
    user = db.execute(select(User).where(User.email == identifier)).scalar_one_or_none()

    if (
        user is None
        or not user.hashed_password
        or not verify_password(body.password, user.hashed_password)
    ):
        # Audit the failed attempt without leaking which factor failed.
        audit.record(
            db,
            actor_id=user.id if user else None,
            actor_email=identifier,
            action="login_failed",
            entity_type="auth",
            **request_context(request),
        )
        db.commit()
        raise AuthenticationError("Incorrect email or password.")

    if not user.is_active:
        raise AuthenticationError("Account is disabled.")

    user.last_login_at = datetime.now(UTC)
    audit.record(
        db,
        actor_id=user.id,
        actor_email=user.email,
        action="login",
        entity_type="auth",
        entity_id=user.id,
        **request_context(request),
    )
    db.commit()
    return _issue_tokens(user)


@router.post("/refresh", response_model=Token)
def refresh(body: TokenRefreshRequest, db: Session = Depends(get_db)) -> Token:
    payload = decode_token(body.refresh_token, expected_type=REFRESH_TOKEN_TYPE)
    try:
        user_id = int(payload["sub"])
    except (KeyError, ValueError) as exc:
        raise AuthenticationError("Malformed refresh token.") from exc

    user = db.get(User, user_id)
    if user is None or not user.is_active:
        raise AuthenticationError("User not found or inactive.")
    return _issue_tokens(user)


@router.get("/me", response_model=UserRead)
def me(
    current: CurrentUser = Depends(get_current_user),
    db: Session = Depends(get_db),
) -> User:
    user = db.get(User, current.id)
    if user is None:
        raise AuthenticationError("User not found.")
    return user


@router.post("/logout", response_model=MessageOut)
def logout(
    request: Request,
    current: CurrentUser = Depends(get_current_user),
    db: Session = Depends(get_db),
) -> MessageOut:
    """Stateless JWT logout: audited; client discards tokens.

    (Token revocation lists can be layered on via the ``jti`` claim.)
    """
    audit.record(
        db,
        actor_id=current.id,
        actor_email=current.email,
        action="logout",
        entity_type="auth",
        entity_id=current.id,
        **request_context(request),
    )
    db.commit()
    return MessageOut(detail="Logged out.")
