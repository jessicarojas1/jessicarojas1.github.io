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
from app.core.exceptions import AuthenticationError, RateLimitError, ValidationAppError
from app.core.security import (
    create_access_token,
    hash_password,
    verify_password,
)
from app.models.user import AuditLog, User
from app.schemas.auth import (
    ChangePasswordRequest,
    CurrentUser,
    LoginRequest,
    MfaCodeRequest,
    MfaEnrollResponse,
    MfaStatus,
    OidcExchangeRequest,
    PasswordResetConfirm,
    PasswordResetRequest,
    Token,
    TokenRefreshRequest,
    UserRead,
)
from app.schemas.common import MessageOut
from app.services import mfa, oidc, password_reset, refresh_tokens
from app.services.crud import request_context

router = APIRouter(prefix="/auth", tags=["auth"])


def _token_response(user: User, refresh_token: str) -> Token:
    access = create_access_token(str(user.id), user.role_names, email=user.email)
    return Token(
        access_token=access,
        refresh_token=refresh_token,
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

    # Second factor: enforced only for accounts that have activated MFA.
    if user.mfa_enabled and user.mfa_secret:
        if not body.otp:
            raise AuthenticationError("MFA code required.")
        if not mfa.verify(user.mfa_secret, body.otp):
            audit.record(
                db,
                actor_id=user.id,
                actor_email=user.email,
                action="login_failed",
                entity_type="auth",
                **request_context(request),
            )
            db.commit()
            raise AuthenticationError("Invalid MFA code.")

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
    refresh_token = refresh_tokens.issue(
        db, user, ip=ctx.get("ip"), user_agent=request.headers.get("User-Agent")
    )
    db.commit()
    return _token_response(user, refresh_token)


@router.post("/refresh", response_model=Token)
def refresh(
    body: TokenRefreshRequest,
    request: Request,
    db: Session = Depends(get_db),
) -> Token:
    """Rotate a refresh token: the presented token is revoked and replaced.

    Reusing a previously rotated token revokes the user's whole active set.
    """
    ctx = request_context(request)
    user, new_refresh = refresh_tokens.rotate(
        db,
        body.refresh_token,
        ip=ctx.get("ip"),
        user_agent=request.headers.get("User-Agent"),
    )
    db.commit()
    return _token_response(user, new_refresh)


@router.post("/oidc/exchange", response_model=Token)
def oidc_exchange(
    request: Request,
    body: OidcExchangeRequest,
    db: Session = Depends(get_db),
) -> Token:
    """Exchange a verified OIDC ID token for an internal access + refresh token.

    The ID token is validated against the IdP's JWKS; the subject is matched to a
    local account (just-in-time provisioned when enabled), with IdP groups mapped
    to local roles. Issues the same internal session a password login would.
    """
    claims = oidc.verify_id_token(body.id_token)
    user = oidc.resolve_or_provision_user(db, claims)
    user.last_login_at = datetime.now(UTC)
    audit.record(
        db,
        actor_id=user.id,
        actor_email=user.email,
        action="login",
        entity_type="auth",
        entity_id=user.id,
        after={"method": "oidc"},
        **request_context(request),
    )
    ctx = request_context(request)
    refresh_token = refresh_tokens.issue(
        db, user, ip=ctx.get("ip"), user_agent=request.headers.get("User-Agent")
    )
    db.commit()
    return _token_response(user, refresh_token)


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
    """Log out: revoke the user's refresh tokens (sign out everywhere) and audit.

    The short-lived access token remains valid until it expires; refresh-token
    revocation prevents any new access tokens from being minted for the session.
    """
    revoked = refresh_tokens.revoke_all(db, current.id)
    audit.record(
        db,
        actor_id=current.id,
        actor_email=current.email,
        action="logout",
        entity_type="auth",
        entity_id=current.id,
        after={"refresh_tokens_revoked": revoked},
        **request_context(request),
    )
    db.commit()
    return MessageOut(detail="Logged out.")


@router.post("/password-reset/request", response_model=MessageOut)
def password_reset_request(
    request: Request,
    body: PasswordResetRequest,
    db: Session = Depends(get_db),
) -> MessageOut:
    """Begin a self-service password reset. Always returns the same response so
    it never reveals whether an account exists."""
    password_reset.request_reset(db, body.email)
    audit.record(
        db,
        actor_id=None,
        actor_email=body.email.lower(),
        action="password_reset_requested",
        entity_type="auth",
        **request_context(request),
    )
    db.commit()
    return MessageOut(detail="If that account exists, a reset link has been sent.")


@router.post("/password-reset/confirm", response_model=MessageOut)
def password_reset_confirm(
    request: Request,
    body: PasswordResetConfirm,
    db: Session = Depends(get_db),
) -> MessageOut:
    """Complete a password reset with a valid token. Revokes existing sessions."""
    user = password_reset.consume(db, body.token, body.new_password)
    audit.record(
        db,
        actor_id=user.id,
        actor_email=user.email,
        action="password_reset",
        entity_type="auth",
        entity_id=user.id,
        **request_context(request),
    )
    db.commit()
    return MessageOut(detail="Your password has been reset. Please sign in.")


@router.post("/change-password", response_model=MessageOut)
def change_password(
    request: Request,
    body: ChangePasswordRequest,
    current: CurrentUser = Depends(get_current_user),
    db: Session = Depends(get_db),
) -> MessageOut:
    """Change the signed-in user's own password (re-auth with current password).

    Revokes other refresh tokens so other sessions are signed out.
    """
    user = db.get(User, current.id)
    if user is None or not user.hashed_password:
        raise AuthenticationError("User not found.")
    if not verify_password(body.current_password, user.hashed_password):
        raise AuthenticationError("Current password is incorrect.")
    if len(body.new_password) < 12:
        raise AuthenticationError("Password must be at least 12 characters.")
    user.hashed_password = hash_password(body.new_password)
    refresh_tokens.revoke_all(db, user.id)
    audit.record(
        db,
        actor_id=user.id,
        actor_email=user.email,
        action="password_change",
        entity_type="auth",
        entity_id=user.id,
        **request_context(request),
    )
    db.commit()
    return MessageOut(detail="Password changed.")


@router.get("/mfa/status", response_model=MfaStatus)
def mfa_status(
    current: CurrentUser = Depends(get_current_user),
    db: Session = Depends(get_db),
) -> MfaStatus:
    user = db.get(User, current.id)
    if user is None:
        raise AuthenticationError("User not found.")
    return MfaStatus(enabled=bool(user.mfa_enabled))


@router.post("/mfa/enroll", response_model=MfaEnrollResponse)
def mfa_enroll(
    current: CurrentUser = Depends(get_current_user),
    db: Session = Depends(get_db),
) -> MfaEnrollResponse:
    """Begin MFA enrollment: generate (but do not yet activate) a TOTP secret.

    Returns the secret and an ``otpauth://`` URI for the authenticator app. The
    secret only becomes enforced after :func:`mfa_activate` confirms a code.
    """
    user = db.get(User, current.id)
    if user is None:
        raise AuthenticationError("User not found.")
    if user.mfa_enabled:
        raise ValidationAppError("MFA is already enabled. Disable it first to re-enroll.")
    secret = mfa.generate_secret()
    user.mfa_secret = secret
    db.commit()
    return MfaEnrollResponse(
        secret=secret,
        otpauth_uri=mfa.provisioning_uri(secret, user.email),
    )


@router.post("/mfa/activate", response_model=MfaStatus)
def mfa_activate(
    request: Request,
    body: MfaCodeRequest,
    current: CurrentUser = Depends(get_current_user),
    db: Session = Depends(get_db),
) -> MfaStatus:
    """Confirm enrollment by verifying a code; enables MFA for future logins."""
    user = db.get(User, current.id)
    if user is None or not user.mfa_secret:
        raise ValidationAppError("Start MFA enrollment first.")
    if not mfa.verify(user.mfa_secret, body.code):
        raise AuthenticationError("Invalid MFA code.")
    user.mfa_enabled = True
    audit.record(
        db,
        actor_id=user.id,
        actor_email=user.email,
        action="mfa_enabled",
        entity_type="auth",
        entity_id=user.id,
        **request_context(request),
    )
    db.commit()
    return MfaStatus(enabled=True)


@router.post("/mfa/disable", response_model=MfaStatus)
def mfa_disable(
    request: Request,
    body: MfaCodeRequest,
    current: CurrentUser = Depends(get_current_user),
    db: Session = Depends(get_db),
) -> MfaStatus:
    """Disable MFA after verifying a current code; clears the stored secret."""
    user = db.get(User, current.id)
    if user is None:
        raise AuthenticationError("User not found.")
    if not user.mfa_enabled or not user.mfa_secret:
        return MfaStatus(enabled=False)
    if not mfa.verify(user.mfa_secret, body.code):
        raise AuthenticationError("Invalid MFA code.")
    user.mfa_enabled = False
    user.mfa_secret = None
    audit.record(
        db,
        actor_id=user.id,
        actor_email=user.email,
        action="mfa_disabled",
        entity_type="auth",
        entity_id=user.id,
        **request_context(request),
    )
    db.commit()
    return MfaStatus(enabled=False)
