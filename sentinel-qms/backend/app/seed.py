"""Idempotent database seed: roles, bootstrap admin, and demo data.

Run with ``python -m app.seed``.  Safe to run repeatedly — it upserts by natural
key and skips records that already exist.
"""
from __future__ import annotations

import logging
from datetime import date, timedelta

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.config import settings
from app.core.database import SessionLocal, engine
from app.core.database import Base
from app.core.logging import configure_logging
from app.core.pages import PAGES
from app.core.permissions import default_level_for
from app.core.rbac import ROLE_PERMISSIONS, Role as RoleEnum
from app.core.security import hash_password
from app.models import (  # noqa: F401 - ensure metadata is populated
    Capa,
    CapaStatus,
    Department,
    Document,
    DocumentStatus,
    DocumentType,
    Equipment,
    NcSeverity,
    NcStatus,
    Nonconformance,
    OrgSettings,
    Role,
    RolePagePermission,
    Supplier,
    SupplierStatus,
    User,
)

logger = logging.getLogger("app.seed")

_ROLE_DESCRIPTIONS = {
    RoleEnum.ADMIN: "Full system administration and user management.",
    RoleEnum.QUALITY_MANAGER: "Owns the QMS; approves and closes records.",
    RoleEnum.QUALITY_ENGINEER: "Investigates NCRs/CAPAs and drives corrective action.",
    RoleEnum.AUDITOR: "Plans and conducts internal/external audits.",
    RoleEnum.SUPPLIER_QUALITY: "Manages supplier performance, SCARs, and the ASL.",
    RoleEnum.OPERATOR: "Reports nonconformances and records inspections.",
    RoleEnum.READ_ONLY: "Read-only visibility across modules.",
}


def seed_roles(db: Session) -> dict[str, Role]:
    existing = {r.name: r for r in db.execute(select(Role)).scalars().all()}
    for role_enum in ROLE_PERMISSIONS:
        if role_enum.value not in existing:
            role = Role(
                name=role_enum.value,
                description=_ROLE_DESCRIPTIONS.get(role_enum, role_enum.value),
            )
            db.add(role)
            existing[role_enum.value] = role
            logger.info("seeded role %s", role_enum.value)
    db.flush()
    return existing


def seed_org_settings(db: Session) -> None:
    """Ensure the singleton organization settings row exists (idempotent)."""
    existing = db.get(OrgSettings, 1) or db.execute(
        select(OrgSettings)
    ).scalars().first()
    if existing is None:
        db.add(OrgSettings(id=1))
        db.flush()
        logger.info("seeded default org settings")


def seed_permissions(db: Session, roles: dict[str, Role]) -> None:
    """Populate the role/page permission matrix with static defaults.

    Idempotent: inserts a :class:`RolePagePermission` for every (role, page) pair
    using :func:`default_level_for`, but only when no row exists for that pair.
    Existing (possibly admin-customized) rows are never overwritten.
    """
    existing: set[tuple[int, str]] = {
        (rp.role_id, rp.page_key)
        for rp in db.execute(select(RolePagePermission)).scalars().all()
    }
    added = 0
    for role_name, role in roles.items():
        try:
            role_enum = RoleEnum(role_name)
        except ValueError:
            continue
        for page in PAGES:
            key = page["key"]
            if (role.id, key) in existing:
                continue
            db.add(
                RolePagePermission(
                    role_id=role.id,
                    page_key=key,
                    level=default_level_for(role_enum, key),
                )
            )
            added += 1
    if added:
        db.flush()
        logger.info("seeded %d role/page permission defaults", added)


def seed_admin(db: Session, roles: dict[str, Role]) -> User | None:
    # ADMIN_AUTO_CREATE is the explicit opt-in (default True). When set, we
    # create-or-resync the bootstrap admin in ANY environment so the configured
    # ADMIN_EMAIL / ADMIN_PASSWORD always work. Set ADMIN_AUTO_CREATE=false for a
    # hardened production deployment where admins are provisioned another way.
    if not settings.ADMIN_AUTO_CREATE:
        logger.info("ADMIN_AUTO_CREATE disabled; skipping admin bootstrap.")
        return None
    if settings.is_production:
        logger.warning(
            "ENVIRONMENT=production with ADMIN_AUTO_CREATE enabled — provisioning "
            "the bootstrap admin anyway; disable ADMIN_AUTO_CREATE to harden."
        )

    email = settings.ADMIN_EMAIL.lower()
    admin = db.execute(select(User).where(User.email == email)).scalar_one_or_none()
    if admin:
        # Keep the admin login usable by re-syncing the password to the
        # configured value, re-activating, and ensuring the admin role — so
        # changing ADMIN_PASSWORD and redeploying always lets you sign in.
        admin.hashed_password = hash_password(settings.ADMIN_PASSWORD)
        admin.is_active = True
        admin_role = roles[RoleEnum.ADMIN.value]
        if admin_role not in admin.roles:
            admin.roles = [*admin.roles, admin_role]
        db.flush()
        logger.info("re-synced admin user %s password", email)
        return admin
    admin = User(
        email=email,
        full_name="System Administrator",
        hashed_password=hash_password(settings.ADMIN_PASSWORD),
        is_active=True,
    )
    admin.roles = [roles[RoleEnum.ADMIN.value]]
    db.add(admin)
    db.flush()
    logger.info("seeded admin user %s", email)
    return admin


def seed_demo(db: Session, admin: User | None) -> None:
    actor_id = admin.id if admin else None

    if not db.execute(select(Supplier)).first():
        suppliers = [
            Supplier(
                supplier_code="SUP-2026-0001",
                name="Aero Precision Machining LLC",
                status=SupplierStatus.APPROVED,
                cage_code="1AB23",
                certification="AS9100D",
                cert_expiry=date.today() + timedelta(days=300),
                country="USA",
                created_by=actor_id,
                updated_by=actor_id,
            ),
            Supplier(
                supplier_code="SUP-2026-0002",
                name="TitaniumWorks Inc.",
                status=SupplierStatus.CONDITIONAL,
                cage_code="4CD56",
                certification="ISO9001",
                country="USA",
                created_by=actor_id,
                updated_by=actor_id,
            ),
        ]
        db.add_all(suppliers)
        logger.info("seeded demo suppliers")

    if not db.execute(select(Document)).first():
        db.add(
            Document(
                document_number="DOC-2026-0001",
                title="Quality Policy",
                doc_type=DocumentType.POLICY,
                status=DocumentStatus.APPROVED,
                department=Department.QUAL,
                version="1.0",
                current_revision="A",
                effective_date=date.today(),
                last_review_date=date.today(),
                next_review_date=date.today() + timedelta(days=365),
                approved_by=actor_id,
                as9100_clause="5.2",
                purpose=(
                    "Establish the organization's commitment to quality and "
                    "continual improvement of the QMS."
                ),
                scope="Applies to all departments and processes within the QMS.",
                definitions="QMS — Quality Management System.",
                responsibilities=(
                    "Executive management owns this policy; all personnel are "
                    "responsible for adhering to it."
                ),
                detail=(
                    "We are committed to meeting customer and regulatory "
                    "requirements and to the continual improvement of our "
                    "processes and products."
                ),
                revision_history="Rev A — Initial release.",
                appendix="None.",
                created_by=actor_id,
                updated_by=actor_id,
            )
        )
        logger.info("seeded demo document")

    if not db.execute(select(Nonconformance)).first():
        db.add(
            Nonconformance(
                ncr_number="NCR-2026-0001",
                title="Out-of-tolerance bore diameter",
                description="Bore measured 0.502 in vs spec 0.500 ±0.001.",
                severity=NcSeverity.MAJOR,
                status=NcStatus.OPEN,
                part_number="PN-12345",
                quantity_affected=12,
                source="in-process",
                detected_at=date.today(),
                created_by=actor_id,
                updated_by=actor_id,
            )
        )
        logger.info("seeded demo NCR")

    if not db.execute(select(Capa)).first():
        db.add(
            Capa(
                capa_number="CAPA-2026-0001",
                title="Recurring bore oversize on CNC cell 3",
                status=CapaStatus.ROOT_CAUSE,
                d2_problem_description="Multiple bores oversize across lots on cell 3.",
                d3_containment="100% inspection of in-process and finished bores.",
                d4_root_cause="Tool offset drift due to missing periodic re-zero.",
                root_cause_method="5why",
                created_by=actor_id,
                updated_by=actor_id,
            )
        )
        logger.info("seeded demo CAPA")

    if not db.execute(select(Equipment)).first():
        db.add(
            Equipment(
                asset_tag="GAGE-2026-0001",
                name="Mitutoyo Micrometer 0-1in",
                equipment_type="micrometer",
                calibration_interval_days=365,
                last_calibration_date=date.today() - timedelta(days=350),
                next_due_date=date.today() + timedelta(days=15),
                created_by=actor_id,
                updated_by=actor_id,
            )
        )
        logger.info("seeded demo equipment")


def run() -> None:
    configure_logging()
    # Create tables when running against a fresh database without migrations
    # (e.g. local/dev or tests). In production, Alembic owns the schema.
    Base.metadata.create_all(bind=engine)

    with SessionLocal() as db:
        # Commit the essentials (roles + admin) FIRST and on their own, so a
        # problem seeding optional demo data can never roll back the admin
        # account and lock everyone out.
        roles = seed_roles(db)
        admin = seed_admin(db, roles)
        seed_permissions(db, roles)
        seed_org_settings(db)
        db.commit()

        try:
            seed_demo(db, admin)
            db.commit()
        except Exception:  # noqa: BLE001 - demo data is best-effort
            db.rollback()
            logger.exception("demo data seeding failed (non-fatal); continuing")
    logger.info("seed complete")


if __name__ == "__main__":
    run()
