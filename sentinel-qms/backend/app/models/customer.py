"""Customer & contract register with requirement flow-down.

Tracks customers, their contracts (with DPAS rating, ITAR control and DFARS
clauses), and the contractual requirements that flow down to internal
processes and suppliers.
"""

from __future__ import annotations

import enum
from datetime import date

from sqlalchemy import Boolean, Date, Enum, ForeignKey, Integer, Numeric, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class CustomerStatus(str, enum.Enum):
    ACTIVE = "active"
    INACTIVE = "inactive"


class ContractStatus(str, enum.Enum):
    ACTIVE = "active"
    ON_HOLD = "on_hold"
    CLOSED = "closed"


class FlowDownTo(str, enum.Enum):
    INTERNAL = "internal"
    SUPPLIER = "supplier"
    BOTH = "both"


class FlowDownStatus(str, enum.Enum):
    OPEN = "open"
    FLOWED_DOWN = "flowed_down"
    VERIFIED = "verified"
    NOT_APPLICABLE = "not_applicable"


class Customer(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "customers"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    code: Mapped[str] = mapped_column(String(64), unique=True, nullable=False, index=True)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    cage_code: Mapped[str | None] = mapped_column(String(16), nullable=True)
    country: Mapped[str | None] = mapped_column(String(64), nullable=True)
    contact_name: Mapped[str | None] = mapped_column(String(255), nullable=True)
    contact_email: Mapped[str | None] = mapped_column(String(255), nullable=True)
    status: Mapped[CustomerStatus] = mapped_column(
        Enum(CustomerStatus, name="customer_status"), default=CustomerStatus.ACTIVE, nullable=False
    )
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    contracts: Mapped[list[Contract]] = relationship(
        "Contract", back_populates="customer", order_by="Contract.id"
    )


class Contract(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "contracts"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    contract_number: Mapped[str] = mapped_column(
        String(64), unique=True, nullable=False, index=True
    )
    customer_id: Mapped[int] = mapped_column(
        ForeignKey("customers.id", ondelete="CASCADE"), nullable=False, index=True
    )
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    dpas_rating: Mapped[str | None] = mapped_column(String(16), nullable=True)
    itar_controlled: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    dfars_clauses: Mapped[str | None] = mapped_column(Text, nullable=True)
    value: Mapped[float | None] = mapped_column(Numeric(16, 2), nullable=True)
    start_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    end_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    status: Mapped[ContractStatus] = mapped_column(
        Enum(ContractStatus, name="contract_status"), default=ContractStatus.ACTIVE, nullable=False
    )
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    customer: Mapped[Customer] = relationship("Customer", back_populates="contracts")
    requirements: Mapped[list[ContractRequirement]] = relationship(
        "ContractRequirement",
        back_populates="contract",
        cascade="all, delete-orphan",
        order_by="ContractRequirement.id",
    )


class ContractRequirement(Base, TimestampMixin):
    __tablename__ = "contract_requirements"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    contract_id: Mapped[int] = mapped_column(
        ForeignKey("contracts.id", ondelete="CASCADE"), nullable=False, index=True
    )
    clause: Mapped[str | None] = mapped_column(String(64), nullable=True)
    description: Mapped[str] = mapped_column(Text, nullable=False)
    flow_down_to: Mapped[FlowDownTo] = mapped_column(
        Enum(FlowDownTo, name="flow_down_to"), default=FlowDownTo.INTERNAL, nullable=False
    )
    status: Mapped[FlowDownStatus] = mapped_column(
        Enum(FlowDownStatus, name="flow_down_status"), default=FlowDownStatus.OPEN, nullable=False
    )

    contract: Mapped[Contract] = relationship("Contract", back_populates="requirements")
