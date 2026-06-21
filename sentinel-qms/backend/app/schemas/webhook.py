"""Webhook + delivery schemas."""

from __future__ import annotations

from datetime import datetime
from typing import Any

from pydantic import BaseModel, Field, HttpUrl

from app.schemas.common import ORMModel


class WebhookCreate(BaseModel):
    name: str = Field(..., min_length=1, max_length=128)
    url: HttpUrl
    # Subscribed event names (e.g. "nonconformance.disposition"); ["*"] = all.
    event_types: list[str] = Field(default_factory=lambda: ["*"])
    description: str | None = Field(default=None, max_length=512)
    active: bool = True


class WebhookUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=128)
    url: HttpUrl | None = None
    event_types: list[str] | None = None
    description: str | None = Field(default=None, max_length=512)
    active: bool | None = None


class WebhookRead(ORMModel):
    id: int
    name: str
    url: str
    event_types: list[str] = []
    description: str | None = None
    active: bool
    created_at: datetime | None = None


class WebhookCreated(WebhookRead):
    """Returned once on creation — carries the plaintext signing secret."""

    secret: str


class WebhookDeliveryRead(ORMModel):
    id: int
    webhook_id: int
    event_type: str
    status: str
    attempts: int
    last_status_code: int | None = None
    last_error: str | None = None
    duration_ms: int | None = None
    next_attempt_at: datetime | None = None
    delivered_at: datetime | None = None
    created_at: datetime | None = None
    payload: dict[str, Any] | None = None
