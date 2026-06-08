"""Customer & contract register schemas."""

from __future__ import annotations

from datetime import date

from pydantic import BaseModel, Field

from app.models.customer import (
    ContractStatus,
    CustomerStatus,
    FlowDownStatus,
    FlowDownTo,
)
from app.schemas.common import ORMModel


# ---- Customers ----
class CustomerCreate(BaseModel):
    code: str = Field(..., min_length=1, max_length=64)
    name: str = Field(..., min_length=1, max_length=255)
    cage_code: str | None = Field(default=None, max_length=16)
    country: str | None = Field(default=None, max_length=64)
    contact_name: str | None = Field(default=None, max_length=255)
    contact_email: str | None = Field(default=None, max_length=255)
    notes: str | None = None


class CustomerUpdate(BaseModel):
    code: str | None = Field(default=None, min_length=1, max_length=64)
    name: str | None = Field(default=None, min_length=1, max_length=255)
    cage_code: str | None = Field(default=None, max_length=16)
    country: str | None = Field(default=None, max_length=64)
    contact_name: str | None = Field(default=None, max_length=255)
    contact_email: str | None = Field(default=None, max_length=255)
    status: CustomerStatus | None = None
    notes: str | None = None


class CustomerRead(ORMModel):
    id: int
    code: str
    name: str
    cage_code: str | None
    country: str | None
    contact_name: str | None
    contact_email: str | None
    status: CustomerStatus
    notes: str | None
    contract_count: int = 0


# ---- Contract requirements (flow-down) ----
class RequirementCreate(BaseModel):
    description: str = Field(..., min_length=1)
    clause: str | None = Field(default=None, max_length=64)
    flow_down_to: FlowDownTo = FlowDownTo.INTERNAL


class RequirementUpdate(BaseModel):
    description: str | None = Field(default=None, min_length=1)
    clause: str | None = Field(default=None, max_length=64)
    flow_down_to: FlowDownTo | None = None
    status: FlowDownStatus | None = None


class RequirementRead(ORMModel):
    id: int
    contract_id: int
    clause: str | None
    description: str
    flow_down_to: FlowDownTo
    status: FlowDownStatus


# ---- Contracts ----
class ContractCreate(BaseModel):
    contract_number: str = Field(..., min_length=1, max_length=64)
    customer_id: int
    title: str = Field(..., min_length=1, max_length=512)
    dpas_rating: str | None = Field(default=None, max_length=16)
    itar_controlled: bool = False
    dfars_clauses: str | None = None
    value: float | None = Field(default=None, ge=0)
    start_date: date | None = None
    end_date: date | None = None
    notes: str | None = None


class ContractUpdate(BaseModel):
    contract_number: str | None = Field(default=None, min_length=1, max_length=64)
    title: str | None = Field(default=None, min_length=1, max_length=512)
    dpas_rating: str | None = Field(default=None, max_length=16)
    itar_controlled: bool | None = None
    dfars_clauses: str | None = None
    value: float | None = Field(default=None, ge=0)
    start_date: date | None = None
    end_date: date | None = None
    status: ContractStatus | None = None
    notes: str | None = None


class ContractList(ORMModel):
    id: int
    contract_number: str
    customer_id: int
    title: str
    dpas_rating: str | None
    itar_controlled: bool
    status: ContractStatus
    start_date: date | None
    end_date: date | None


class ContractRead(ContractList):
    dfars_clauses: str | None
    value: float | None
    notes: str | None
    requirements: list[RequirementRead]
