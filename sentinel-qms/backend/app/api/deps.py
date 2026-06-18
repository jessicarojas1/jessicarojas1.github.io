"""Shared FastAPI dependencies: current user, DB session, pagination."""

from __future__ import annotations

from collections.abc import Callable
from dataclasses import dataclass

from fastapi import Depends, Query, Request
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.exceptions import AuthenticationError, PermissionDeniedError
from app.core.permissions import effective_levels, level_at_least
from app.core.security import ACCESS_TOKEN_TYPE, decode_token, oauth2_scheme
from app.models.user import User
from app.schemas.auth import CurrentUser

# HTTP methods that mutate state — a Personal Access Token must hold the
# ``write`` scope to use any of these.
_UNSAFE_METHODS = frozenset({"POST", "PUT", "PATCH", "DELETE"})


def _principal_from_user(user: User) -> CurrentUser:
    return CurrentUser(
        id=user.id,
        email=user.email,
        full_name=user.full_name,
        role_names=user.role_names,
        is_active=user.is_active,
    )


def resolve_current_user(request: Request, db: Session) -> CurrentUser:
    """Resolve the principal from the Bearer credential; used by rbac + deps.

    Accepts either an interactive JWT access token or a Personal Access Token
    (``sntl_...``). A PAT acts as its owning user — every normal RBAC check still
    applies — but additionally must carry the ``write`` scope to perform any
    state-changing (non-safe) request. Kept as a plain function (not a
    dependency) so ``app.core.rbac`` can call it without an import cycle.
    """
    auth = request.headers.get("Authorization", "")
    if not auth.lower().startswith("bearer "):
        raise AuthenticationError("Missing bearer token.")
    token = auth[7:].strip()

    # Personal Access Token path (distinguished by its scheme prefix).
    from app.services import api_tokens as token_svc

    if token_svc.looks_like_api_token(token):
        result = token_svc.authenticate_api_token(db, token)
        if result is None:
            raise AuthenticationError("Invalid or expired API token.")
        user, scopes = result
        if request.method.upper() in _UNSAFE_METHODS and "write" not in scopes:
            raise PermissionDeniedError("This API token is read-only.")
        principal = _principal_from_user(user)
        request.state.user = principal
        request.state.auth_method = "api_token"
        return principal

    # Interactive JWT path.
    payload = decode_token(token, expected_type=ACCESS_TOKEN_TYPE)
    try:
        user_id = int(payload["sub"])
    except (KeyError, ValueError) as exc:
        raise AuthenticationError("Malformed token subject.") from exc

    user = db.get(User, user_id)
    if user is None or not user.is_active:
        raise AuthenticationError("User not found or inactive.")

    principal = _principal_from_user(user)
    # Stash on request.state so middleware/audit can read it.
    request.state.user = principal
    request.state.auth_method = "jwt"
    return principal


def require_interactive_user(
    request: Request,
    db: Session = Depends(get_db),
    _token: str | None = Depends(oauth2_scheme),
) -> CurrentUser:
    """Like :func:`get_current_user` but rejects Personal Access Tokens.

    Guards self-management surfaces (e.g. minting/revoking API tokens) so a
    token can never escalate by issuing further tokens for its owner.
    """
    user = resolve_current_user(request, db)
    if getattr(request.state, "auth_method", None) == "api_token":
        raise PermissionDeniedError(
            "This action requires an interactive session, not an API token."
        )
    return user


def get_current_user(
    request: Request,
    db: Session = Depends(get_db),
    _token: str | None = Depends(oauth2_scheme),
) -> CurrentUser:
    """FastAPI dependency yielding the authenticated principal."""
    return resolve_current_user(request, db)


def require_page(page_key: str, level: str = "view") -> Callable:
    """Dependency factory enforcing a minimum effective level for ``page_key``.

    The user's effective level is resolved per :mod:`app.core.permissions`
    (DB rows override; static fallback fills gaps). Raises 403 when the level is
    below ``level``. Returns the :class:`CurrentUser`.
    """

    def _checker(
        request: Request,
        db: Session = Depends(get_db),
        _token: str | None = Depends(oauth2_scheme),
    ) -> CurrentUser:
        user = resolve_current_user(request, db)
        levels = effective_levels(db, user)
        actual = levels.get(page_key, "none")
        if not level_at_least(actual, level):
            raise PermissionDeniedError("Insufficient permissions for this operation.")
        return user

    return _checker


def require_perm(permission: str) -> Callable:
    """Dependency factory enforcing a granular ``"<module>.<action>"`` permission.

    Allows the request when the user effectively holds ``permission`` (role
    defaults UNION explicit grants, see :mod:`app.core.iam`) OR the user is an
    admin. This is an additive granular check layered over the page-level
    :func:`require_page` baseline; because admins always pass and role defaults
    grant the right permissions to the right roles, normal behavior is unchanged.
    """

    def _checker(
        request: Request,
        db: Session = Depends(get_db),
        _token: str | None = Depends(oauth2_scheme),
    ) -> CurrentUser:
        from app.core.iam import has_permission
        from app.core.rbac import Role

        user = resolve_current_user(request, db)
        if Role.ADMIN.value in user.role_names:
            return user
        if not has_permission(db, user, permission):
            raise PermissionDeniedError("Insufficient permissions for this operation.")
        return user

    return _checker


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
