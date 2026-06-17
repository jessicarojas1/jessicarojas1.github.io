"""Canonical registry of application pages and their default permission mapping.

This is the single source of truth for:

* the navigation/page list exposed to the frontend (``GET /api/v1/pages``),
* the *default* (read, write) :class:`~app.core.rbac.Permission` each page maps
  to, used both for seeding the DB permission matrix and as the static fallback
  when no DB row exists for a (role, page).

Permission LEVELS are the ordered strings ``"none" < "view" < "edit"``.
"""

from __future__ import annotations

from typing import TypedDict

from app.core.rbac import Permission

# Ordered permission levels. A higher index means more access.
LEVELS: tuple[str, ...] = ("none", "view", "edit")
LEVEL_RANK: dict[str, int] = {level: i for i, level in enumerate(LEVELS)}


class PageDef(TypedDict):
    key: str
    label: str
    group: str
    admin_only: bool


def _page(key: str, label: str, group: str, admin_only: bool = False) -> PageDef:
    return {"key": key, "label": label, "group": group, "admin_only": admin_only}


PAGES: list[PageDef] = [
    # Overview
    _page("dashboard", "Dashboard", "Overview"),
    _page("analytics", "Analytics", "Overview"),
    _page("documentation", "Documentation", "Overview"),
    # Quality Events
    _page("nonconformances", "Nonconformances", "Quality Events"),
    _page("capa", "CAPA", "Quality Events"),
    _page("complaints", "Complaints", "Quality Events"),
    _page("risks", "Risks", "Quality Events"),
    # Control
    _page("documents", "Documents", "Control"),
    _page("changes", "Change Control", "Control"),
    _page("audits", "Audits", "Control"),
    _page("inspections", "Inspections", "Control"),
    # Operations
    _page("suppliers", "Suppliers", "Operations"),
    _page("calibration", "Calibration", "Operations"),
    _page("training", "Training", "Operations"),
    _page("mgmt_reviews", "Management Reviews", "Operations"),
    _page("quality_objectives", "Quality Objectives", "Operations"),
    # Administration (admin-only)
    _page("users", "Users", "Administration", admin_only=True),
    _page("roles", "Roles", "Administration", admin_only=True),
    _page("permissions", "Permissions", "Administration", admin_only=True),
    _page("audit_trail", "Audit Trail", "Administration", admin_only=True),
]

PAGE_KEYS: frozenset[str] = frozenset(p["key"] for p in PAGES)


# (read_permission, write_permission). ``None`` means "no specific permission":
# a ``None`` read perm => every authenticated user may view; a ``None`` write
# perm => the page has no edit action distinct from view.
PAGE_DEFAULT_PERMS: dict[str, tuple[Permission | None, Permission | None]] = {
    "dashboard": (Permission.NCR_READ, None),
    "analytics": (Permission.NCR_READ, None),
    "documentation": (None, None),
    "nonconformances": (Permission.NCR_READ, Permission.NCR_WRITE),
    "capa": (Permission.CAPA_READ, Permission.CAPA_WRITE),
    "complaints": (Permission.COMPLAINT_READ, Permission.COMPLAINT_WRITE),
    "risks": (Permission.RISK_READ, Permission.RISK_WRITE),
    "documents": (Permission.DOCUMENT_READ, Permission.DOCUMENT_WRITE),
    "changes": (Permission.CHANGE_READ, Permission.CHANGE_WRITE),
    "audits": (Permission.AUDIT_READ, Permission.AUDIT_WRITE),
    "inspections": (Permission.INSPECTION_READ, Permission.INSPECTION_WRITE),
    "suppliers": (Permission.SUPPLIER_READ, Permission.SUPPLIER_WRITE),
    "calibration": (Permission.CALIBRATION_READ, Permission.CALIBRATION_WRITE),
    "training": (Permission.TRAINING_READ, Permission.TRAINING_WRITE),
    "mgmt_reviews": (Permission.MGMT_REVIEW_READ, Permission.MGMT_REVIEW_WRITE),
    "quality_objectives": (Permission.QOBJECTIVE_READ, Permission.QOBJECTIVE_WRITE),
    "users": (Permission.USER_MANAGE, Permission.USER_MANAGE),
    "roles": (Permission.USER_MANAGE, Permission.USER_MANAGE),
    "permissions": (Permission.USER_MANAGE, Permission.USER_MANAGE),
    "audit_trail": (Permission.USER_MANAGE, Permission.USER_MANAGE),
}
