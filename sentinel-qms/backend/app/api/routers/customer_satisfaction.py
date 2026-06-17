"""Customer Satisfaction survey endpoints (AS9100/ISO 9001 clause 9.1.2).

Reads require csat:read, writes csat:write.
"""

from __future__ import annotations

from fastapi import APIRouter, Depends, Query, status
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.exceptions import NotFoundError, ValidationAppError
from app.core.rbac import Permission, require_permission
from app.models.customer import Customer
from app.models.customer_satisfaction import CustomerSurvey
from app.schemas.auth import CurrentUser
from app.schemas.customer_satisfaction import (
    SurveyCreate,
    SurveyRead,
    SurveyUpdate,
    overall_of,
)
from app.services import numbering

router = APIRouter(prefix="/customer-satisfaction", tags=["customer-satisfaction"])

_READ = require_permission(Permission.CSAT_READ)
_WRITE = require_permission(Permission.CSAT_WRITE)


def _customer_name(db: Session, customer_id: int) -> str | None:
    c = db.get(Customer, customer_id)
    return c.name if c is not None else None


def _to_read(obj: CustomerSurvey, db: Session) -> dict:
    return {
        "id": obj.id,
        "survey_number": obj.survey_number,
        "customer_id": obj.customer_id,
        "customer_name": _customer_name(db, obj.customer_id),
        "period": obj.period,
        "survey_date": obj.survey_date,
        "method": obj.method,
        "quality_score": obj.quality_score,
        "delivery_score": obj.delivery_score,
        "communication_score": obj.communication_score,
        "overall_score": obj.overall_score,
        "respondent": obj.respondent,
        "comments": obj.comments,
    }


def _get(db: Session, survey_id: int) -> CustomerSurvey:
    obj = db.get(CustomerSurvey, survey_id)
    if obj is None or obj.is_deleted:
        raise NotFoundError(f"Customer survey {survey_id} not found.")
    return obj


@router.get("", response_model=list[SurveyRead])
def list_surveys(
    db: Session = Depends(get_db),
    customer_id: int | None = Query(None),
    _: CurrentUser = Depends(_READ),
) -> list[dict]:
    stmt = select(CustomerSurvey).where(CustomerSurvey.is_deleted.is_(False))
    if customer_id is not None:
        stmt = stmt.where(CustomerSurvey.customer_id == customer_id)
    stmt = stmt.order_by(CustomerSurvey.id.desc())
    return [_to_read(o, db) for o in db.execute(stmt).scalars().all()]


@router.post("", response_model=SurveyRead, status_code=status.HTTP_201_CREATED)
def create_survey(
    body: SurveyCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    customer = db.get(Customer, body.customer_id)
    if customer is None or customer.is_deleted:
        raise ValidationAppError(f"Customer {body.customer_id} not found.")
    data = body.model_dump()
    data["overall_score"] = overall_of(
        body.quality_score, body.delivery_score, body.communication_score, body.overall_score
    )
    obj = CustomerSurvey(
        **data,
        survey_number=numbering.next_number(db, CustomerSurvey, "survey_number", "CSAT"),
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(obj)
    db.commit()
    db.refresh(obj)
    return _to_read(obj, db)


@router.get("/summary", response_model=dict)
def satisfaction_summary(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> dict:
    """Average overall score and survey count (for the dashboard tile)."""
    avg, count = db.execute(
        select(func.avg(CustomerSurvey.overall_score), func.count()).where(
            CustomerSurvey.is_deleted.is_(False),
            CustomerSurvey.overall_score.is_not(None),
        )
    ).one()
    return {
        "average_overall": round(float(avg), 1) if avg is not None else None,
        "count": int(count),
    }


@router.get("/{survey_id}", response_model=SurveyRead)
def get_survey(
    survey_id: int,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(_READ),
) -> dict:
    return _to_read(_get(db, survey_id), db)


@router.patch("/{survey_id}", response_model=SurveyRead)
def update_survey(
    survey_id: int,
    body: SurveyUpdate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> dict:
    obj = _get(db, survey_id)
    data = body.model_dump(exclude_unset=True)
    for key, value in data.items():
        setattr(obj, key, value)
    # Recompute overall from sub-scores unless an explicit overall was sent now.
    obj.overall_score = overall_of(
        obj.quality_score,
        obj.delivery_score,
        obj.communication_score,
        data.get("overall_score"),
    )
    obj.updated_by = actor.id
    db.commit()
    db.refresh(obj)
    return _to_read(obj, db)


@router.delete("/{survey_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_survey(
    survey_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(_WRITE),
) -> None:
    _get(db, survey_id).soft_delete(actor.id)
    db.commit()
