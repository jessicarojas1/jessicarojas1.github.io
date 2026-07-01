"""Training and competency schemas."""

from __future__ import annotations

from datetime import date, datetime

from pydantic import BaseModel, Field

from app.models.training import CompetencyLevel, TrainingStatus
from app.schemas.common import ORMModel


class PersonnelBase(BaseModel):
    full_name: str = Field(..., min_length=1, max_length=255)
    job_title: str | None = Field(default=None, max_length=255)
    department: str | None = Field(default=None, max_length=128)
    hire_date: date | None = None
    user_id: int | None = None
    is_active: bool = True


class PersonnelCreate(PersonnelBase):
    employee_id: str = Field(..., min_length=1, max_length=64)


class PersonnelUpdate(BaseModel):
    full_name: str | None = Field(default=None, max_length=255)
    job_title: str | None = Field(default=None, max_length=255)
    department: str | None = Field(default=None, max_length=128)
    hire_date: date | None = None
    is_active: bool | None = None


class PersonnelRead(ORMModel):
    id: int
    employee_id: str
    full_name: str
    job_title: str | None
    department: str | None
    hire_date: date | None
    user_id: int | None
    is_active: bool
    created_at: datetime | None = None


class CourseCreate(BaseModel):
    course_code: str = Field(..., min_length=1, max_length=32)
    title: str = Field(..., min_length=1, max_length=255)
    description: str | None = None
    category: str | None = Field(default=None, max_length=128)
    validity_months: int | None = Field(default=None, ge=1)
    is_mandatory: bool = False


class CourseUpdate(BaseModel):
    title: str | None = Field(default=None, max_length=255)
    description: str | None = None
    category: str | None = Field(default=None, max_length=128)
    validity_months: int | None = Field(default=None, ge=1)
    is_mandatory: bool | None = None


class CourseRead(ORMModel):
    id: int
    course_code: str
    title: str
    description: str | None
    category: str | None
    validity_months: int | None
    is_mandatory: bool
    created_at: datetime | None = None


class TrainingAssign(BaseModel):
    personnel_id: int
    course_id: int
    assigned_date: date | None = None


class TrainingRecordUpdate(BaseModel):
    status: TrainingStatus | None = None
    completion_date: date | None = None
    score: str | None = Field(default=None, max_length=32)
    trainer: str | None = Field(default=None, max_length=255)


class TrainingRecordRead(ORMModel):
    id: int
    personnel_id: int
    course_id: int
    status: TrainingStatus
    assigned_date: date | None
    completion_date: date | None
    expiry_date: date | None
    score: str | None
    trainer: str | None
    created_at: datetime | None = None


class TrainingRecordListItem(BaseModel):
    """Flattened training record for the list view (joined personnel/course)."""

    id: int
    employee_id: str
    employee_name: str
    department: str | None = None
    course: str
    course_code: str | None
    status: TrainingStatus
    assigned_at: date | None
    completed_at: date | None
    due_date: date | None
    score: str | None


class CompetencyCell(BaseModel):
    competency: str
    level: int


class CompetencyRow(BaseModel):
    employee_id: str
    employee_name: str
    department: str | None = None
    cells: list[CompetencyCell]


class CompetencyMatrix(BaseModel):
    competencies: list[str]
    rows: list[CompetencyRow]


class CompetencyCreate(BaseModel):
    personnel_id: int
    skill: str = Field(..., min_length=1, max_length=255)
    required_level: CompetencyLevel = CompetencyLevel.AWARENESS
    current_level: CompetencyLevel = CompetencyLevel.NONE
    assessed_date: date | None = None


class CompetencyUpdate(BaseModel):
    required_level: CompetencyLevel | None = None
    current_level: CompetencyLevel | None = None
    assessed_date: date | None = None


class CompetencyRead(ORMModel):
    id: int
    personnel_id: int
    skill: str
    required_level: CompetencyLevel
    current_level: CompetencyLevel
    assessed_date: date | None
    created_at: datetime | None = None
