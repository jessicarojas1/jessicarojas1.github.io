"""Document control schemas."""
from __future__ import annotations

from datetime import date, datetime

from pydantic import BaseModel, Field

from app.models.document import DocumentStatus, DocumentType
from app.schemas.common import ESignatureIn, ORMModel


class DocumentBase(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    doc_type: DocumentType
    description: str | None = None
    owner_id: int | None = None
    as9100_clause: str | None = Field(default=None, max_length=32)
    next_review_date: date | None = None


class DocumentCreate(DocumentBase):
    pass


class DocumentUpdate(BaseModel):
    title: str | None = Field(default=None, max_length=512)
    doc_type: DocumentType | None = None
    description: str | None = None
    owner_id: int | None = None
    as9100_clause: str | None = Field(default=None, max_length=32)
    next_review_date: date | None = None


class RevisionCreate(BaseModel):
    revision: str = Field(..., min_length=1, max_length=16)
    change_summary: str | None = None
    attachment_id: int | None = None


class RevisionRead(ORMModel):
    id: int
    document_id: int
    revision: str
    change_summary: str | None
    status: DocumentStatus
    attachment_id: int | None
    effective_date: date | None
    created_at: datetime | None = None


class ApprovalDecision(BaseModel):
    decision: str = Field(..., pattern="^(approved|rejected)$")
    comments: str | None = None
    effective_date: date | None = None
    signature: ESignatureIn


class ApprovalRead(ORMModel):
    id: int
    revision_id: int
    approver_id: int
    approver_name: str
    decision: str
    comments: str | None
    signature_id: int | None
    decided_at: datetime


class DocumentRead(ORMModel):
    id: int
    document_number: str
    title: str
    doc_type: DocumentType
    status: DocumentStatus
    description: str | None
    owner_id: int | None
    current_revision: str | None
    effective_date: date | None
    next_review_date: date | None
    as9100_clause: str | None
    created_at: datetime | None = None
    updated_at: datetime | None = None
    revisions: list[RevisionRead] = []


class DocumentList(ORMModel):
    id: int
    document_number: str
    title: str
    doc_type: DocumentType
    status: DocumentStatus
    current_revision: str | None
    effective_date: date | None
    created_at: datetime | None = None
