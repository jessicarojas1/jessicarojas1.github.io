"""One-off admin credential reset — operator utility, not an HTTP endpoint.

Run from a deploy shell (e.g. Render) to create-or-resync a local administrator
without changing environment variables or triggering a redeploy:

    python -m app.reset_admin <email> <password>

It sets the password, (re)activates the account, and ensures the ``Admin`` role.
The action is written to the immutable audit log. Refuses weak passwords.
"""

from __future__ import annotations

import sys

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.database import SessionLocal
from app.core.rbac import Role as RoleEnum
from app.core.security import hash_password
from app.models.user import Role, User

MIN_PASSWORD_LENGTH = 12


def reset_admin(db: Session, email: str, password: str) -> str:
    """Create or re-sync an administrator account. Returns a status message."""
    email = (email or "").strip().lower()
    if not email or "@" not in email:
        raise SystemExit("A valid email address is required.")
    if len(password or "") < MIN_PASSWORD_LENGTH:
        raise SystemExit(
            f"Refusing to set a password shorter than {MIN_PASSWORD_LENGTH} characters."
        )

    admin_role = db.execute(
        select(Role).where(Role.name == RoleEnum.ADMIN.value)
    ).scalar_one_or_none()
    if admin_role is None:
        admin_role = Role(name=RoleEnum.ADMIN.value, description="Administrator")
        db.add(admin_role)
        db.flush()

    user = db.execute(select(User).where(func.lower(User.email) == email)).scalar_one_or_none()
    created = user is None
    if user is None:
        user = User(email=email, full_name="System Administrator", is_active=True)
        db.add(user)

    user.hashed_password = hash_password(password)
    user.is_active = True
    if admin_role not in user.roles:
        user.roles = [*user.roles, admin_role]
    db.flush()

    audit.record(
        db,
        actor_id=user.id,
        actor_email=user.email,
        action="admin_credentials_reset",
        entity_type="user",
        entity_id=user.id,
        after={"email": email, "via": "reset_admin_cli", "created": created},
    )
    db.commit()
    return f"Admin account {'created' if created else 'updated'}: {email}"


def main(argv: list[str] | None = None) -> None:
    argv = list(sys.argv[1:] if argv is None else argv)
    if len(argv) != 2:
        raise SystemExit("Usage: python -m app.reset_admin <email> <password>")
    with SessionLocal() as db:
        print(reset_admin(db, argv[0], argv[1]))


if __name__ == "__main__":
    main()
