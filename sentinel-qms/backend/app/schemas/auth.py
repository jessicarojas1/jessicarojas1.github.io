"""Auth and user/role schemas."""
from __future__ import annotations

from datetime import datetime

from pydantic import BaseModel, EmailStr, Field

from app.schemas.common import ORMModel


class Token(BaseModel):
    access_token: str
    refresh_token: str
    token_type: str = "bearer"
    expires_in: int


class TokenRefreshRequest(BaseModel):
    refresh_token: str


class LoginRequest(BaseModel):
    username: EmailStr
    password: str = Field(..., min_length=1, max_length=256)


class RoleRead(ORMModel):
    id: int
    name: str
    description: str | None = None


class UserBase(BaseModel):
    email: EmailStr
    full_name: str = Field(..., min_length=1, max_length=255)
    employee_id: str | None = Field(default=None, max_length=64)
    department: str | None = Field(default=None, max_length=128)
    is_active: bool = True


class UserCreate(UserBase):
    password: str = Field(..., min_length=12, max_length=256)
    role_names: list[str] = Field(default_factory=list)


class UserUpdate(BaseModel):
    full_name: str | None = Field(default=None, max_length=255)
    department: str | None = Field(default=None, max_length=128)
    employee_id: str | None = Field(default=None, max_length=64)
    is_active: bool | None = None
    password: str | None = Field(default=None, min_length=12, max_length=256)
    role_names: list[str] | None = None


class UserRead(ORMModel):
    id: int
    email: EmailStr
    full_name: str
    employee_id: str | None = None
    department: str | None = None
    is_active: bool
    is_sso: bool
    last_login_at: datetime | None = None
    roles: list[RoleRead] = []
    created_at: datetime | None = None


class CurrentUser(BaseModel):
    """Lightweight principal attached to the request after authentication."""

    id: int
    email: str
    full_name: str
    role_names: list[str]
    is_active: bool
