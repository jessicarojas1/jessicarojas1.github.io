"""Global search result schemas."""

from __future__ import annotations

from pydantic import BaseModel


class SearchHit(BaseModel):
    type: str
    id: int
    number: str
    title: str
    url: str


class SearchResults(BaseModel):
    results: list[SearchHit]
