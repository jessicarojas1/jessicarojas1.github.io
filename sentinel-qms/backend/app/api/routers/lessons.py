"""Lessons Learned endpoints (organizational learning / knowledge retention).

Reads require lesson:read, writes lesson:write. Publishing stamps published_at;
deletes are soft. Every mutation is written to the immutable audit log.
"""

from __future__ import annotations

from datetime import UTC, datetime

from fastapi import APIRouter, Depends, Query, Request, status
from sqlalchemy import or_, select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.database import get_db
from app.core.exceptions import NotFoundError
from app.core.rbac import Permission, require_permission
from app.models.lesson import LessonCategory, LessonLearned, LessonSource, LessonStatus
from app.schemas.auth import CurrentUser
from app.schemas.lesson import LessonCreate, LessonRead, LessonUpdate
from app.services import numbering
from app.services.crud import request_context

router = APIRouter(prefix="/lessons-learned", tags=["lessons-learned"])

ENTITY = "lesson"
_READ = require_permission(Permission.LESSON_READ)
_WRITE = require_permission(Permission.LESSON_WRITE)


def _get(db: Session, lesson_id: int) -> LessonLearned:
    obj = db.get(LessonLearned, lesson_id)
    if obj is None or obj.is_deleted:
        raise NotFoundError(f"Lesson {lesson_id} not found.")
    return obj


@router.get("", response_model=list[LessonRead])
def list_lessons(
    db: Session = Depends(get_db),
    status_filter: LessonStatus | None = Query(None, alias="status"),
    category_filter: LessonCategory | None = Query(None, alias="category"),
    source_filter: LessonSource | None = Query(None, alias="source"),
    department: str | None = Query(None),
    search: str | None = Query(None),
    _: CurrentUser = Depends(_READ),
) -> list[LessonLearned]:
    stmt = select(LessonLearned).where(LessonLearned.is_deleted.is_(False))
    if status_filter:
        stmt = stmt.where(LessonLearned.status == status_filter)
    if category_filter:
        stmt = stmt.where(LessonLearned.category == category_filter)
    if source_filter:
        stmt = stmt.where(LessonLearned.source == source_filter)
    if department:
        stmt = stmt.where(LessonLearned.department.ilike(department))
    if search:
        like = f"%{search}%"
        stmt = stmt.where(
            or_(
                LessonLearned.title.ilike(like),
                LessonLearned.lesson_number.ilike(like),
                LessonLearned.recommendation.ilike(like),
            )
        )
    stmt = stmt.order_by(LessonLearned.id.desc())
    return list(db.execute(stmt).scalars().all())


@router.post("", response_model=LessonRead, status_code=status.HTTP_201_CREATED)
def create_lesson(
    body: LessonCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> LessonLearned:
    obj = LessonLearned(
        **body.model_dump(),
        lesson_number=numbering.next_number(db, LessonLearned, "lesson_number", "LL"),
        status=LessonStatus.DRAFT,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(obj)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create",
        entity_type=ENTITY,
        entity_id=obj.id,
        after=obj,
        **request_context(request),
    )
    db.commit()
    db.refresh(obj)
    return obj


@router.get("/{lesson_id}", response_model=LessonRead)
def get_lesson(
    lesson_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> LessonLearned:
    return _get(db, lesson_id)


@router.patch("/{lesson_id}", response_model=LessonRead)
def update_lesson(
    lesson_id: int,
    body: LessonUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> LessonLearned:
    obj = _get(db, lesson_id)
    before = audit.snapshot(obj)
    data = body.model_dump(exclude_unset=True)
    for key, value in data.items():
        setattr(obj, key, value)
    # Stamp publication the first time the lesson becomes PUBLISHED.
    if data.get("status") == LessonStatus.PUBLISHED and obj.published_at is None:
        obj.published_at = datetime.now(UTC)
    obj.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type=ENTITY,
        entity_id=obj.id,
        before=before,
        after=obj,
        **request_context(request),
    )
    db.commit()
    db.refresh(obj)
    return obj


@router.delete("/{lesson_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_lesson(
    lesson_id: int,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> None:
    obj = _get(db, lesson_id)
    obj.soft_delete(actor.id)
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="soft_delete",
        entity_type=ENTITY,
        entity_id=obj.id,
        **request_context(request),
    )
    db.commit()
