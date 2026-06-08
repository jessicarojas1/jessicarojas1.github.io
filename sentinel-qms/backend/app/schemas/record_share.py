"""Record-share schemas."""

from __future__ import annotations

from datetime import datetime

from pydantic import BaseModel, Field

from app.schemas.common import ORMModel


class ShareCreate(BaseModel):
    entity_type: str = Field(..., min_length=1, max_length=64)
    entity_id: str = Field(..., min_length=1, max_length=64)
    label: str = Field(..., min_length=1, max_length=512)
    shared_with_user_id: int
    note: str | None = None


class ShareRead(ORMModel):
    id: int
    entity_type: str
    entity_id: str
    label: str
    shared_with_user_id: int
    shared_by_user_id: int
    note: str | None
    created_at: datetime | None = None
