"""Authentication endpoints: login, refresh, me, logout."""

from __future__ import annotations

from datetime import UTC, datetime, timedelta

from fastapi import APIRouter, Depends, Request
from sqlalchemy import or_, select
from sqlalchemy.orm import Session

from app.api.deps import get_current_user
from app.core import audit
from app.core.config import settings
from app.core.database import get_db
from app.core.exceptions import AuthenticationError, RateLimitError
from app.core.security import (
    REFRESH_TOKEN_TYPE,
    create_access_token,
    create_refresh_token,
    decode_token,
    verify_password,
)
from app.models.user import AuditLog, User
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


def _too_many_failed_logins(db: Session, identifier: str, ip: str | None) -> bool:
    """True when recent failed logins for this email/IP exceed the threshold.

    Counts the most recent ``LOGIN_MAX_FAILURES`` ``login_failed`` audit rows for
    the email or source IP and checks the oldest of them falls inside the rolling
    window (normalized to UTC so it is correct on both Postgres and SQLite).
    """
    limit = settings.LOGIN_MAX_FAILURES
    if limit <= 0:
        return False
    conds = [AuditLog.actor_email == identifier]
    if ip:
        conds.append(AuditLog.ip_address == ip)
    rows = (
        db.execute(
            select(AuditLog.created_at)
            .where(AuditLog.action == "login_failed", or_(*conds))
            .order_by(AuditLog.created_at.desc())
            .limit(limit)
        )
        .scalars()
        .all()
    )
    if len(rows) < limit:
        return False
    oldest = rows[-1]
    if oldest.tzinfo is None:
        oldest = oldest.replace(tzinfo=UTC)
    window = timedelta(minutes=settings.LOGIN_FAILURE_WINDOW_MINUTES)
    return datetime.now(UTC) - oldest <= window


@router.post("/login", response_model=Token)
def login(
    request: Request,
    body: LoginRequest,
    db: Session = Depends(get_db),
) -> Token:
    """Password-grant login. ``username`` is the user's email address."""
    identifier = body.username.lower()
    ctx = request_context(request)
    if _too_many_failed_logins(db, identifier, ctx.get("ip")):
        raise RateLimitError("Too many failed login attempts. Try again later.")
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
