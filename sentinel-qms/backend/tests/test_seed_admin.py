"""Bootstrap-admin seeding only happens when credentials are supplied.

Admin credentials come solely from the environment (e.g. the Render dashboard);
there is no committed default, so an unset password must skip the bootstrap
rather than create an admin with a baked-in secret.
"""

from __future__ import annotations

from sqlalchemy import func, select

from app.core.config import settings
from app.core.rbac import Role as RoleEnum
from app.models.user import User
from app.seed import seed_admin, seed_roles


def test_seed_admin_skipped_when_credentials_unset(db_session, monkeypatch):
    monkeypatch.setattr(settings, "ADMIN_AUTO_CREATE", True)
    monkeypatch.setattr(settings, "ADMIN_EMAIL", None)
    monkeypatch.setattr(settings, "ADMIN_PASSWORD", None)
    roles = seed_roles(db_session)

    assert seed_admin(db_session, roles) is None
    assert db_session.execute(select(func.count()).select_from(User)).scalar_one() == 0


def test_seed_admin_created_from_environment(db_session, monkeypatch):
    monkeypatch.setattr(settings, "ADMIN_AUTO_CREATE", True)
    monkeypatch.setattr(settings, "ADMIN_EMAIL", "ops@example.mil")
    monkeypatch.setattr(settings, "ADMIN_PASSWORD", "S3cret!Pass123")
    roles = seed_roles(db_session)

    admin = seed_admin(db_session, roles)
    assert admin is not None
    assert admin.email == "ops@example.mil"
    assert roles[RoleEnum.ADMIN.value] in admin.roles
