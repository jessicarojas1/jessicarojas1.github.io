"""Shared Pydantic schema primitives: ORM config, pagination, e-signature."""
from __future__ import annotations

from datetime import datetime
from typing import Generic, TypeVar

from pydantic import BaseModel, ConfigDict, Field

T = TypeVar("T")


class ORMModel(BaseModel):
    """Base for read schemas that map from ORM instances."""

    model_config = ConfigDict(from_attributes=True)


class TimestampsRead(ORMModel):
    created_at: datetime | None = None
    updated_at: datetime | None = None
    created_by: int | None = None
    updated_by: int | None = None


class Page(BaseModel, Generic[T]):
    """Standard paginated envelope returned by list endpoints."""

    items: list[T]
    total: int
    page: int
    size: int
    pages: int


class ESignatureIn(BaseModel):
    """21 CFR Part 11 e-signature payload supplied on approvals/dispositions."""

    meaning: str = Field(..., max_length=128, description="e.g. approved, reviewed, dispositioned")
    reason: str | None = Field(default=None, max_length=2000)
    # Re-authentication password (verified server-side; never stored).
    password: str | None = Field(default=None, max_length=256)


class MessageOut(BaseModel):
    detail: str


class ImportRowError(BaseModel):
    """A single row failure during a bulk CSV import."""

    row: int
    message: str


class ImportResult(BaseModel):
    """Outcome of a bulk CSV import: how many rows were created vs. failed."""

    created: int = 0
    failed: int = 0
    errors: list[ImportRowError] = Field(default_factory=list)


class AuditLogRead(ORMModel):
    id: int
    actor_id: int | None
    actor_email: str | None
    action: str
    entity_type: str
    entity_id: str | None
    before: dict | None
    after: dict | None
    ip_address: str | None
    request_id: str | None
    created_at: datetime
