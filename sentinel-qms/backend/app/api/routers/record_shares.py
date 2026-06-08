"""Record shares — a "Shared with Me" inbox of read-only record pointers.

All endpoints require authentication. A share is a reference only; following it
still goes through the app's normal per-record permissions, so sharing never
grants new access or exposes anything publicly.
"""

from __future__ import annotations

from fastapi import APIRouter, Depends, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.api.deps import get_current_user
from app.core.database import get_db
from app.core.exceptions import NotFoundError
from app.models.record_share import RecordShare
from app.models.user import User
from app.schemas.auth import CurrentUser
from app.schemas.record_share import ShareCreate, ShareRead

router = APIRouter(prefix="/shares", tags=["shares"])


@router.get("/mine", response_model=list[ShareRead])
def list_my_shares(
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(get_current_user),
) -> list[RecordShare]:
    """Records shared with the current user, newest first."""
    stmt = (
        select(RecordShare)
        .where(RecordShare.shared_with_user_id == actor.id)
        .order_by(RecordShare.id.desc())
    )
    return list(db.execute(stmt).scalars().all())


@router.post("", response_model=ShareRead, status_code=status.HTTP_201_CREATED)
def create_share(
    body: ShareCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(get_current_user),
) -> RecordShare:
    recipient = db.get(User, body.shared_with_user_id)
    if recipient is None or not recipient.is_active:
        raise NotFoundError(f"User {body.shared_with_user_id} not found.")
    share = RecordShare(
        **body.model_dump(),
        shared_by_user_id=actor.id,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(share)
    db.commit()
    db.refresh(share)
    return share


@router.delete("/{share_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_share(
    share_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(get_current_user),
) -> None:
    """Recipient or the original sharer may remove a share."""
    share = db.get(RecordShare, share_id)
    if share is None or actor.id not in (share.shared_with_user_id, share.shared_by_user_id):
        raise NotFoundError(f"Share {share_id} not found.")
    db.delete(share)
    db.commit()
