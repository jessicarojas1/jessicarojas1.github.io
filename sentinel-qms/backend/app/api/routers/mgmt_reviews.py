"""Management review endpoints: CRUD + inputs + action items."""

from __future__ import annotations

from datetime import UTC, datetime

from fastapi import APIRouter, Depends, Query, Request, status
from sqlalchemy.orm import Session

from app.api.deps import (
    Pagination,
    SortParams,
    pagination_params,
    require_page,
    require_perm,
    sort_params,
)
from app.core import audit
from app.core.database import get_db
from app.core.exceptions import NotFoundError
from app.models.mgmt_review import (
    ActionItem,
    ActionItemStatus,
    ManagementReview,
    ManagementReviewInput,
    ReviewStatus,
)
from app.schemas.auth import CurrentUser
from app.schemas.common import Page
from app.schemas.mgmt_review import (
    ActionItemCreate,
    ActionItemRead,
    ActionItemUpdate,
    ReviewCreate,
    ReviewInputCreate,
    ReviewInputRead,
    ReviewList,
    ReviewRead,
    ReviewUpdate,
)
from app.services import kpi, numbering
from app.services.crud import (
    apply_sort,
    base_select,
    get_or_404,
    page_meta,
    paginate,
    request_context,
)

router = APIRouter(prefix="/management-reviews", tags=["management-reviews"])

ENTITY = "management_review"


@router.get("", response_model=Page[ReviewList])
def list_reviews(
    db: Session = Depends(get_db),
    pagination: Pagination = Depends(pagination_params),
    sort: SortParams = Depends(sort_params),
    status_filter: ReviewStatus | None = Query(None, alias="status"),
    _: CurrentUser = Depends(require_page("mgmt_reviews", "view")),
) -> Page[ReviewList]:
    stmt = base_select(ManagementReview)
    if status_filter:
        stmt = stmt.where(ManagementReview.status == status_filter)
    stmt = apply_sort(stmt, ManagementReview, sort)
    items, total = paginate(db, stmt, ManagementReview, pagination)
    return Page[ReviewList](items=items, **page_meta(total, pagination))


@router.post("", response_model=ReviewRead, status_code=status.HTTP_201_CREATED)
def create_review(
    body: ReviewCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("mgmt_reviews.create")),
) -> ManagementReview:
    review = ManagementReview(
        **body.model_dump(),
        review_number=numbering.next_number(db, ManagementReview, "review_number", "MR"),
        status=ReviewStatus.SCHEDULED,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(review)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create",
        entity_type=ENTITY,
        entity_id=review.id,
        after=review,
        **request_context(request),
    )
    db.commit()
    db.refresh(review)
    return review


@router.get("/{review_id}", response_model=ReviewRead)
def get_review(
    review_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_page("mgmt_reviews", "view")),
) -> ManagementReview:
    return get_or_404(db, ManagementReview, review_id, name="Management review")


@router.patch("/{review_id}", response_model=ReviewRead)
def update_review(
    review_id: int,
    body: ReviewUpdate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("mgmt_reviews.edit")),
) -> ManagementReview:
    review = get_or_404(db, ManagementReview, review_id, name="Management review")
    before = audit.snapshot(review)
    for key, value in body.model_dump(exclude_unset=True).items():
        setattr(review, key, value)
    review.updated_by = actor.id
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="update",
        entity_type=ENTITY,
        entity_id=review.id,
        before=before,
        after=review,
        **request_context(request),
    )
    db.commit()
    db.refresh(review)
    return review


@router.post(
    "/{review_id}/inputs", response_model=ReviewInputRead, status_code=status.HTTP_201_CREATED
)
def add_input(
    review_id: int,
    body: ReviewInputCreate,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_perm("mgmt_reviews.edit")),
) -> ManagementReviewInput:
    get_or_404(db, ManagementReview, review_id, name="Management review")
    item = ManagementReviewInput(review_id=review_id, **body.model_dump())
    db.add(item)
    db.commit()
    db.refresh(item)
    return item


@router.post(
    "/{review_id}/auto-inputs",
    response_model=list[ReviewInputRead],
    status_code=status.HTTP_201_CREATED,
)
def compile_auto_inputs(
    review_id: int,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("mgmt_reviews.edit")),
) -> list[ManagementReviewInput]:
    """Auto-compile the ISO 9001 / AS9100 clause 9.3.2 review inputs from current
    QMS data. Idempotent: replaces previously auto-generated rows (matched by
    their fixed category labels) and leaves any manually-added inputs untouched.
    """
    get_or_404(db, ManagementReview, review_id, name="Management review")

    # Remove prior auto rows so re-running refreshes rather than duplicates.
    db.query(ManagementReviewInput).filter(
        ManagementReviewInput.review_id == review_id,
        ManagementReviewInput.category.in_(kpi.MGMT_REVIEW_AUTO_CATEGORIES),
    ).delete(synchronize_session=False)

    created = [
        ManagementReviewInput(review_id=review_id, **row)
        for row in kpi.management_review_inputs(db)
    ]
    db.add_all(created)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="compile_auto_inputs",
        entity_type=ENTITY,
        entity_id=review_id,
        after={"count": len(created)},
        **request_context(request),
    )
    db.commit()
    for item in created:
        db.refresh(item)
    return created


@router.post(
    "/{review_id}/action-items",
    response_model=ActionItemRead,
    status_code=status.HTTP_201_CREATED,
)
def add_action_item(
    review_id: int,
    body: ActionItemCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("mgmt_reviews.edit")),
) -> ActionItem:
    get_or_404(db, ManagementReview, review_id, name="Management review")
    item = ActionItem(
        review_id=review_id,
        **body.model_dump(),
        status=ActionItemStatus.OPEN,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(item)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="add_action_item",
        entity_type=ENTITY,
        entity_id=review_id,
        after={"action_item_id": item.id},
        **request_context(request),
    )
    db.commit()
    db.refresh(item)
    return item


@router.patch("/action-items/{item_id}", response_model=ActionItemRead)
def update_action_item(
    item_id: int,
    body: ActionItemUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_perm("mgmt_reviews.edit")),
) -> ActionItem:
    item = db.get(ActionItem, item_id)
    if item is None:
        raise NotFoundError(f"Action item {item_id} not found.")
    data = body.model_dump(exclude_unset=True)
    if data.get("status") == ActionItemStatus.COMPLETED and item.completed_at is None:
        item.completed_at = datetime.now(UTC)
    for key, value in data.items():
        setattr(item, key, value)
    item.updated_by = actor.id
    db.commit()
    db.refresh(item)
    return item
