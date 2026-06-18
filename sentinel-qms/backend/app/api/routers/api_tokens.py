"""Personal Access Tokens — self-service management of scoped API keys.

Every endpoint requires an authenticated *interactive* session (JWT), and a user
manages only their own tokens. Creating a token returns the plaintext secret
exactly once; thereafter only the non-secret prefix is ever exposed. A token
acts as its owner, so all normal RBAC still applies on top of its coarse scope.
"""

from __future__ import annotations

from datetime import UTC, datetime, timedelta

from fastapi import APIRouter, Depends, Request, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.api.deps import require_interactive_user
from app.core import audit
from app.core.database import get_db
from app.core.exceptions import NotFoundError
from app.models.api_token import ApiToken
from app.schemas.api_token import ApiTokenCreate, ApiTokenCreated, ApiTokenRead
from app.schemas.auth import CurrentUser
from app.services import api_tokens as svc

router = APIRouter(prefix="/tokens", tags=["api-tokens"])


@router.get("", response_model=list[ApiTokenRead])
def list_tokens(
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_interactive_user),
) -> list[ApiToken]:
    """The current user's tokens, newest first (secrets never included)."""
    stmt = select(ApiToken).where(ApiToken.user_id == actor.id).order_by(ApiToken.id.desc())
    return list(db.execute(stmt).scalars().all())


@router.post("", response_model=ApiTokenCreated, status_code=status.HTTP_201_CREATED)
def create_token(
    body: ApiTokenCreate,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_interactive_user),
) -> ApiTokenCreated:
    full_token, prefix, digest = svc.generate_token()
    scopes = svc.normalize_scopes(body.scopes)
    expires_at = (
        datetime.now(UTC) + timedelta(days=body.expires_in_days)
        if body.expires_in_days is not None
        else None
    )
    token = ApiToken(
        user_id=actor.id,
        name=body.name.strip(),
        token_prefix=prefix,
        token_hash=digest,
        scopes=scopes,
        expires_at=expires_at,
        created_by=actor.id,
    )
    db.add(token)
    db.flush()
    audit.record(
        db,
        actor_id=actor.id,
        actor_email=actor.email,
        action="api_token.create",
        entity_type="api_token",
        entity_id=token.id,
        after={"name": token.name, "scopes": scopes, "prefix": prefix},
        ip=getattr(request.client, "host", None),
        request_id=getattr(request.state, "request_id", None),
    )
    db.commit()
    db.refresh(token)
    # Serialize the stored row, then attach the one-time plaintext.
    payload = ApiTokenRead.model_validate(token).model_dump()
    return ApiTokenCreated(**payload, token=full_token)


@router.delete("/{token_id}", status_code=status.HTTP_204_NO_CONTENT)
def revoke_token(
    token_id: int,
    request: Request,
    db: Session = Depends(get_db),
    actor: CurrentUser = Depends(require_interactive_user),
) -> None:
    """Revoke (soft-disable) one of the current user's tokens. Idempotent."""
    token = db.get(ApiToken, token_id)
    if token is None or token.user_id != actor.id:
        raise NotFoundError(f"Token {token_id} not found.")
    if token.revoked_at is None:
        token.revoked_at = datetime.now(UTC)
        audit.record(
            db,
            actor_id=actor.id,
            actor_email=actor.email,
            action="api_token.revoke",
            entity_type="api_token",
            entity_id=token.id,
            before={"name": token.name, "prefix": token.token_prefix},
            ip=getattr(request.client, "host", None),
            request_id=getattr(request.state, "request_id", None),
        )
        db.commit()
