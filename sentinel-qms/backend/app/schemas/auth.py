"""Auth and user/role schemas."""

from __future__ import annotations

from datetime import datetime

from pydantic import BaseModel, Field

from app.schemas.common import ORMModel


class Token(BaseModel):
    # The refresh token is NOT returned in the body; it is delivered as an
    # HttpOnly cookie (see app.core.cookies). Only the short-lived access token is
    # exposed to the SPA, which keeps it in memory.
    access_token: str
    token_type: str = "bearer"
    expires_in: int


class PasswordResetRequest(BaseModel):
    email: str = Field(..., min_length=1, max_length=320)


class PasswordResetConfirm(BaseModel):
    token: str = Field(..., min_length=1, max_length=512)
    new_password: str = Field(..., min_length=12, max_length=256)


class ChangePasswordRequest(BaseModel):
    current_password: str = Field(..., min_length=1, max_length=256)
    new_password: str = Field(..., min_length=12, max_length=256)


class LoginRequest(BaseModel):
    # Plain string (not EmailStr): the identifier is matched against the user's
    # email server-side, and EmailStr rejects reserved domains like ".local".
    username: str = Field(..., min_length=1, max_length=320)
    password: str = Field(..., min_length=1, max_length=256)
    # TOTP code, required only for accounts with MFA enabled.
    otp: str | None = Field(default=None, max_length=16)


class OidcExchangeRequest(BaseModel):
    """Exchange an IdP-issued OIDC ID token for an internal session."""

    id_token: str = Field(..., min_length=1)


class MfaEnrollResponse(BaseModel):
    secret: str
    otpauth_uri: str


class MfaCodeRequest(BaseModel):
    code: str = Field(..., min_length=1, max_length=16)


class MfaStatus(BaseModel):
    enabled: bool


class RoleRead(ORMModel):
    id: int
    name: str
    description: str | None = None


class UserBase(BaseModel):
    email: str
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
    email: str
    full_name: str
    employee_id: str | None = None
    department: str | None = None
    is_active: bool
    is_sso: bool
    last_login_at: datetime | None = None
    roles: list[RoleRead] = []
    created_at: datetime | None = None


class UserLookup(ORMModel):
    """Lightweight directory entry for resolving user IDs to names in the UI."""

    id: int
    full_name: str
    email: str
    is_active: bool


class CurrentUser(BaseModel):
    """Lightweight principal attached to the request after authentication."""

    id: int
    email: str
    full_name: str
    role_names: list[str]
    is_active: bool
