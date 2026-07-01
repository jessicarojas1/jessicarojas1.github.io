"""CAPA (8D) schemas."""

from __future__ import annotations

from datetime import date, datetime

from pydantic import BaseModel, Field

from app.models.capa import CapaActionStatus, CapaStatus, CapaType
from app.schemas.common import ESignatureIn, ORMModel


class FiveWhyStep(BaseModel):
    """One step in a structured 5-Why root-cause chain."""

    why: str | None = None
    because: str | None = None


class CapaActionBase(BaseModel):
    description: str = Field(..., min_length=1)
    action_kind: str = Field(default="corrective", max_length=32)
    owner_id: int | None = None
    due_date: date | None = None


class CapaActionCreate(CapaActionBase):
    pass


class CapaActionUpdate(BaseModel):
    description: str | None = None
    action_kind: str | None = Field(default=None, max_length=32)
    owner_id: int | None = None
    status: CapaActionStatus | None = None
    due_date: date | None = None


class CapaActionRead(ORMModel):
    id: int
    capa_id: int
    description: str
    action_kind: str
    owner_id: int | None
    status: CapaActionStatus
    due_date: date | None
    completed_at: datetime | None
    created_at: datetime | None = None


class CapaBase(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    capa_type: CapaType = CapaType.CORRECTIVE
    d2_problem_description: str = Field(..., min_length=1)
    d1_team: str | None = None
    d3_containment: str | None = None
    d4_root_cause: str | None = None
    root_cause_method: str | None = Field(default=None, max_length=64)
    five_whys: list[FiveWhyStep] | None = None
    d5_corrective_action: str | None = None
    d6_implementation: str | None = None
    d7_preventive_action: str | None = None
    d8_closure: str | None = None
    owner_id: int | None = None
    supplier_id: int | None = None
    due_date: date | None = None


class CapaCreate(CapaBase):
    nonconformance_id: int | None = Field(
        default=None, description="Optionally link to an originating NCR"
    )


class CapaUpdate(BaseModel):
    title: str | None = Field(default=None, max_length=512)
    capa_type: CapaType | None = None
    d1_team: str | None = None
    d2_problem_description: str | None = None
    d3_containment: str | None = None
    d4_root_cause: str | None = None
    root_cause_method: str | None = Field(default=None, max_length=64)
    five_whys: list[FiveWhyStep] | None = None
    d5_corrective_action: str | None = None
    d6_implementation: str | None = None
    d7_preventive_action: str | None = None
    d8_closure: str | None = None
    owner_id: int | None = None
    supplier_id: int | None = None
    due_date: date | None = None


class CapaStatusChange(BaseModel):
    status: CapaStatus


class CapaLinkResult(BaseModel):
    capa_id: int
    capa_number: str


class CapaEffectivenessVerify(BaseModel):
    effective: bool
    notes: str | None = None


class CapaClose(BaseModel):
    d8_closure: str = Field(..., min_length=1)
    signature: ESignatureIn


class CapaRead(ORMModel):
    id: int
    capa_number: str
    title: str
    capa_type: CapaType
    status: CapaStatus
    d1_team: str | None
    d2_problem_description: str
    d3_containment: str | None
    d4_root_cause: str | None
    root_cause_method: str | None
    five_whys: list[FiveWhyStep] | None = None
    d5_corrective_action: str | None
    d6_implementation: str | None
    d7_preventive_action: str | None
    d8_closure: str | None
    effectiveness_verified: bool
    effectiveness_notes: str | None
    effectiveness_verified_by: int | None
    effectiveness_verified_at: datetime | None
    owner_id: int | None
    supplier_id: int | None
    due_date: date | None
    closed_at: datetime | None
    closure_signature_id: int | None
    created_at: datetime | None = None
    updated_at: datetime | None = None
    actions: list[CapaActionRead] = []


class CapaList(ORMModel):
    id: int
    capa_number: str
    title: str
    capa_type: CapaType
    status: CapaStatus
    owner_id: int | None
    due_date: date | None
    effectiveness_verified: bool
    created_at: datetime | None = None
