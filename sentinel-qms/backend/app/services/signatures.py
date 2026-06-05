"""21 CFR Part 11 electronic-signature capture and verification."""
from __future__ import annotations

import hashlib
from datetime import datetime, timezone

from sqlalchemy.orm import Session

from app.core.exceptions import AuthenticationError
from app.core.security import verify_password
from app.models.user import ElectronicSignature, User
from app.schemas.auth import CurrentUser
from app.schemas.common import ESignatureIn


def create_signature(
    db: Session,
    *,
    actor: CurrentUser,
    entity_type: str,
    entity_id: int | str,
    payload: ESignatureIn,
    require_reauth: bool = True,
) -> ElectronicSignature:
    """Verify the signer (optional re-auth) and persist an immutable e-signature.

    The ``signed_hash`` binds signer + meaning + entity + timestamp so the
    record is tamper-evident in the audit trail.
    """
    if require_reauth:
        user = db.get(User, actor.id)
        if user is None or not user.hashed_password:
            raise AuthenticationError("Signer account cannot be verified.")
        if not payload.password or not verify_password(payload.password, user.hashed_password):
            raise AuthenticationError(
                "Electronic signature requires re-authentication with your password."
            )

    signed_at = datetime.now(timezone.utc)
    material = f"{actor.id}|{actor.email}|{entity_type}|{entity_id}|{payload.meaning}|{signed_at.isoformat()}"
    signed_hash = hashlib.sha256(material.encode("utf-8")).hexdigest()

    sig = ElectronicSignature(
        entity_type=entity_type,
        entity_id=str(entity_id),
        signer_id=actor.id,
        signer_name=actor.full_name,
        meaning=payload.meaning,
        reason=payload.reason,
        signed_hash=signed_hash,
        signed_at=signed_at,
    )
    db.add(sig)
    db.flush()
    return sig
