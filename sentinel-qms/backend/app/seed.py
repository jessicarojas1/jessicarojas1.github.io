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
from app.core.database import Base, SessionLocal, engine
from app.core.logging import configure_logging
from app.core.pages import PAGES
from app.core.permissions import default_level_for
from app.core.rbac import ROLE_PERMISSIONS
from app.core.rbac import Role as RoleEnum
from app.core.security import hash_password
from app.models import (  # noqa: F401 - ensure metadata is populated
    Capa,
    CapaStatus,
    CoverageStatus,
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
    Standard,
    StandardRequirement,
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
    RoleEnum.CUSTOMER: "External stakeholder; sees only records shared with them.",
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
    existing = db.get(OrgSettings, 1) or db.execute(select(OrgSettings)).scalars().first()
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
        (rp.role_id, rp.page_key) for rp in db.execute(select(RolePagePermission)).scalars().all()
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
    if not settings.ADMIN_EMAIL or not settings.ADMIN_PASSWORD:
        logger.warning(
            "ADMIN_AUTO_CREATE enabled but ADMIN_EMAIL/ADMIN_PASSWORD are not set; "
            "skipping admin bootstrap. Configure them in the environment."
        )
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


# Standards seed: (code, name, description, [(clause, title, module_key, status), ...]).
_C = CoverageStatus
_STANDARDS_SEED = [
    (
        "AS9100D",
        "AS9100 Rev D — Aviation, Space & Defense QMS",
        "SAE AS9100D quality management system requirements for aerospace.",
        [
            ("4", "Context of the organization", "documents", _C.COVERED),
            ("5", "Leadership", "mgmt_reviews", _C.COVERED),
            ("6.1", "Actions to address risks and opportunities", "risks", _C.COVERED),
            (
                "7.1.5",
                "Monitoring and measuring resources (calibration)",
                "calibration",
                _C.COVERED,
            ),
            ("7.2", "Competence (training)", "training", _C.COVERED),
            ("7.5", "Documented information", "documents", _C.COVERED),
            ("8.1.4", "Prevention of counterfeit parts", None, _C.GAP),
            ("8.4", "Control of externally provided processes/products", "suppliers", _C.COVERED),
            ("8.5.1.3", "Production process verification (FAI)", "inspections", _C.COVERED),
            ("8.7", "Control of nonconforming outputs", "nonconformances", _C.COVERED),
            ("9.2", "Internal audit", "audits", _C.COVERED),
            ("9.3", "Management review", "mgmt_reviews", _C.COVERED),
            ("10.2", "Nonconformity and corrective action", "capa", _C.COVERED),
        ],
    ),
    (
        "ISO9001",
        "ISO 9001:2015 — Quality Management Systems",
        "Baseline QMS requirements; AS9100 is built on this.",
        [
            ("7.1.5", "Monitoring and measuring resources", "calibration", _C.COVERED),
            ("8.4", "Control of externally provided processes", "suppliers", _C.COVERED),
            ("8.7", "Control of nonconforming outputs", "nonconformances", _C.COVERED),
            ("9.2", "Internal audit", "audits", _C.COVERED),
            ("9.3", "Management review", "mgmt_reviews", _C.COVERED),
            ("10.2", "Nonconformity and corrective action", "capa", _C.COVERED),
        ],
    ),
    (
        "NADCAP",
        "NADCAP — Special Process Accreditation",
        "Supplier accreditation for special processes (heat treat, NDT, welding, chem).",
        [
            ("AC7004", "Quality system for special processors", "suppliers", _C.PARTIAL),
            ("Process audits", "Special-process audit coverage", "audits", _C.PARTIAL),
            ("Accreditation tracking", "Supplier NADCAP accreditation status", "suppliers", _C.GAP),
        ],
    ),
    (
        "NIST800-171",
        "NIST SP 800-171 — Protecting CUI",
        "Safeguarding Controlled Unclassified Information (pairs with CMMC).",
        [
            ("3.1", "Access control", None, _C.GAP),
            ("3.3", "Audit and accountability", None, _C.PARTIAL),
            ("3.12", "Security assessment", None, _C.GAP),
        ],
    ),
    (
        "AS9145",
        "AS9145 — APQP & PPAP",
        "Advanced Product Quality Planning and Production Part Approval Process.",
        [
            ("Phase 1-2", "Planning & product design", "changes", _C.GAP),
            ("Phase 3", "Process design & development", None, _C.GAP),
            ("PPAP", "Production part approval package", "inspections", _C.PARTIAL),
            ("Control plan", "Control plan & PFMEA", "risks", _C.GAP),
        ],
    ),
]


def seed_standards(db: Session) -> None:
    """Seed the standards-coverage matrix (idempotent — skips existing codes)."""
    existing = {code for (code,) in db.execute(select(Standard.code)).all()}
    for code, name, description, reqs in _STANDARDS_SEED:
        if code in existing:
            continue
        std = Standard(code=code, name=name, description=description)
        db.add(std)
        db.flush()
        for clause, title, module_key, status in reqs:
            db.add(
                StandardRequirement(
                    standard_id=std.id,
                    clause=clause,
                    title=title,
                    module_key=module_key,
                    coverage_status=status,
                )
            )


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
        seed_standards(db)
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
