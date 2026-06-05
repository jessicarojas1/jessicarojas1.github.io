"""Document control: Document, DocumentRevision, DocumentApproval."""
from __future__ import annotations

import enum
from datetime import date, datetime

from sqlalchemy import (
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
    DRAFT = "draft"
    IN_REVIEW = "in_review"
    APPROVED = "approved"
    EFFECTIVE = "effective"
    OBSOLETE = "obsolete"


class DocumentType(str, enum.Enum):
    PROCEDURE = "procedure"
    WORK_INSTRUCTION = "work_instruction"
    FORM = "form"
    POLICY = "policy"
    SPECIFICATION = "specification"
    DRAWING = "drawing"
    QUALITY_MANUAL = "quality_manual"


# Shared Enum type instances: reuse one object so the PG type is created once
# even though the enum is referenced by multiple columns/tables.
DOCUMENT_STATUS_ENUM = Enum(DocumentStatus, name="document_status")
DOCUMENT_TYPE_ENUM = Enum(DocumentType, name="document_type")


class Document(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "documents"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    document_number: Mapped[str] = mapped_column(String(64), unique=True, nullable=False, index=True)
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    doc_type: Mapped[DocumentType] = mapped_column(DOCUMENT_TYPE_ENUM, nullable=False)
    status: Mapped[DocumentStatus] = mapped_column(
        DOCUMENT_STATUS_ENUM,
        default=DocumentStatus.DRAFT,
        nullable=False,
    )
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    owner_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    current_revision: Mapped[str | None] = mapped_column(String(16), nullable=True)
    effective_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    next_review_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    as9100_clause: Mapped[str | None] = mapped_column(String(32), nullable=True)

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
        default=DocumentStatus.DRAFT,
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
