"""Attachment and notification schemas."""

from __future__ import annotations

from datetime import datetime

from pydantic import BaseModel, Field

from app.schemas.common import ORMModel


class AttachmentRead(ORMModel):
    id: int
    entity_type: str
    entity_id: str
    original_filename: str
    content_type: str
    size_bytes: int
    checksum_sha256: str | None
    storage_backend: str
    uploaded_by: int | None
    created_at: datetime | None = None


class AttachmentUploadResult(BaseModel):
    attachment: AttachmentRead
    download_url: str | None = None


class NotificationRead(ORMModel):
    id: int
    title: str
    body: str | None
    category: str
    entity_type: str | None
    entity_id: str | None
    is_read: bool
    created_at: datetime


class AttachmentLinkQuery(BaseModel):
    entity_type: str = Field(..., max_length=64)
    entity_id: str = Field(..., max_length=64)
