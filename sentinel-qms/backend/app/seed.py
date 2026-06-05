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
from app.core.rbac import ROLE_PERMISSIONS, Role as RoleEnum
from app.core.security import hash_password
from app.models import (  # noqa: F401 - ensure metadata is populated
    Capa,
    CapaStatus,
    Document,
    DocumentStatus,
    DocumentType,
    Equipment,
    NcSeverity,
    NcStatus,
    Nonconformance,
    Role,
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


def seed_admin(db: Session, roles: dict[str, Role]) -> User | None:
    if not settings.ADMIN_AUTO_CREATE:
        logger.info("ADMIN_AUTO_CREATE disabled; skipping admin bootstrap.")
        return None
    if settings.is_production:
        logger.warning("Refusing to auto-create admin in production environment.")
        return None

    email = settings.ADMIN_EMAIL.lower()
    admin = db.execute(select(User).where(User.email == email)).scalar_one_or_none()
    if admin:
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
                title="Quality Manual",
                doc_type=DocumentType.QUALITY_MANUAL,
                status=DocumentStatus.EFFECTIVE,
                current_revision="A",
                effective_date=date.today(),
                as9100_clause="4.4",
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
        roles = seed_roles(db)
        admin = seed_admin(db, roles)
        seed_demo(db, admin)
        db.commit()
    logger.info("seed complete")


if __name__ == "__main__":
    run()
