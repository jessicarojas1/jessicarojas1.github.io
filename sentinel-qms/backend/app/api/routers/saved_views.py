"""Per-user saved list views (filter / sort presets)."""

from __future__ import annotations

import json

from fastapi import APIRouter, Depends, Query, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.api.deps import get_current_user
from app.core.database import get_db
from app.core.exceptions import NotFoundError
from app.models.saved_view import SavedView
from app.schemas.auth import CurrentUser
from app.schemas.saved_view import SavedViewCreate, SavedViewRead

router = APIRouter(prefix="/saved-views", tags=["saved-views"])


def _read(v: SavedView) -> dict:
    try:
        params = json.loads(v.params) if v.params else {}
    except ValueError:
        params = {}
    return {"id": v.id, "page_key": v.page_key, "name": v.name, "params": params}


@router.get("", response_model=list[SavedViewRead])
def list_views(
    page_key: str = Query(..., max_length=64),
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(get_current_user),
) -> list[dict]:
    rows = (
        db.execute(
            select(SavedView)
            .where(SavedView.user_id == actor.id, SavedView.page_key == page_key)
            .order_by(SavedView.name)
        )
        .scalars()
        .all()
    )
    return [_read(v) for v in rows]


@router.post("", response_model=SavedViewRead, status_code=status.HTTP_201_CREATED)
def create_view(
    body: SavedViewCreate,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(get_current_user),
) -> dict:
    view = SavedView(
        user_id=actor.id,
        page_key=body.page_key,
        name=body.name,
        params=json.dumps(body.params),
        created_by=actor.id,
        updated_by=actor.id,
    )
    db.add(view)
    db.commit()
    db.refresh(view)
    return _read(view)


@router.delete("/{view_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_view(
    view_id: int,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(get_current_user),
) -> None:
    view = db.get(SavedView, view_id)
    if view is None or view.user_id != actor.id:
        raise NotFoundError(f"Saved view {view_id} not found.")
    db.delete(view)
    db.commit()
