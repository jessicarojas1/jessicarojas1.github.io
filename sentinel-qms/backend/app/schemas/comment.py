"""Comment / collaboration schemas."""
from __future__ import annotations

from datetime import datetime

from pydantic import BaseModel, Field

from app.schemas.common import ORMModel


class CommentCreate(BaseModel):
    entity_type: str = Field(..., max_length=64)
    entity_id: str = Field(..., max_length=64)
    body: str = Field(..., min_length=1)
    parent_id: int | None = None
    mentions: list[int] = Field(default_factory=list)


class CommentRead(ORMModel):
    id: int
    entity_type: str
    entity_id: str
    author_id: int
    body: str
    parent_id: int | None = None
    created_at: datetime | None = None
