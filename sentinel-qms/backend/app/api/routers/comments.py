"""Comment / collaboration endpoints: threaded notes with @mentions."""
from __future__ import annotations

from fastapi import APIRouter, Depends, Request, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.api.deps import get_current_user
from app.core import audit
from app.core.database import get_db
from app.core.exceptions import NotFoundError, PermissionDeniedError
from app.core.rbac import Permission, permissions_for_roles
from app.models.comment import Comment
from app.schemas.auth import CurrentUser
from app.schemas.comment import CommentCreate, CommentRead
from app.services.crud import request_context
from app.services.notifications import notify_assignment

router = APIRouter(prefix="/comments", tags=["comments"])

ENTITY = "comment"


@router.get("", response_model=list[CommentRead])
def list_comments(
    entity_type: str,
    entity_id: str,
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(get_current_user),
) -> list[Comment]:
    """List comments for a record, oldest-first for natural thread reading."""
    return (
        db.execute(
            select(Comment)
            .where(
                Comment.entity_type == entity_type,
                Comment.entity_id == entity_id,
            )
            .order_by(Comment.id.asc())
        )
        .scalars()
        .all()
    )


@router.post("", response_model=CommentRead, status_code=status.HTTP_201_CREATED)
def create_comment(
    payload: CommentCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(get_current_user),
) -> Comment:
    comment = Comment(
        entity_type=payload.entity_type,
        entity_id=payload.entity_id,
        author_id=actor.id,
        body=payload.body,
        parent_id=payload.parent_id,
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(comment)
    db.flush()

    # Best-effort: notify each mentioned user (skip self-mentions).
    for mentioned_id in dict.fromkeys(payload.mentions):
        if mentioned_id == actor.id:
            continue
        try:
            notify_assignment(
                db,
                user_id=mentioned_id,
                title="You were mentioned",
                message=f"{actor.full_name or actor.email} mentioned you in a comment.",
                entity_type=payload.entity_type,
                entity_id=payload.entity_id,
            )
        except Exception:  # noqa: BLE001 — mention notification must never break the comment
            pass

    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="create",
        entity_type=ENTITY,
        entity_id=comment.id,
        after={
            "linked_to": f"{payload.entity_type}:{payload.entity_id}",
            "parent_id": payload.parent_id,
            "mentions": payload.mentions,
        },
        **request_context(request),
    )
    db.commit()
    db.refresh(comment)
    return comment


@router.delete("/{comment_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_comment(
    comment_id: int,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(get_current_user),
) -> None:
    comment = db.get(Comment, comment_id)
    if comment is None:
        raise NotFoundError(f"Comment {comment_id} not found.")

    can_manage = Permission.USER_MANAGE in permissions_for_roles(actor.role_names)
    if comment.author_id != actor.id and not can_manage:
        raise PermissionDeniedError("Only the author or an administrator may delete this comment.")

    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="delete",
        entity_type=ENTITY,
        entity_id=comment.id,
        before={
            "linked_to": f"{comment.entity_type}:{comment.entity_id}",
            "author_id": comment.author_id,
        },
        **request_context(request),
    )
    db.delete(comment)
    db.commit()
