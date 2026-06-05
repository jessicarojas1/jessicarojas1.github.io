"""Training endpoints: personnel + courses + assignments + competency matrix."""
from __future__ import annotations

from datetime import date, timedelta

from fastapi import APIRouter, Depends, Query, Request, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.api.deps import Pagination, pagination_params
from app.core import audit
from app.core.database import get_db
from app.core.exceptions import ConflictError, NotFoundError
from app.core.rbac import Permission, require_permission
from app.models.training import (
    CompetencyMatrixEntry,
    Personnel,
    TrainingCourse,
    TrainingRecord,
    TrainingStatus,
)
from app.schemas.auth import CurrentUser
from app.schemas.common import Page
from app.schemas.training import (
    CompetencyCreate,
    CompetencyRead,
    CompetencyUpdate,
    CourseCreate,
    CourseRead,
    CourseUpdate,
    PersonnelCreate,
    PersonnelRead,
    PersonnelUpdate,
    TrainingAssign,
    TrainingRecordRead,
    TrainingRecordUpdate,
)
from app.services.crud import base_select, get_or_404, page_meta, paginate, request_context

router = APIRouter(prefix="/training", tags=["training"])


# ── Personnel ───────────────────────────────────────────────────────────────


@router.get("/personnel", response_model=Page[PersonnelRead])
def list_personnel(
    db: Session = Depends(get_db),
    pagination: Pagination = Depends(pagination_params),
    search: str | None = Query(None),
    _: CurrentUser = Depends(require_permission(Permission.TRAINING_READ)),
) -> Page[PersonnelRead]:
    stmt = base_select(Personnel).order_by(Personnel.id.desc())
    if search:
        like = f"%{search}%"
        stmt = stmt.where(Personnel.full_name.ilike(like) | Personnel.employee_id.ilike(like))
    items, total = paginate(db, stmt, Personnel, pagination)
    return Page[PersonnelRead](items=items, **page_meta(total, pagination))


@router.post("/personnel", response_model=PersonnelRead, status_code=status.HTTP_201_CREATED)
def create_personnel(
    body: PersonnelCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.TRAINING_WRITE)),
) -> Personnel:
    if db.execute(
        select(Personnel).where(Personnel.employee_id == body.employee_id)
    ).scalar_one_or_none():
        raise ConflictError(f"Personnel with employee_id {body.employee_id} already exists.")
    person = Personnel(**body.model_dump(), created_by=actor.id, updated_by=actor.id)
    db.add(person)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create",
        entity_type="personnel",
        entity_id=person.id,
        after=person,
        **request_context(request),
    )
    db.commit()
    db.refresh(person)
    return person


@router.patch("/personnel/{person_id}", response_model=PersonnelRead)
def update_personnel(
    person_id: int,
    body: PersonnelUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.TRAINING_WRITE)),
) -> Personnel:
    person = get_or_404(db, Personnel, person_id, name="Personnel")
    before = audit.snapshot(person)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(person, key, value)
    person.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type="personnel",
        entity_id=person.id,
        before=before,
        after=person,
        **request_context(request),
    )
    db.commit()
    db.refresh(person)
    return person


# ── Courses ─────────────────────────────────────────────────────────────────


@router.get("/courses", response_model=list[CourseRead])
def list_courses(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.TRAINING_READ)),
) -> list[TrainingCourse]:
    return db.execute(select(TrainingCourse).order_by(TrainingCourse.course_code)).scalars().all()


@router.post("/courses", response_model=CourseRead, status_code=status.HTTP_201_CREATED)
def create_course(
    body: CourseCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.TRAINING_WRITE)),
) -> TrainingCourse:
    if db.execute(
        select(TrainingCourse).where(TrainingCourse.course_code == body.course_code)
    ).scalar_one_or_none():
        raise ConflictError(f"Course {body.course_code} already exists.")
    course = TrainingCourse(**body.model_dump(), created_by=actor.id, updated_by=actor.id)
    db.add(course)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create",
        entity_type="training_course",
        entity_id=course.id,
        after=course,
        **request_context(request),
    )
    db.commit()
    db.refresh(course)
    return course


@router.patch("/courses/{course_id}", response_model=CourseRead)
def update_course(
    course_id: int,
    body: CourseUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.TRAINING_WRITE)),
) -> TrainingCourse:
    course = get_or_404(db, TrainingCourse, course_id, name="Course")
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(course, key, value)
    course.updated_by = actor.id
    db.commit()
    db.refresh(course)
    return course


# ── Assignments / records ───────────────────────────────────────────────────


@router.post("/assign", response_model=TrainingRecordRead, status_code=status.HTTP_201_CREATED)
def assign_training(
    body: TrainingAssign,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.TRAINING_WRITE)),
) -> TrainingRecord:
    person = get_or_404(db, Personnel, body.personnel_id, name="Personnel")
    course = get_or_404(db, TrainingCourse, body.course_id, name="Course")
    record = TrainingRecord(
        personnel_id=person.id,
        course_id=course.id,
        status=TrainingStatus.ASSIGNED,
        assigned_date=body.assigned_date or date.today(),
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(record)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="assign_training",
        entity_type="training_record",
        entity_id=record.id,
        after={"personnel_id": person.id, "course_id": course.id},
        **request_context(request),
    )
    db.commit()
    db.refresh(record)
    return record


@router.patch("/records/{record_id}", response_model=TrainingRecordRead)
def update_record(
    record_id: int,
    body: TrainingRecordUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.TRAINING_WRITE)),
) -> TrainingRecord:
    record = db.get(TrainingRecord, record_id)
    if record is None:
        raise NotFoundError(f"Training record {record_id} not found.")
    before = audit.snapshot(record)
    data = body.model_dump(exclude_unset=True)
    for key, value in data.items():
        setattr(record, key, value)

    # On completion, compute expiry from course validity window.
    if record.status == TrainingStatus.COMPLETED and record.completion_date:
        course = db.get(TrainingCourse, record.course_id)
        if course and course.validity_months:
            record.expiry_date = record.completion_date + timedelta(
                days=30 * course.validity_months
            )
    record.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update_training_record",
        entity_type="training_record",
        entity_id=record.id,
        before=before,
        after=record,
        **request_context(request),
    )
    db.commit()
    db.refresh(record)
    return record


@router.get("/personnel/{person_id}/records", response_model=list[TrainingRecordRead])
def personnel_records(
    person_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.TRAINING_READ)),
) -> list[TrainingRecord]:
    get_or_404(db, Personnel, person_id, name="Personnel")
    return (
        db.execute(
            select(TrainingRecord)
            .where(TrainingRecord.personnel_id == person_id)
            .order_by(TrainingRecord.id.desc())
        )
        .scalars()
        .all()
    )


# ── Competency matrix ───────────────────────────────────────────────────────


@router.post("/competency", response_model=CompetencyRead, status_code=status.HTTP_201_CREATED)
def add_competency(
    body: CompetencyCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.TRAINING_WRITE)),
) -> CompetencyMatrixEntry:
    get_or_404(db, Personnel, body.personnel_id, name="Personnel")
    entry = CompetencyMatrixEntry(**body.model_dump(), created_by=actor.id, updated_by=actor.id)
    db.add(entry)
    db.commit()
    db.refresh(entry)
    return entry


@router.patch("/competency/{entry_id}", response_model=CompetencyRead)
def update_competency(
    entry_id: int,
    body: CompetencyUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_permission(Permission.TRAINING_WRITE)),
) -> CompetencyMatrixEntry:
    entry = get_or_404(db, CompetencyMatrixEntry, entry_id, name="Competency entry")
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(entry, key, value)
    entry.updated_by = actor.id
    db.commit()
    db.refresh(entry)
    return entry


@router.get("/personnel/{person_id}/competency", response_model=list[CompetencyRead])
def personnel_competency(
    person_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.TRAINING_READ)),
) -> list[CompetencyMatrixEntry]:
    get_or_404(db, Personnel, person_id, name="Personnel")
    return (
        db.execute(
            select(CompetencyMatrixEntry).where(
                CompetencyMatrixEntry.personnel_id == person_id
            )
        )
        .scalars()
        .all()
    )
