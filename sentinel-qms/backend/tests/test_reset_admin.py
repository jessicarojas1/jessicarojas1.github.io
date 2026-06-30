"""Operator admin-reset CLI: create, re-sync, role-grant, and weak-password guard."""

from __future__ import annotations

import pytest
from sqlalchemy import func, select

from app.core.rbac import Role as RoleEnum
from app.core.security import verify_password
from app.models.user import User
from app.reset_admin import reset_admin


def test_creates_new_admin(db_session, seeded):
    msg = reset_admin(db_session, "NewAdmin@corp.mil", "StrongPassw0rd!")
    assert "created" in msg
    user = db_session.execute(
        select(User).where(func.lower(User.email) == "newadmin@corp.mil")
    ).scalar_one()
    assert user.is_active is True
    assert RoleEnum.ADMIN.value in user.role_names
    assert verify_password("StrongPassw0rd!", user.hashed_password)


def test_resyncs_existing_user_and_grants_admin(db_session, seeded):
    # An existing engineer is promoted + password reset.
    msg = reset_admin(db_session, "qe@test.local", "BrandNewPassw0rd!")
    assert "updated" in msg
    user = db_session.execute(
        select(User).where(func.lower(User.email) == "qe@test.local")
    ).scalar_one()
    assert RoleEnum.ADMIN.value in user.role_names
    assert verify_password("BrandNewPassw0rd!", user.hashed_password)


def test_rejects_weak_password(db_session, seeded):
    with pytest.raises(SystemExit):
        reset_admin(db_session, "weak@corp.mil", "short")


def test_rejects_bad_email(db_session, seeded):
    with pytest.raises(SystemExit):
        reset_admin(db_session, "not-an-email", "StrongPassw0rd!")
