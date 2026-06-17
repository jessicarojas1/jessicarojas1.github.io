"""Role-Based Access Control: roles, permissions, and FastAPI dependencies."""

from __future__ import annotations

from collections.abc import Callable
from enum import Enum

from fastapi import Depends, HTTPException, Request, status

from app.core.database import get_db


class Role(str, Enum):
    ADMIN = "Admin"
    QUALITY_MANAGER = "Quality Manager"
    QUALITY_ENGINEER = "Quality Engineer"
    AUDITOR = "Auditor"
    SUPPLIER_QUALITY = "Supplier Quality"
    OPERATOR = "Operator"
    READ_ONLY = "Read-Only"
    CUSTOMER = "Customer"


class Permission(str, Enum):
    # Generic verbs scoped by domain. Format: <domain>:<action>
    USER_MANAGE = "user:manage"
    DOCUMENT_READ = "document:read"
    DOCUMENT_WRITE = "document:write"
    DOCUMENT_APPROVE = "document:approve"
    NCR_READ = "ncr:read"
    NCR_WRITE = "ncr:write"
    NCR_DISPOSITION = "ncr:disposition"
    CAPA_READ = "capa:read"
    CAPA_WRITE = "capa:write"
    CAPA_CLOSE = "capa:close"
    AUDIT_READ = "audit:read"
    AUDIT_WRITE = "audit:write"
    SUPPLIER_READ = "supplier:read"
    SUPPLIER_WRITE = "supplier:write"
    CALIBRATION_READ = "calibration:read"
    CALIBRATION_WRITE = "calibration:write"
    TRAINING_READ = "training:read"
    TRAINING_WRITE = "training:write"
    CHANGE_READ = "change:read"
    CHANGE_WRITE = "change:write"
    RISK_READ = "risk:read"
    RISK_WRITE = "risk:write"
    INSPECTION_READ = "inspection:read"
    INSPECTION_WRITE = "inspection:write"
    MGMT_REVIEW_READ = "mgmt_review:read"
    MGMT_REVIEW_WRITE = "mgmt_review:write"
    COMPLAINT_READ = "complaint:read"
    COMPLAINT_WRITE = "complaint:write"
    QOBJECTIVE_READ = "quality_objective:read"
    QOBJECTIVE_WRITE = "quality_objective:write"
    IMPROVEMENT_READ = "improvement:read"
    IMPROVEMENT_WRITE = "improvement:write"
    CSAT_READ = "csat:read"
    CSAT_WRITE = "csat:write"
    DASHBOARD_READ = "dashboard:read"


# Every authenticated user can read most modules; writes are restricted.
_READ_ALL = {
    Permission.DOCUMENT_READ,
    Permission.NCR_READ,
    Permission.CAPA_READ,
    Permission.AUDIT_READ,
    Permission.SUPPLIER_READ,
    Permission.CALIBRATION_READ,
    Permission.TRAINING_READ,
    Permission.CHANGE_READ,
    Permission.RISK_READ,
    Permission.INSPECTION_READ,
    Permission.MGMT_REVIEW_READ,
    Permission.COMPLAINT_READ,
    Permission.QOBJECTIVE_READ,
    Permission.IMPROVEMENT_READ,
    Permission.CSAT_READ,
    Permission.DASHBOARD_READ,
}

ALL_PERMISSIONS = set(Permission)

ROLE_PERMISSIONS: dict[Role, set[Permission]] = {
    Role.ADMIN: ALL_PERMISSIONS,
    Role.QUALITY_MANAGER: ALL_PERMISSIONS - {Permission.USER_MANAGE},
    Role.QUALITY_ENGINEER: _READ_ALL
    | {
        Permission.DOCUMENT_WRITE,
        Permission.NCR_WRITE,
        Permission.NCR_DISPOSITION,
        Permission.CAPA_WRITE,
        Permission.CAPA_CLOSE,
        Permission.AUDIT_WRITE,
        Permission.CALIBRATION_WRITE,
        Permission.CHANGE_WRITE,
        Permission.RISK_WRITE,
        Permission.INSPECTION_WRITE,
        Permission.COMPLAINT_WRITE,
        Permission.QOBJECTIVE_WRITE,
        Permission.IMPROVEMENT_WRITE,
        Permission.CSAT_WRITE,
        Permission.DOCUMENT_APPROVE,
    },
    Role.AUDITOR: _READ_ALL | {Permission.AUDIT_WRITE},
    Role.SUPPLIER_QUALITY: _READ_ALL
    | {
        Permission.SUPPLIER_WRITE,
        Permission.NCR_WRITE,
        Permission.CAPA_WRITE,
    },
    Role.OPERATOR: _READ_ALL
    | {
        Permission.NCR_WRITE,
        Permission.INSPECTION_WRITE,
    },
    Role.READ_ONLY: set(_READ_ALL),
    # Customer: an external stakeholder with NO standing module access. They see
    # only records explicitly shared with them via "Shared with Me" (the shares
    # endpoints are gated by share ownership, not by these permissions).
    Role.CUSTOMER: set(),
}


def permissions_for_roles(roles: list[str]) -> set[Permission]:
    perms: set[Permission] = set()
    for r in roles:
        try:
            perms |= ROLE_PERMISSIONS.get(Role(r), set())
        except ValueError:
            continue
    return perms


def require_roles(*roles: Role) -> Callable:
    """Dependency factory: require the current user to hold at least one role."""
    allowed = {r.value for r in roles}

    def _checker(user=Depends(_lazy_current_user)):  # noqa: ANN001
        if not allowed.intersection(set(user.role_names)):
            raise HTTPException(
                status_code=status.HTTP_403_FORBIDDEN,
                detail="Insufficient role privileges for this operation.",
            )
        return user

    return _checker


def require_permission(*perms: Permission) -> Callable:
    """Dependency factory: require the current user to hold all listed permissions."""
    needed = set(perms)

    def _checker(user=Depends(_lazy_current_user)):  # noqa: ANN001
        granted = permissions_for_roles(user.role_names)
        if not needed.issubset(granted):
            raise HTTPException(
                status_code=status.HTTP_403_FORBIDDEN,
                detail="Insufficient permissions for this operation.",
            )
        return user

    return _checker


def _lazy_current_user(
    request: Request,
    db=Depends(get_db),  # noqa: ANN001
):
    """Resolve the principal, delegating to ``app.api.deps``.

    ``resolve_current_user`` is imported lazily to avoid an rbac<->deps import
    cycle at module load. ``get_db`` is depended on directly (not via a wrapper)
    so test/dependency overrides of ``get_db`` apply here too.
    """
    from app.api.deps import resolve_current_user

    return resolve_current_user(request, db)
