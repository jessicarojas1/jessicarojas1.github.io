"""Records Retention & Disposition Schedule endpoints.

Reads require retention:read, writes retention:write. Deletes are soft. Every
mutation is written to the immutable audit log.

Honest scope: these endpoints manage the retention *schedule* (a documented
policy per record category), a legal-hold flag, and the *scheduled* disposition
action. They do NOT automatically destroy or archive records — disposition is a
documented, manually-performed step outside this module.
"""

from __future__ import annotations

from fastapi import APIRouter, Depends, Query, Request, status
from sqlalchemy import or_, select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.database import get_db
from app.core.exceptions import NotFoundError
from app.core.rbac import Permission, require_permission
from app.models.retention import RetentionCategory, RetentionPolicy, RetentionStatus
from app.schemas.auth import CurrentUser
from app.schemas.retention import RetentionCreate, RetentionRead, RetentionUpdate
from app.services import numbering
from app.services.crud import request_context

router = APIRouter(prefix="/retention-policies", tags=["retention-policies"])

ENTITY = "retention_policy"
_READ = require_permission(Permission.RETENTION_READ)
_WRITE = require_permission(Permission.RETENTION_WRITE)


def _get(db: Session, policy_id: int) -> RetentionPolicy:
    obj = db.get(RetentionPolicy, policy_id)
    if obj is None or obj.is_deleted:
        raise NotFoundError(f"Retention policy {policy_id} not found.")
    return obj


@router.get("", response_model=list[RetentionRead])
def list_policies(
    db: Session = Depends(get_db),
    status_filter: RetentionStatus | None = Query(None, alias="status"),
    category_filter: RetentionCategory | None = Query(None, alias="category"),
    search: str | None = Query(None),
    _: CurrentUser = Depends(_READ),
) -> list[RetentionPolicy]:
    stmt = select(RetentionPolicy).where(RetentionPolicy.is_deleted.is_(False))
    if status_filter:
        stmt = stmt.where(RetentionPolicy.status == status_filter)
    if category_filter:
        stmt = stmt.where(RetentionPolicy.record_category == category_filter)
    if search:
        like = f"%{search}%"
        stmt = stmt.where(
            or_(
                RetentionPolicy.title.ilike(like),
                RetentionPolicy.policy_number.ilike(like),
                RetentionPolicy.authority_reference.ilike(like),
            )
        )
    stmt = stmt.order_by(RetentionPolicy.id.desc())
    return list(db.execute(stmt).scalars().all())


@router.post("", response_model=RetentionRead, status_code=status.HTTP_201_CREATED)
def create_policy(
    body: RetentionCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> RetentionPolicy:
    obj = RetentionPolicy(
        **body.model_dump(),
        policy_number=numbering.next_number(db, RetentionPolicy, "policy_number", "RET"),
        status=RetentionStatus.DRAFT,
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


@router.get("/{policy_id}", response_model=RetentionRead)
def get_policy(
    policy_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> RetentionPolicy:
    return _get(db, policy_id)


@router.patch("/{policy_id}", response_model=RetentionRead)
def update_policy(
    policy_id: int,
    body: RetentionUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> RetentionPolicy:
    obj = _get(db, policy_id)
    before = audit.snapshot(obj)
    data = body.model_dump(exclude_unset=True)
    for key, value in data.items():
        setattr(obj, key, value)
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


@router.delete("/{policy_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_policy(
    policy_id: int,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> None:
    obj = _get(db, policy_id)
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
