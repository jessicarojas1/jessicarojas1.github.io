"""Access-token denylist for true logout revocation.

Access tokens are short-lived JWTs (stateless), so logout alone cannot revoke an
already-issued one. To make logout *immediate*, the token's ``jti`` is recorded
here (with the token's own expiry) and checked on every authenticated request.
Rows are only meaningful until ``expires_at``; expired rows can be pruned but a
missing/expired row simply means "not denied", so pruning is purely housekeeping.

This is the DB-backed fallback used when no shared cache (Redis) is configured;
see :mod:`app.services.token_denylist`.
"""

from __future__ import annotations

from datetime import datetime

from sqlalchemy import BigInteger, DateTime, Integer, String
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class AccessTokenDenylist(Base):
    __tablename__ = "access_token_denylist"

    id: Mapped[int] = mapped_column(BigInteger().with_variant(Integer, "sqlite"), primary_key=True)
    jti: Mapped[str] = mapped_column(String(64), unique=True, nullable=False, index=True)
    expires_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False, index=True)
