"""Document control schemas."""

from __future__ import annotations

from datetime import date, datetime

from pydantic import BaseModel, Field

from app.models.document import Department, DocumentStatus, DocumentType
from app.schemas.common import ESignatureIn, ORMModel


class DocumentBase(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    doc_type: DocumentType
    department: Department | None = None
    description: str | None = None
    owner_id: int | None = None
    version: str | None = Field(default=None, max_length=16)
    current_revision: str | None = Field(default=None, max_length=16)
    as9100_clause: str | None = Field(default=None, max_length=32)
    acknowledgement_required: bool | None = None
    next_review_date: date | None = None
    last_review_date: date | None = None
    # Fixed-template body sections.
    purpose: str | None = None
    scope: str | None = None
    definitions: str | None = None
    responsibilities: str | None = None
    detail: str | None = None
    revision_history: str | None = None
    appendix: str | None = None


class DocumentCreate(DocumentBase):
    pass


class DocumentUpdate(BaseModel):
    title: str | None = Field(default=None, max_length=512)
    doc_type: DocumentType | None = None
    department: Department | None = None
    description: str | None = None
    owner_id: int | None = None
    version: str | None = Field(default=None, max_length=16)
    current_revision: str | None = Field(default=None, max_length=16)
    as9100_clause: str | None = Field(default=None, max_length=32)
    acknowledgement_required: bool | None = None
    next_review_date: date | None = None
    last_review_date: date | None = None
    purpose: str | None = None
    scope: str | None = None
    definitions: str | None = None
    responsibilities: str | None = None
    detail: str | None = None
    revision_history: str | None = None
    appendix: str | None = None


# Body fields whose modification on an APPROVED document forces it back to WIP.
CONTENT_FIELDS: frozenset[str] = frozenset(
    {
        "title",
        "doc_type",
        "department",
        "description",
        "version",
        "current_revision",
        "purpose",
        "scope",
        "definitions",
        "responsibilities",
        "detail",
        "revision_history",
        "appendix",
    }
)


class TransitionRequest(BaseModel):
    """Workflow transition request.

    Provide either ``action`` (preferred) or an explicit ``to_status``.
    Actions: ``advance`` (next stage), ``approve``, ``obsolete``, ``revise``.
    """

    action: str | None = Field(default=None, pattern="^(advance|approve|obsolete|revise)$")
    to_status: DocumentStatus | None = None


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
    department: Department | None
    description: str | None
    owner_id: int | None
    approved_by: int | None
    version: str | None
    current_revision: str | None
    effective_date: date | None
    next_review_date: date | None
    last_review_date: date | None
    as9100_clause: str | None
    acknowledgement_required: bool = False
    purpose: str | None
    scope: str | None
    definitions: str | None
    responsibilities: str | None
    detail: str | None
    revision_history: str | None
    appendix: str | None
    created_at: datetime | None = None
    updated_at: datetime | None = None
    revisions: list[RevisionRead] = []


class DocumentList(ORMModel):
    id: int
    document_number: str
    title: str
    doc_type: DocumentType
    status: DocumentStatus
    department: Department | None
    current_revision: str | None
    version: str | None
    effective_date: date | None
    next_review_date: date | None
    created_at: datetime | None = None


class AcknowledgeRequest(BaseModel):
    note: str | None = Field(default=None, max_length=2000)


class AcknowledgementRead(ORMModel):
    id: int
    document_id: int
    revision: str
    user_id: int
    user_name: str
    note: str | None = None
    acknowledged_at: datetime


class PendingAcknowledgement(BaseModel):
    """A controlled document the current user still needs to acknowledge."""

    document_id: int
    document_number: str
    title: str
    current_revision: str | None = None
