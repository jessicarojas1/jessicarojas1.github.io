"""Per-record (entity) view authorization for record-satellite endpoints.

Attachments, comments, e-signatures and the audit trail are *satellite* data
hanging off a primary record (an NCR, CAPA, supplier, …). Reading or writing
them must require the same module-view access as the record itself — otherwise
any authenticated principal (including the read-only ``Customer`` role, which
holds no module permissions) could enumerate another record's files, comments,
21 CFR Part 11 signatures or audit history just by guessing ``entity_type`` /
``entity_id``.

This mirrors the delegation check already used for record sharing
(:data:`app.api.routers.record_shares._SHARE_VIEW_PAGE`), generalized to every
record type that exposes satellite data. Unmapped types fail closed.
"""

from __future__ import annotations

from sqlalchemy.orm import Session

from app.core.exceptions import PermissionDeniedError
from app.core.permissions import effective_levels, level_at_least

# entity_type -> the page key whose "view" level authorizes reading that record's
# satellite data. Covers every entity_type used by RecordSupplements / the
# attachment, comment, signature and audit-trail panels across the SPA.
ENTITY_VIEW_PAGE: dict[str, str] = {
    "nonconformance": "nonconformances",
    "capa": "capa",
    "concession": "nonconformances",
    "complaint": "complaints",
    "risk": "risks",
    "document": "documents",
    "document_revision": "documents",
    "change_order": "changes",
    "change": "changes",
    "audit": "audits",
    "audit_finding": "audits",
    "inspection": "inspections",
    "fai_report": "inspections",
    "apqp_project": "inspections",
    "key_characteristic": "inspections",
    "fod_event": "inspections",
    "supplier": "suppliers",
    "supplier_scar": "suppliers",
    "supplier_rating": "suppliers",
    "scar": "suppliers",
    "asl_entry": "suppliers",
    "counterfeit_sourcing": "suppliers",
    "counterfeit_alert": "suppliers",
    "equipment": "calibration",
    "calibration": "calibration",
    "training_record": "training",
    "training_course": "training",
    "personnel": "training",
    "management_review": "mgmt_reviews",
    "mgmt_review": "mgmt_reviews",
}


def require_entity_view(db: Session, actor, entity_type: str) -> None:  # noqa: ANN001
    """Raise :class:`PermissionDeniedError` unless ``actor`` may view the module
    that owns ``entity_type``. Fail-closed for unknown entity types."""
    page_key = ENTITY_VIEW_PAGE.get(entity_type)
    if page_key is None:
        raise PermissionDeniedError(
            f"Records of type '{entity_type}' are not accessible."
        )
    levels = effective_levels(db, actor)
    if not level_at_least(levels.get(page_key, "none"), "view"):
        raise PermissionDeniedError("You do not have permission to view this record.")
