"""Effective page-permission resolution.

Combines the DB-driven :class:`~app.models.permission.RolePagePermission` rows
(authoritative when present) with the static fallback derived from
:data:`app.core.rbac.ROLE_PERMISSIONS` + :data:`app.core.pages.PAGE_DEFAULT_PERMS`.

Crucially, an *un-provisioned* (role, page) pair (no DB row) resolves to its
static default, never to a hard "none" — guaranteeing backward compatibility for
deployments and tests that never seed page permissions.
"""

from __future__ import annotations

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.pages import LEVEL_RANK, PAGE_DEFAULT_PERMS, PAGES
from app.core.rbac import ROLE_PERMISSIONS, Role


def level_at_least(actual: str, required: str) -> bool:
    """True if ``actual`` level meets or exceeds ``required`` in the ordering."""
    return LEVEL_RANK.get(actual, 0) >= LEVEL_RANK.get(required, 0)


def default_level_for(role: Role, page_key: str) -> str:
    """Static fallback level for a (role, page), from ROLE_PERMISSIONS + defaults.

    * "edit" if the role holds the page's write permission.
    * "view" if the role can view the page (holds the read permission, the read
      permission is ``None`` so everyone may view, or the page has no distinct
      write permission but the role can read it).
    * "none" otherwise.
    """
    # Customers have no standing page access — their only surface is the
    # "Shared with Me" route, which is gated by share ownership rather than the
    # page matrix. Returning "none" here also stops pages whose read permission
    # is ``None`` (e.g. Documentation) from leaking into the customer's nav.
    if role == Role.CUSTOMER:
        return "none"

    read_perm, write_perm = PAGE_DEFAULT_PERMS.get(page_key, (None, None))
    granted = ROLE_PERMISSIONS.get(role, set())

    # Determine view eligibility first.
    can_view = read_perm is None or read_perm in granted

    if write_perm is not None and write_perm in granted:
        return "edit"

    if can_view:
        return "view"
    return "none"


def user_explicit_levels(db: Session, user_id: int) -> dict[str, str]:
    """Per-user override levels (only pages that have an explicit row)."""
    from app.models.permission import UserPagePermission

    rows = (
        db.execute(select(UserPagePermission).where(UserPagePermission.user_id == user_id))
        .scalars()
        .all()
    )
    return {r.page_key: r.level for r in rows}


def role_derived_levels(db: Session, user) -> dict[str, str]:  # noqa: ANN001
    """Max level per page across the user's roles (DB row, else static default)."""
    from app.models.permission import RolePagePermission
    from app.models.user import Role as RoleModel

    role_names = list(user.role_names)
    if not role_names:
        return {p["key"]: "none" for p in PAGES}

    # Resolve role name -> id and the Role enum for static defaults.
    role_rows = db.execute(
        select(RoleModel.id, RoleModel.name).where(RoleModel.name.in_(role_names))
    ).all()
    id_to_name = dict(role_rows)
    role_ids = list(id_to_name)

    # DB overrides keyed by (role_id, page_key).
    db_levels: dict[tuple[int, str], str] = {}
    if role_ids:
        for rp in (
            db.execute(select(RolePagePermission).where(RolePagePermission.role_id.in_(role_ids)))
            .scalars()
            .all()
        ):
            db_levels[(rp.role_id, rp.page_key)] = rp.level

    # Map role names to the Role enum (for static defaults). Unknown names skip.
    enum_by_name: dict[str, Role] = {}
    for name in role_names:
        try:
            enum_by_name[name] = Role(name)
        except ValueError:
            continue

    result: dict[str, str] = {}
    for page in PAGES:
        page_key = page["key"]
        best = "none"
        for rid, rname in id_to_name.items():
            override = db_levels.get((rid, page_key))
            if override is not None:
                level = override
            else:
                role_enum = enum_by_name.get(rname)
                level = default_level_for(role_enum, page_key) if role_enum else "none"
            if LEVEL_RANK.get(level, 0) > LEVEL_RANK.get(best, 0):
                best = level
        result[page_key] = best
    return result


def effective_levels(db: Session, user) -> dict[str, str]:  # noqa: ANN001
    """Effective level per page: a per-user override REPLACES the role-derived
    level when present; otherwise the role-derived level applies."""
    levels = role_derived_levels(db, user)
    user_id = getattr(user, "id", None)
    if user_id is not None:
        for page_key, level in user_explicit_levels(db, int(user_id)).items():
            levels[page_key] = level
    return levels
