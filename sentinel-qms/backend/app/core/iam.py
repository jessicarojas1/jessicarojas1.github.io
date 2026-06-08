"""Granular module x action IAM catalog and resolution (AEGIS-style).

This is an ADDITIVE layer on top of the page-level
:class:`~app.models.permission.RolePagePermission` /
:class:`~app.models.permission.UserPagePermission` model, which keeps working
unchanged. Granular permissions are strings ``"<module>.<action>"``.

Effective permissions for a user = the union of the default grants of all the
user's roles (:data:`ROLE_DEFAULT_PERMISSIONS`) and the user's explicit grants
(:class:`~app.models.iam.UserPermissionGrant` rows). A permission is granted if
the role grants it OR the user is explicitly granted it.
"""

from __future__ import annotations

from typing import TypedDict

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.rbac import Role


class ActionDef(TypedDict):
    key: str
    label: str


class ModuleDef(TypedDict):
    key: str
    label: str
    icon: str
    actions: list[ActionDef]


def _a(key: str, label: str) -> ActionDef:
    return {"key": key, "label": label}


def _m(key: str, label: str, icon: str, actions: list[ActionDef]) -> ModuleDef:
    return {"key": key, "label": label, "icon": icon, "actions": actions}


# Canonical, ordered module/action catalog. The full permission string for an
# action is ``"<module.key>.<action.key>"``.
MODULES: list[ModuleDef] = [
    _m(
        "nonconformances",
        "Nonconformances",
        "shield-alert",
        [
            _a("view", "View"),
            _a("create", "Create"),
            _a("edit", "Edit"),
            _a("disposition", "Disposition"),
            _a("close", "Close"),
            _a("delete", "Delete"),
        ],
    ),
    _m(
        "capa",
        "CAPA",
        "clipboard-check",
        [
            _a("view", "View"),
            _a("create", "Create"),
            _a("edit", "Edit"),
            _a("close", "Close"),
            _a("delete", "Delete"),
        ],
    ),
    _m(
        "complaints",
        "Complaints",
        "message-warning",
        [
            _a("view", "View"),
            _a("create", "Create"),
            _a("edit", "Edit"),
            _a("close", "Close"),
        ],
    ),
    _m(
        "risks",
        "Risks",
        "shield-alert",
        [
            _a("view", "View"),
            _a("create", "Create"),
            _a("edit", "Edit"),
            _a("treat", "Treat"),
            _a("close", "Close"),
        ],
    ),
    _m(
        "documents",
        "Documents",
        "file-text",
        [
            _a("view", "View"),
            _a("create", "Create"),
            _a("edit", "Edit"),
            _a("approve", "Approve"),
            _a("obsolete", "Obsolete"),
        ],
    ),
    _m(
        "changes",
        "Change Control",
        "git-pull-request",
        [
            _a("view", "View"),
            _a("create", "Create"),
            _a("edit", "Edit"),
            _a("approve", "Approve"),
        ],
    ),
    _m(
        "audits",
        "Audits",
        "scroll-text",
        [
            _a("view", "View"),
            _a("create", "Create"),
            _a("edit", "Edit"),
            _a("conduct", "Conduct"),
            _a("close", "Close"),
        ],
    ),
    _m(
        "inspections",
        "Inspections",
        "flask",
        [
            _a("view", "View"),
            _a("create", "Create"),
            _a("edit", "Edit"),
            _a("record", "Record"),
        ],
    ),
    _m(
        "suppliers",
        "Suppliers",
        "truck",
        [
            _a("view", "View"),
            _a("create", "Create"),
            _a("edit", "Edit"),
            _a("scar", "SCAR"),
            _a("rate", "Rate"),
        ],
    ),
    _m(
        "calibration",
        "Calibration",
        "wrench",
        [
            _a("view", "View"),
            _a("create", "Create"),
            _a("edit", "Edit"),
            _a("record", "Record"),
        ],
    ),
    _m(
        "training",
        "Training",
        "graduation-cap",
        [
            _a("view", "View"),
            _a("create", "Create"),
            _a("edit", "Edit"),
            _a("record", "Record"),
        ],
    ),
    _m(
        "mgmt_reviews",
        "Management Reviews",
        "gauge",
        [
            _a("view", "View"),
            _a("create", "Create"),
            _a("edit", "Edit"),
            _a("close", "Close"),
        ],
    ),
    _m(
        "analytics",
        "Analytics",
        "trending-up",
        [
            _a("view", "View"),
        ],
    ),
    _m(
        "reports",
        "Reports",
        "file-bar-chart",
        [
            _a("view", "View"),
            _a("export", "Export"),
        ],
    ),
    _m(
        "documentation",
        "Documentation",
        "book-open",
        [
            _a("view", "View"),
        ],
    ),
    _m(
        "users",
        "Users",
        "users",
        [
            _a("view", "View"),
            _a("manage", "Manage"),
        ],
    ),
    _m(
        "roles",
        "Roles",
        "award",
        [
            _a("view", "View"),
            _a("manage", "Manage"),
        ],
    ),
    _m(
        "permissions",
        "Permissions",
        "key",
        [
            _a("view", "View"),
            _a("manage", "Manage"),
        ],
    ),
    _m(
        "audit_trail",
        "Audit Trail",
        "history",
        [
            _a("view", "View"),
        ],
    ),
    _m(
        "settings",
        "Settings",
        "settings",
        [
            _a("view", "View"),
            _a("manage", "Manage"),
        ],
    ),
]


def permissions_for_module(key: str) -> list[str]:
    """Return the ordered ``"<module>.<action>"`` strings for one module."""
    for mod in MODULES:
        if mod["key"] == key:
            return [f"{key}.{a['key']}" for a in mod["actions"]]
    return []


# Every "module.action" permission string in the catalog.
ALL_PERMISSIONS: set[str] = {
    f"{mod['key']}.{action['key']}" for mod in MODULES for action in mod["actions"]
}

# Alias used for validation of incoming permission strings.
PERMISSION_SET: set[str] = set(ALL_PERMISSIONS)


def _perms(*keys: str) -> set[str]:
    """Helper to build a permission set, validating against the catalog."""
    out: set[str] = set()
    for k in keys:
        if k not in ALL_PERMISSIONS:
            raise ValueError(f"Unknown permission in default map: {k!r}")
        out.add(k)
    return out


def _all_view() -> set[str]:
    """Every ``"<module>.view"`` permission (read-only baseline)."""
    return {p for p in ALL_PERMISSIONS if p.endswith(".view")}


# --- Per-role granular defaults, derived from ROLE_PERMISSIONS / page model. ---

_QUALITY_MANAGER = (
    # View+create+edit on all quality modules.
    _perms(
        "nonconformances.view",
        "nonconformances.create",
        "nonconformances.edit",
        "nonconformances.disposition",
        "nonconformances.close",
        "nonconformances.delete",
        "capa.view",
        "capa.create",
        "capa.edit",
        "capa.close",
        "complaints.view",
        "complaints.create",
        "complaints.edit",
        "complaints.close",
        "risks.view",
        "risks.create",
        "risks.edit",
        "risks.treat",
        "risks.close",
        "documents.view",
        "documents.create",
        "documents.edit",
        "documents.approve",
        "documents.obsolete",
        "changes.view",
        "changes.create",
        "changes.edit",
        "changes.approve",
        "audits.view",
        "audits.create",
        "audits.edit",
        "audits.conduct",
        "audits.close",
        "inspections.view",
        "inspections.create",
        "inspections.edit",
        "inspections.record",
        "suppliers.view",
        "suppliers.create",
        "suppliers.edit",
        "suppliers.scar",
        "suppliers.rate",
        "calibration.view",
        "calibration.create",
        "calibration.edit",
        "calibration.record",
        "training.view",
        "training.create",
        "training.edit",
        "training.record",
        "mgmt_reviews.view",
        "mgmt_reviews.create",
        "mgmt_reviews.edit",
        "mgmt_reviews.close",
        "analytics.view",
        "reports.view",
        "reports.export",
        "documentation.view",
        "audit_trail.view",
        "users.view",
        "roles.view",
        "permissions.view",
        "settings.view",
    )
)

_QUALITY_ENGINEER = _perms(
    "nonconformances.view",
    "nonconformances.create",
    "nonconformances.edit",
    "nonconformances.disposition",
    "capa.view",
    "capa.create",
    "capa.edit",
    "changes.view",
    "changes.create",
    "changes.edit",
    "risks.view",
    "risks.create",
    "risks.edit",
    "inspections.view",
    "inspections.create",
    "inspections.edit",
    "complaints.view",
    "complaints.create",
    "complaints.edit",
    "documents.view",
    "documents.create",
    "documents.edit",
    "reports.view",
    "analytics.view",
    "calibration.view",
    "suppliers.view",
    "audits.view",
    "training.view",
    "documentation.view",
)

_AUDITOR = (
    _perms(
        "audits.view",
        "audits.create",
        "audits.edit",
        "audits.conduct",
        "audits.close",
        "audit_trail.view",
        "reports.view",
        "analytics.view",
    )
    | _all_view()
)

_SUPPLIER_QUALITY = (
    _perms(
        "suppliers.view",
        "suppliers.create",
        "suppliers.edit",
        "suppliers.scar",
        "suppliers.rate",
        "nonconformances.view",
        "nonconformances.create",
        "nonconformances.edit",
        "nonconformances.disposition",
        "inspections.view",
        "inspections.create",
        "inspections.edit",
        "inspections.record",
        "reports.view",
        "analytics.view",
    )
    | _all_view()
)

_OPERATOR = _perms(
    "nonconformances.view",
    "nonconformances.create",
    "nonconformances.edit",
    "inspections.view",
    "inspections.create",
    "inspections.edit",
    "inspections.record",
    "calibration.view",
    "documents.view",
    "training.view",
    "documentation.view",
)

_READ_ONLY = _all_view()


ROLE_DEFAULT_PERMISSIONS: dict[Role, set[str]] = {
    Role.ADMIN: set(ALL_PERMISSIONS),
    Role.QUALITY_MANAGER: _QUALITY_MANAGER,
    Role.QUALITY_ENGINEER: _QUALITY_ENGINEER,
    Role.AUDITOR: _AUDITOR,
    Role.SUPPLIER_QUALITY: _SUPPLIER_QUALITY,
    Role.OPERATOR: _OPERATOR,
    Role.READ_ONLY: _READ_ONLY,
    # Customer: no standing granular grants — access is via shared records only.
    Role.CUSTOMER: set(),
}


# --- Backward-compatibility: coarse page-level strings -> granular arrays. ---
# Lets older coarse checks (``"<module>.read"`` / ``"<module>.write"``) resolve
# to one or more granular permissions in this catalog.
BACKWARD_COMPAT_ALIASES: dict[str, list[str]] = {}


def _alias(coarse: str, granular: list[str]) -> None:
    BACKWARD_COMPAT_ALIASES[coarse] = granular


for _mod in MODULES:
    _key = _mod["key"]
    _action_keys = {a["key"] for a in _mod["actions"]}
    # read -> view
    if "view" in _action_keys:
        _alias(f"{_key}.read", [f"{_key}.view"])
    # write -> create + edit (whichever exist)
    _write = [f"{_key}.{act}" for act in ("create", "edit") if act in _action_keys]
    if _write:
        _alias(f"{_key}.write", _write)
    # manage -> manage (admin pages)
    if "manage" in _action_keys:
        _alias(f"{_key}.manage", [f"{_key}.manage"])


def resolve_alias(perm: str) -> list[str]:
    """Map a (possibly coarse) permission string to granular permission(s).

    Granular strings pass through unchanged; coarse strings expand via
    :data:`BACKWARD_COMPAT_ALIASES`. Unknown strings return ``[perm]``.
    """
    if perm in ALL_PERMISSIONS:
        return [perm]
    return BACKWARD_COMPAT_ALIASES.get(perm, [perm])


# --- Resolution helpers. -----------------------------------------------------


def role_default_permissions(role_names: list[str]) -> set[str]:
    """Union of :data:`ROLE_DEFAULT_PERMISSIONS` across the given role names."""
    perms: set[str] = set()
    for name in role_names:
        try:
            role = Role(name)
        except ValueError:
            continue
        perms |= ROLE_DEFAULT_PERMISSIONS.get(role, set())
    return perms


def user_grant_rows(db: Session, user_id: int) -> tuple[set[str], set[str]]:
    """Return ``(granted, denied)`` explicit per-user permission sets."""
    from app.models.iam import UserPermissionGrant

    rows = db.execute(
        select(UserPermissionGrant.permission, UserPermissionGrant.deny).where(
            UserPermissionGrant.user_id == user_id
        )
    ).all()
    granted = {perm for perm, deny in rows if not deny}
    denied = {perm for perm, deny in rows if deny}
    return granted, denied


def user_explicit_permissions(db: Session, user_id: int) -> set[str]:
    """Explicit per-user *grants* (deny rows excluded). Back-compat helper."""
    return user_grant_rows(db, user_id)[0]


def effective_permissions(db: Session, user) -> set[str]:  # noqa: ANN001
    """(Role defaults UNION explicit grants) MINUS explicit denies."""
    perms = role_default_permissions(list(getattr(user, "role_names", []) or []))
    user_id = getattr(user, "id", None)
    if user_id is not None:
        granted, denied = user_grant_rows(db, int(user_id))
        perms = (perms | granted) - denied
    return perms


def has_permission(db: Session, user, perm: str) -> bool:  # noqa: ANN001
    """True if the user effectively holds ``perm`` (granular, alias-aware)."""
    effective = effective_permissions(db, user)
    return any(granular in effective for granular in resolve_alias(perm))
