"""Change order (ECN/ECO) schemas."""

from __future__ import annotations

from datetime import date, datetime

from pydantic import BaseModel, Field

from app.models.change import ChangePriority, ChangeStatus, ChangeType
from app.schemas.common import ESignatureIn, ORMModel


class ChangeOrderBase(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    change_type: ChangeType = ChangeType.ECN
    priority: ChangePriority = ChangePriority.MEDIUM
    description: str = Field(..., min_length=1)
    reason: str | None = None
    affected_items: str | None = None
    impact_analysis: str | None = None
    owner_id: int | None = None
    document_id: int | None = None
    target_date: date | None = None


class ChangeOrderCreate(ChangeOrderBase):
    pass


class ChangeOrderUpdate(BaseModel):
    title: str | None = Field(default=None, max_length=512)
    change_type: ChangeType | None = None
    priority: ChangePriority | None = None
    description: str | None = None
    reason: str | None = None
    affected_items: str | None = None
    impact_analysis: str | None = None
    owner_id: int | None = None
    document_id: int | None = None
    target_date: date | None = None


class ChangeStatusChange(BaseModel):
    status: ChangeStatus


class ChangeApproval(BaseModel):
    decision: str = Field(..., pattern="^(approved|rejected)$")
    signature: ESignatureIn


class ChangeOrderRead(ORMModel):
    id: int
    change_number: str
    title: str
    change_type: ChangeType
    status: ChangeStatus
    priority: ChangePriority
    description: str
    reason: str | None
    affected_items: str | None
    impact_analysis: str | None
    requested_by: int | None
    owner_id: int | None
    document_id: int | None
    target_date: date | None
    approved_by: int | None
    approved_at: datetime | None
    implemented_at: datetime | None
    created_at: datetime | None = None
    updated_at: datetime | None = None


class ChangeOrderList(ORMModel):
    id: int
    change_number: str
    title: str
    change_type: ChangeType
    status: ChangeStatus
    priority: ChangePriority
    target_date: date | None
    created_at: datetime | None = None
