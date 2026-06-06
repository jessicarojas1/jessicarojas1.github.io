"""DB-driven, page-level role permission model.

A :class:`RolePagePermission` row records the access level a given role has for a
given page (by ``page_key`` from :mod:`app.core.pages`). When no row exists for a
(role, page) pair the effective-permission helpers fall back to the static
mapping in :mod:`app.core.rbac`, so the system is never hard-locked.
"""
from __future__ import annotations

from sqlalchemy import ForeignKey, Integer, String, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import TimestampMixin


class RolePagePermission(Base, TimestampMixin):
    __tablename__ = "role_page_permissions"
    __table_args__ = (
        UniqueConstraint("role_id", "page_key", name="uq_role_page"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    role_id: Mapped[int] = mapped_column(
        ForeignKey("roles.id", ondelete="CASCADE"), nullable=False, index=True
    )
    page_key: Mapped[str] = mapped_column(String(64), nullable=False)
    level: Mapped[str] = mapped_column(String(16), default="none", nullable=False)


class UserPagePermission(Base, TimestampMixin):
    """Per-user page-permission override.

    When a row exists for a (user, page) it REPLACES the level the user would
    otherwise derive from their roles — letting an admin elevate or restrict an
    individual user. Absence of a row means "inherit from roles".
    """

    __tablename__ = "user_page_permissions"
    __table_args__ = (
        UniqueConstraint("user_id", "page_key", name="uq_user_page"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=False, index=True
    )
    page_key: Mapped[str] = mapped_column(String(64), nullable=False)
    level: Mapped[str] = mapped_column(String(16), default="none", nullable=False)
