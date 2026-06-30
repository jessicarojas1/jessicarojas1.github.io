"""Document control: Document, DocumentRevision, DocumentApproval."""

from __future__ import annotations

import enum
from datetime import date, datetime

from sqlalchemy import (
    Boolean,
    Date,
    DateTime,
    Enum,
    ForeignKey,
    Integer,
    String,
    Text,
    UniqueConstraint,
    func,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class DocumentStatus(str, enum.Enum):
    CONCEPT = "concept"
    WORK_IN_PROGRESS = "work_in_progress"
    PEER_REVIEW = "peer_review"
    QA_REVIEW = "qa_review"
    APPROVED = "approved"
    OBSOLETE = "obsolete"


class DocumentType(str, enum.Enum):
    WORK_INSTRUCTION = "work_instruction"
    POLICY = "policy"
    PROCESS = "process"
    PROCEDURE = "procedure"
    FORM = "form"
    GUIDE = "guide"


class Department(str, enum.Enum):
    ENS = "ens"
    EXEC = "exec"
    QUAL = "qual"
    ILM = "ilm"
    INS = "ins"  # label "I&S"
    TS = "ts"
    FIN = "fin"
    OPS = "ops"


# Ordered approval stages (excludes the terminal OBSOLETE state). The index of a
# status in this tuple defines the linear "advance" path.
WORKFLOW_STAGES: tuple[DocumentStatus, ...] = (
    DocumentStatus.CONCEPT,
    DocumentStatus.WORK_IN_PROGRESS,
    DocumentStatus.PEER_REVIEW,
    DocumentStatus.QA_REVIEW,
    DocumentStatus.APPROVED,
)


def next_stage(current: DocumentStatus) -> DocumentStatus | None:
    """Return the next stage after ``current`` on the linear path, or None."""
    try:
        idx = WORKFLOW_STAGES.index(current)
    except ValueError:
        return None
    if idx + 1 >= len(WORKFLOW_STAGES):
        return None
    return WORKFLOW_STAGES[idx + 1]


# Shared Enum type instances: reuse one object so the PG type is created once
# even though the enum is referenced by multiple columns/tables.
DOCUMENT_STATUS_ENUM = Enum(DocumentStatus, name="document_status")
DOCUMENT_TYPE_ENUM = Enum(DocumentType, name="document_type")
DEPARTMENT_ENUM = Enum(Department, name="document_department")


class Document(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "documents"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    document_number: Mapped[str] = mapped_column(
        String(64), unique=True, nullable=False, index=True
    )
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    doc_type: Mapped[DocumentType] = mapped_column(DOCUMENT_TYPE_ENUM, nullable=False)
    status: Mapped[DocumentStatus] = mapped_column(
        DOCUMENT_STATUS_ENUM,
        default=DocumentStatus.CONCEPT,
        nullable=False,
    )
    department: Mapped[Department | None] = mapped_column(DEPARTMENT_ENUM, nullable=True)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    owner_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    approved_by: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    current_revision: Mapped[str | None] = mapped_column(String(16), nullable=True)
    version: Mapped[str | None] = mapped_column(String(16), nullable=True)
    effective_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    next_review_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    last_review_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    as9100_clause: Mapped[str | None] = mapped_column(String(32), nullable=True)
    # When true, named users must read-and-acknowledge the current revision
    # (controlled-document awareness / training linkage).
    acknowledgement_required: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)

    # Fixed-template body sections.
    purpose: Mapped[str | None] = mapped_column(Text, nullable=True)
    scope: Mapped[str | None] = mapped_column(Text, nullable=True)
    definitions: Mapped[str | None] = mapped_column(Text, nullable=True)
    responsibilities: Mapped[str | None] = mapped_column(Text, nullable=True)
    detail: Mapped[str | None] = mapped_column(Text, nullable=True)
    revision_history: Mapped[str | None] = mapped_column(Text, nullable=True)
    appendix: Mapped[str | None] = mapped_column(Text, nullable=True)

    revisions: Mapped[list[DocumentRevision]] = relationship(
        "DocumentRevision",
        back_populates="document",
        cascade="all, delete-orphan",
        order_by="DocumentRevision.id",
    )


class DocumentRevision(Base, TimestampMixin):
    __tablename__ = "document_revisions"
    __table_args__ = (UniqueConstraint("document_id", "revision", name="uq_doc_revision"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    document_id: Mapped[int] = mapped_column(
        ForeignKey("documents.id", ondelete="CASCADE"), nullable=False, index=True
    )
    revision: Mapped[str] = mapped_column(String(16), nullable=False)
    change_summary: Mapped[str | None] = mapped_column(Text, nullable=True)
    status: Mapped[DocumentStatus] = mapped_column(
        DOCUMENT_STATUS_ENUM,
        default=DocumentStatus.CONCEPT,
        nullable=False,
    )
    attachment_id: Mapped[int | None] = mapped_column(ForeignKey("attachments.id"), nullable=True)
    effective_date: Mapped[date | None] = mapped_column(Date, nullable=True)

    document: Mapped[Document] = relationship("Document", back_populates="revisions")
    approvals: Mapped[list[DocumentApproval]] = relationship(
        "DocumentApproval", back_populates="revision", cascade="all, delete-orphan"
    )


class DocumentApproval(Base):
    __tablename__ = "document_approvals"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    revision_id: Mapped[int] = mapped_column(
        ForeignKey("document_revisions.id", ondelete="CASCADE"), nullable=False, index=True
    )
    approver_id: Mapped[int] = mapped_column(ForeignKey("users.id"), nullable=False)
    approver_name: Mapped[str] = mapped_column(String(255), nullable=False)
    decision: Mapped[str] = mapped_column(String(16), nullable=False)  # approved | rejected
    comments: Mapped[str | None] = mapped_column(Text, nullable=True)
    signature_id: Mapped[int | None] = mapped_column(
        ForeignKey("electronic_signatures.id"), nullable=True
    )
    decided_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )

    revision: Mapped[DocumentRevision] = relationship(
        "DocumentRevision", back_populates="approvals"
    )


class DocumentAcknowledgement(Base):
    """A user's read-and-acknowledge attestation for a specific document revision.

    One row per (document, revision, user): proof that the named person read and
    acknowledged the controlled revision. Acknowledgements are append-only; a new
    revision requires a fresh acknowledgement.
    """

    __tablename__ = "document_acknowledgements"
    __table_args__ = (
        UniqueConstraint("document_id", "revision", "user_id", name="uq_doc_ack_doc_rev_user"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    document_id: Mapped[int] = mapped_column(
        ForeignKey("documents.id", ondelete="CASCADE"), nullable=False, index=True
    )
    # The document revision label that was acknowledged (e.g. "B"); may be blank
    # for an unrevisioned controlled doc.
    revision: Mapped[str] = mapped_column(String(16), nullable=False, default="")
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=False, index=True
    )
    user_name: Mapped[str] = mapped_column(String(255), nullable=False)
    note: Mapped[str | None] = mapped_column(Text, nullable=True)
    acknowledged_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
