"""APQP / PPAP (AS9145) — Advanced Product Quality Planning and the
Production Part Approval Process.

An :class:`ApqpProject` walks the five APQP phases and owns a checklist of
:class:`PpapElement` rows (the standard PPAP submission package).
"""

from __future__ import annotations

import enum
from datetime import date

from sqlalchemy import Date, Enum, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class ApqpPhase(str, enum.Enum):
    PLANNING = "planning"  # Phase 1
    PRODUCT_DESIGN = "product_design"  # Phase 2
    PROCESS_DESIGN = "process_design"  # Phase 3
    VALIDATION = "validation"  # Phase 4
    PRODUCTION = "production"  # Phase 5 — ongoing production & continual improvement


class ApqpStatus(str, enum.Enum):
    ACTIVE = "active"
    ON_HOLD = "on_hold"
    COMPLETE = "complete"
    CANCELLED = "cancelled"


class PpapElementStatus(str, enum.Enum):
    NOT_STARTED = "not_started"
    IN_PROGRESS = "in_progress"
    SUBMITTED = "submitted"
    APPROVED = "approved"
    REJECTED = "rejected"
    NOT_APPLICABLE = "not_applicable"


# The standard AS9145 / AIAG PPAP submission package (key, display name).
PPAP_ELEMENTS: list[tuple[str, str]] = [
    ("design_records", "Design Records"),
    ("change_documents", "Authorized Engineering Change Documents"),
    ("customer_approvals", "Customer Engineering Approvals"),
    ("dfmea", "Design FMEA"),
    ("process_flow", "Process Flow Diagram"),
    ("pfmea", "Process FMEA"),
    ("control_plan", "Control Plan"),
    ("msa", "Measurement System Analysis (MSA)"),
    ("dimensional_results", "Dimensional Results"),
    ("material_tests", "Material & Performance Test Results"),
    ("process_studies", "Initial Process Studies (capability)"),
    ("lab_documentation", "Qualified Laboratory Documentation"),
    ("appearance_approval", "Appearance Approval Report (AAR)"),
    ("sample_parts", "Sample Production Parts"),
    ("master_sample", "Master Sample"),
    ("checking_aids", "Checking Aids"),
    ("customer_requirements", "Customer-Specific Requirements"),
    ("psw", "Part Submission Warrant (PSW)"),
]


class ApqpProject(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "apqp_projects"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    project_number: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    part_number: Mapped[str] = mapped_column(String(128), nullable=False, index=True)
    part_name: Mapped[str] = mapped_column(String(255), nullable=False)
    customer: Mapped[str | None] = mapped_column(String(255), nullable=True)
    supplier_id: Mapped[int | None] = mapped_column(ForeignKey("suppliers.id"), nullable=True)
    current_phase: Mapped[ApqpPhase] = mapped_column(
        Enum(ApqpPhase, name="apqp_phase"), default=ApqpPhase.PLANNING, nullable=False
    )
    status: Mapped[ApqpStatus] = mapped_column(
        Enum(ApqpStatus, name="apqp_status"), default=ApqpStatus.ACTIVE, nullable=False
    )
    # PPAP submission level (1-5 per AS9145/AIAG).
    submission_level: Mapped[int] = mapped_column(Integer, default=3, nullable=False)
    target_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    elements: Mapped[list[PpapElement]] = relationship(
        "PpapElement",
        back_populates="project",
        cascade="all, delete-orphan",
        order_by="PpapElement.id",
    )


class PpapElement(Base, TimestampMixin):
    __tablename__ = "ppap_elements"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    project_id: Mapped[int] = mapped_column(
        ForeignKey("apqp_projects.id", ondelete="CASCADE"), nullable=False, index=True
    )
    element_key: Mapped[str] = mapped_column(String(64), nullable=False)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    status: Mapped[PpapElementStatus] = mapped_column(
        Enum(PpapElementStatus, name="ppap_element_status"),
        default=PpapElementStatus.NOT_STARTED,
        nullable=False,
    )
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    project: Mapped[ApqpProject] = relationship("ApqpProject", back_populates="elements")
