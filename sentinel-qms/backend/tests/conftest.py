"""Pytest fixtures: in-memory SQLite DB, seeded roles/users, and auth clients."""

from __future__ import annotations

import os

os.environ.setdefault("DATABASE_URL", "sqlite+pysqlite:///:memory:")
os.environ.setdefault("JWT_SECRET", "test-secret-key-please-only-for-tests-32chars")
os.environ.setdefault("ENVIRONMENT", "development")
os.environ.setdefault("ADMIN_AUTO_CREATE", "false")
# The background scheduler must never spin up during tests.
os.environ.setdefault("RUN_SCHEDULER", "false")

import pytest
from fastapi.testclient import TestClient
from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker
from sqlalchemy.pool import StaticPool

from app.core.database import Base, get_db
from app.core.rbac import ROLE_PERMISSIONS
from app.core.rbac import Role as RoleEnum
from app.core.security import hash_password
from app.main import app
from app.models import Role, User


@pytest.fixture(scope="session")
def engine():
    eng = create_engine(
        "sqlite+pysqlite:///:memory:",
        connect_args={"check_same_thread": False},
        poolclass=StaticPool,
    )
    Base.metadata.create_all(bind=eng)
    return eng


@pytest.fixture()
def db_session(engine):
    TestingSessionLocal = sessionmaker(bind=engine, autoflush=False, expire_on_commit=False)
    # Clean slate per test for isolation.
    for table in reversed(Base.metadata.sorted_tables):
        with engine.begin() as conn:
            conn.execute(table.delete())
    session = TestingSessionLocal()
    try:
        yield session
    finally:
        session.close()


def _ensure_roles(session) -> dict[str, Role]:
    roles: dict[str, Role] = {}
    for role_enum in ROLE_PERMISSIONS:
        role = Role(name=role_enum.value, description=role_enum.value)
        session.add(role)
        roles[role_enum.value] = role
    session.flush()
    return roles


def _make_user(session, roles, email: str, role: RoleEnum, password: str) -> User:
    user = User(
        email=email,
        full_name=email.split("@")[0].title(),
        hashed_password=hash_password(password),
        is_active=True,
    )
    user.roles = [roles[role.value]]
    session.add(user)
    session.flush()
    return user


@pytest.fixture()
def seeded(db_session):
    """Seed roles and one user per relevant role; returns a lookup dict."""
    roles = _ensure_roles(db_session)
    users = {
        "admin": _make_user(db_session, roles, "admin@test.local", RoleEnum.ADMIN, "AdminPass123!"),
        "engineer": _make_user(
            db_session, roles, "qe@test.local", RoleEnum.QUALITY_ENGINEER, "EngPass123!"
        ),
        "manager": _make_user(
            db_session, roles, "qm@test.local", RoleEnum.QUALITY_MANAGER, "MgrPass123!"
        ),
        "readonly": _make_user(
            db_session, roles, "ro@test.local", RoleEnum.READ_ONLY, "ReadPass123!"
        ),
        "customer": _make_user(
            db_session, roles, "cust@test.local", RoleEnum.CUSTOMER, "CustPass123!"
        ),
    }
    db_session.commit()
    return {"roles": roles, "users": users}


@pytest.fixture()
def client(db_session):
    def _override_get_db():
        try:
            yield db_session
        finally:
            pass

    app.dependency_overrides[get_db] = _override_get_db
    with TestClient(app) as c:
        yield c
    app.dependency_overrides.clear()


def _login(client: TestClient, email: str, password: str) -> str:
    resp = client.post(
        "/api/v1/auth/login",
        json={"username": email, "password": password},
    )
    assert resp.status_code == 200, resp.text
    return resp.json()["access_token"]


@pytest.fixture()
def auth_headers(client, seeded):
    """Return a factory: auth_headers(role_key) -> {Authorization: Bearer ...}."""
    creds = {
        "admin": ("admin@test.local", "AdminPass123!"),
        "engineer": ("qe@test.local", "EngPass123!"),
        "manager": ("qm@test.local", "MgrPass123!"),
        "readonly": ("ro@test.local", "ReadPass123!"),
        "customer": ("cust@test.local", "CustPass123!"),
    }

    def _factory(role_key: str = "engineer") -> dict[str, str]:
        email, pwd = creds[role_key]
        token = _login(client, email, pwd)
        return {"Authorization": f"Bearer {token}"}

    return _factory
