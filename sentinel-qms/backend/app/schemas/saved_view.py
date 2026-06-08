"""Saved-view schemas."""

from __future__ import annotations

from pydantic import BaseModel, Field

from app.schemas.common import ORMModel


class SavedViewCreate(BaseModel):
    page_key: str = Field(..., min_length=1, max_length=64)
    name: str = Field(..., min_length=1, max_length=128)
    params: dict = Field(default_factory=dict)


class SavedViewRead(ORMModel):
    id: int
    page_key: str
    name: str
    params: dict
