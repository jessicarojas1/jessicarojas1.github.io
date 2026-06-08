"""Notification read schema (derives a SPA url from entity_type/entity_id)."""

from __future__ import annotations

from datetime import datetime
from typing import Any

from pydantic import BaseModel, computed_field, model_validator

from app.schemas.common import ORMModel

# entity_type -> SPA path template. Mirrors the mapping used by global search.
_URL_MAP: dict[str, str] = {
    "nonconformance": "/nonconformances/{id}",
    "capa": "/capa/{id}",
    "document": "/documents/{id}",
    "supplier": "/suppliers/{id}",
    "audit": "/audits/{id}",
    "complaint": "/complaints/{id}",
    "risk": "/risks/{id}",
    "change_order": "/changes/{id}",
    "change": "/changes/{id}",
    "inspection": "/inspections/{id}",
    "equipment": "/calibration/{id}",
    "key_characteristic": "/key-characteristics/{id}",
}


def notification_url(entity_type: str | None, entity_id: str | None) -> str | None:
    if not entity_type or entity_id is None:
        return None
    template = _URL_MAP.get(entity_type)
    if template is None:
        return None
    return template.format(id=entity_id)


class NotificationRead(ORMModel):
    id: int
    title: str
    # The ORM column is named ``body``; expose it as ``message`` for the frontend.
    message: str | None = None
    entity_type: str | None = None
    entity_id: str | None = None
    is_read: bool
    created_at: datetime

    @model_validator(mode="before")
    @classmethod
    def _map_body_to_message(cls, data: Any) -> Any:
        # When constructing from an ORM Notification, copy ``body`` -> ``message``
        # without mutating the ORM instance (avoid marking the session dirty).
        if isinstance(data, dict):
            return data
        return {
            "id": getattr(data, "id", None),
            "title": getattr(data, "title", None),
            "message": getattr(data, "body", None),
            "entity_type": getattr(data, "entity_type", None),
            "entity_id": getattr(data, "entity_id", None),
            "is_read": getattr(data, "is_read", None),
            "created_at": getattr(data, "created_at", None),
        }

    @computed_field  # type: ignore[prop-decorator]
    @property
    def url(self) -> str | None:
        return notification_url(self.entity_type, self.entity_id)


class UnreadCount(BaseModel):
    count: int
