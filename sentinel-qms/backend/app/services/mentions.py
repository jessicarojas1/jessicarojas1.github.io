"""Server-side @mention resolution for comments.

The comment API accepts a client-supplied ``mentions: list[int]``, but that
source is untrusted: a caller could notify arbitrary users or users who cannot
see the record. This module re-derives the mention set from two sources and
validates both against the current user table:

1. ``@``-tokens parsed out of the comment body itself (trusted server-side).
2. The client-supplied ids (validated, not trusted).

Only existing, active, non-deleted users survive, the comment author is
excluded, and — best-effort — users who lack view access to the parent entity
are dropped so a mention never leaks a record to someone who cannot see it.
"""

from __future__ import annotations

import re

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.core.entity_access import ENTITY_VIEW_PAGE
from app.core.permissions import effective_levels, level_at_least
from app.models.user import User

# Match @email@domain mention tokens in free text. Emails are the only stable,
# unique handle Users have (there is no separate username/handle column), so we
# resolve @-tokens to email addresses. A leading @ precedes a normal email.
_MENTION_RE = re.compile(r"@([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})")


def _parse_mention_emails(body: str) -> list[str]:
    """Extract unique, lower-cased @email mention tokens from the comment body."""
    if not body:
        return []
    seen: dict[str, None] = {}
    for match in _MENTION_RE.finditer(body):
        seen.setdefault(match.group(1).lower(), None)
    return list(seen)


def _can_view_entity(db: Session, user: User, entity_type: str) -> bool:
    """Best-effort, non-raising view check mirroring ``require_entity_view``.

    Unknown entity types fail closed (no notification is sent)."""
    page_key = ENTITY_VIEW_PAGE.get(entity_type)
    if page_key is None:
        return False
    levels = effective_levels(db, user)
    return level_at_least(levels.get(page_key, "none"), "view")


def resolve_mentions(
    db: Session,
    body: str,
    client_ids: list[int],
    *,
    author_id: int,
    entity_type: str | None = None,
) -> list[int]:
    """Resolve the trusted set of user ids to notify for a comment.

    Unions @email tokens parsed from ``body`` with the client-supplied
    ``client_ids``. Every candidate is validated against the users table
    (existing, active, non-deleted). The author is excluded, and — when
    ``entity_type`` is given — users without view access to the parent entity
    are dropped. Returns a deterministic, de-duplicated, sorted list.
    """
    emails = _parse_mention_emails(body)

    # Load candidate active users by id and by (case-insensitive) email in
    # parameterized queries. Users have no soft-delete flag; ``is_active`` is the
    # authoritative "usable account" gate.
    candidates: dict[int, User] = {}

    ids = {int(i) for i in client_ids if isinstance(i, int)}
    if ids:
        rows = (
            db.execute(select(User).where(User.id.in_(ids), User.is_active.is_(True)))
            .scalars()
            .all()
        )
        for u in rows:
            candidates[u.id] = u

    if emails:
        rows = (
            db.execute(
                select(User).where(
                    func.lower(User.email).in_(emails), User.is_active.is_(True)
                )
            )
            .scalars()
            .all()
        )
        for u in rows:
            candidates[u.id] = u

    resolved: set[int] = set()
    for uid, user in candidates.items():
        if uid == author_id:
            continue
        if entity_type is not None and not _can_view_entity(db, user, entity_type):
            continue
        resolved.add(uid)

    return sorted(resolved)
