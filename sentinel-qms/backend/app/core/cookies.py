"""Refresh-token cookie helpers.

The refresh token is delivered as an HttpOnly cookie scoped to the auth
endpoints so it is never readable by JavaScript (mitigating XSS token theft) and
is only ever sent to ``/api/v1/auth`` (rotation + logout). The deployment is
single-origin (the SPA is served from the API; dev uses the Vite proxy), so
``SameSite=Strict`` is sufficient CSRF protection for the cookie itself, and the
in-memory access-token bearer remains the CSRF defense for every other endpoint.
"""

from __future__ import annotations

from fastapi import Response

from app.core.config import settings

# Cookie name for the rotating refresh token.
REFRESH_COOKIE_NAME = "sentinel_refresh"


def _cookie_path() -> str:
    """Scope the cookie to the auth endpoints only (rotation + logout)."""
    return f"{settings.API_V1_PREFIX}/auth"


def set_refresh_cookie(response: Response, token: str) -> None:
    """Attach the rotating refresh token as an HttpOnly cookie on ``response``."""
    response.set_cookie(
        key=REFRESH_COOKIE_NAME,
        value=token,
        max_age=settings.REFRESH_TOKEN_EXPIRE_DAYS * 24 * 60 * 60,
        httponly=True,
        secure=settings.is_production,
        samesite="strict",
        path=_cookie_path(),
    )


def clear_refresh_cookie(response: Response) -> None:
    """Delete the refresh cookie (must match the attributes used when setting)."""
    response.delete_cookie(
        key=REFRESH_COOKIE_NAME,
        path=_cookie_path(),
        httponly=True,
        secure=settings.is_production,
        samesite="strict",
    )
