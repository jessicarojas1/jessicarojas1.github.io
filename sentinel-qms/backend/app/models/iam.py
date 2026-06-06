"""Granular (module x action) per-user permission grant model.

A :class:`UserPermissionGrant` row records that a user is EXPLICITLY granted a
single granular permission string ``"<module>.<action>"`` (see
:mod:`app.core.iam`). Effective permissions layer these explicit grants on top
of the user's role defaults (additive: granted if the role grants it OR the user
is explicitly granted it). This is independent of the page-level
:class:`~app.models.permission.UserPagePermission` overrides, which keep working.
"""
from __future__ import annotations

from sqlalchemy import ForeignKey, Integer, String, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import TimestampMixin


class UserPermissionGrant(Base, TimestampMixin):
    __tablename__ = "user_permission_grants"
    __table_args__ = (
        UniqueConstraint("user_id", "permission", name="uq_user_permission"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=False, index=True
    )
    permission: Mapped[str] = mapped_column(String(128), nullable=False)
