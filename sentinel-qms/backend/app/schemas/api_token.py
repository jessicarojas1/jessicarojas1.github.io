"""Personal Access Token schemas."""

from __future__ import annotations

from datetime import datetime

from pydantic import BaseModel, Field, computed_field

from app.models.api_token import token_is_active
from app.schemas.common import ORMModel


class ApiTokenCreate(BaseModel):
    name: str = Field(..., min_length=1, max_length=128)
    # Coarse scopes; unknown values are dropped and an empty list defaults to read-only.
    scopes: list[str] = Field(default_factory=lambda: ["read"])
    # Optional lifetime in days; omit for a non-expiring token.
    expires_in_days: int | None = Field(default=None, ge=1, le=3650)


class ApiTokenRead(ORMModel):
    id: int
    name: str
    token_prefix: str
    scopes: list[str] = []
    last_used_at: datetime | None = None
    expires_at: datetime | None = None
    revoked_at: datetime | None = None
    created_at: datetime | None = None

    @computed_field  # type: ignore[prop-decorator]
    @property
    def active(self) -> bool:
        return token_is_active(self.revoked_at, self.expires_at)


class ApiTokenCreated(ApiTokenRead):
    """Returned once on creation — carries the plaintext secret."""

    token: str
