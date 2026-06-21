"""Access-token (``jti``) denylist for true logout revocation.

Access tokens are short-lived, stateless JWTs, so signing out cannot by itself
invalidate one that has already been issued. To make logout *immediate*, the
current access token's ``jti`` is added here for the remainder of its lifetime,
and :func:`is_denied` is consulted on every authenticated request.

Backends (chosen at call time from configuration):

* **Redis** — used when ``settings.REDIS_URL`` is set. A single ``SETEX`` key per
  denied ``jti`` with the token's remaining TTL; correct across workers/replicas
  and self-expiring. Mirrors the shared-store pattern already used by the rate
  limiter. Uses the synchronous ``redis`` client because the auth dependency path
  is synchronous; on any connection/import error it falls back to the DB so
  revocation is never silently disabled.
* **Database** — the default fallback (no Redis configured) and the failure
  fallback. A row per denied ``jti`` with its ``expires_at`` in the
  ``access_token_denylist`` table. Correct across workers/replicas. Expired rows
  are pruned opportunistically. This is preferred over an in-process set, which
  would be per-worker and therefore unsound under multiple workers.
"""

from __future__ import annotations

import logging
from datetime import UTC, datetime, timedelta

from sqlalchemy import delete, select
from sqlalchemy.orm import Session

from app.core.config import settings
from app.models.token_denylist import AccessTokenDenylist

logger = logging.getLogger("app.auth")

_REDIS_PREFIX = "denylist:jti:"

# Lazily-built synchronous Redis client (None when unconfigured/unavailable).
_redis_client = None
_redis_ready = False


def _get_redis():
    """Build the synchronous Redis client once. Returns None when REDIS_URL is
    unset or the client library/connection is unavailable (callers fall back to
    the DB-backed denylist)."""
    global _redis_client, _redis_ready
    if _redis_ready:
        return _redis_client
    _redis_ready = True
    if settings.REDIS_URL:
        try:
            import redis  # noqa: PLC0415

            _redis_client = redis.Redis.from_url(settings.REDIS_URL, decode_responses=True)
        except Exception as exc:  # pragma: no cover - import/connection issues
            logger.warning("Token denylist: Redis unavailable (%s); using DB denylist.", exc)
            _redis_client = None
    return _redis_client


def add(db: Session, jti: str, ttl_seconds: int) -> None:
    """Deny ``jti`` for ``ttl_seconds`` (the token's remaining lifetime).

    Negative/zero TTLs are a no-op: the token is already expired and would be
    rejected by signature/expiry validation anyway.
    """
    if not jti or ttl_seconds <= 0:
        return
    client = _get_redis()
    if client is not None:
        try:
            client.setex(f"{_REDIS_PREFIX}{jti}", ttl_seconds, "1")
            return
        except Exception as exc:  # pragma: no cover - runtime redis failure
            logger.warning("Token denylist: Redis error (%s); using DB denylist.", exc)
    expires_at = datetime.now(UTC) + timedelta(seconds=ttl_seconds)
    # Opportunistically prune expired rows so the table stays small.
    db.execute(delete(AccessTokenDenylist).where(AccessTokenDenylist.expires_at <= datetime.now(UTC)))
    existing = db.execute(
        select(AccessTokenDenylist).where(AccessTokenDenylist.jti == jti)
    ).scalar_one_or_none()
    if existing is None:
        db.add(AccessTokenDenylist(jti=jti, expires_at=expires_at))
    else:
        existing.expires_at = expires_at
    db.flush()


def is_denied(db: Session, jti: str | None) -> bool:
    """True when ``jti`` has been revoked (and the revocation has not expired)."""
    if not jti:
        return False
    client = _get_redis()
    if client is not None:
        try:
            return bool(client.exists(f"{_REDIS_PREFIX}{jti}"))
        except Exception as exc:  # pragma: no cover - runtime redis failure
            logger.warning("Token denylist: Redis error (%s); using DB denylist.", exc)
    row = db.execute(
        select(AccessTokenDenylist).where(AccessTokenDenylist.jti == jti)
    ).scalar_one_or_none()
    if row is None:
        return False
    exp = row.expires_at
    if exp.tzinfo is None:
        exp = exp.replace(tzinfo=UTC)
    return exp > datetime.now(UTC)
