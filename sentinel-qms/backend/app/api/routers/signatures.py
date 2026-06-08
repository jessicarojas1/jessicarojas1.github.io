"""Electronic-signature manifest (read-only).

Surfaces the immutable 21 CFR Part 11 signatures captured on approvals,
dispositions and closures so they can be reviewed per record.
"""

from __future__ import annotations

from fastapi import APIRouter, Depends, Query
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.api.deps import get_current_user
from app.core.database import get_db
from app.models.user import ElectronicSignature
from app.schemas.auth import CurrentUser
from app.schemas.common import ESignatureRead

router = APIRouter(prefix="/signatures", tags=["signatures"])


@router.get("", response_model=list[ESignatureRead])
def list_signatures(
    entity_type: str = Query(..., max_length=64),
    entity_id: str = Query(..., max_length=64),
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(get_current_user),
) -> list[ElectronicSignature]:
    """Return the e-signatures for one record, newest first."""
    stmt = (
        select(ElectronicSignature)
        .where(
            ElectronicSignature.entity_type == entity_type,
            ElectronicSignature.entity_id == str(entity_id),
        )
        .order_by(ElectronicSignature.signed_at.desc())
    )
    return list(db.execute(stmt).scalars().all())
