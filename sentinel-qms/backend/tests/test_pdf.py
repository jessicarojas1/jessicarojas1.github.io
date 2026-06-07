"""Branded PDF rendering tests (service-level, no HTTP)."""
from __future__ import annotations

import base64
from io import BytesIO

from app.models.capa import Capa, CapaStatus, CapaType
from app.models.nonconformance import NcSeverity, NcStatus, Nonconformance
from app.models.settings import OrgSettings
from app.services import pdf


def _png_data_url() -> str:
    from PIL import Image

    buf = BytesIO()
    Image.new("RGB", (32, 32), (10, 120, 110)).save(buf, format="PNG")
    return "data:image/png;base64," + base64.b64encode(buf.getvalue()).decode()


def _settings(db, **overrides):
    org = OrgSettings(id=1, organization_name="Acme QMS")
    for k, v in overrides.items():
        setattr(org, k, v)
    db.add(org)
    db.commit()
    return org


def _ncr(db, **overrides):
    ncr = Nonconformance(
        ncr_number="NCR-0001",
        title="Surface defect",
        description="Scratch at receiving.",
        severity=NcSeverity.MAJOR,
        status=NcStatus.UNDER_REVIEW,
    )
    for k, v in overrides.items():
        setattr(ncr, k, v)
    db.add(ncr)
    db.commit()
    return ncr


def _capa(db, **overrides):
    capa = Capa(
        capa_number="CAPA-0001",
        title="Recurring defect",
        capa_type=CapaType.CORRECTIVE,
        d2_problem_description="Defects across lots.",
        status=CapaStatus.IMPLEMENTATION,
    )
    for k, v in overrides.items():
        setattr(capa, k, v)
    db.add(capa)
    db.commit()
    return capa


def test_ncr_pdf_is_valid_with_branding(db_session, seeded):
    _settings(db_session, primary_color="#0f766e", logo_url=_png_data_url())
    ncr = _ncr(db_session, assigned_to=seeded["users"]["engineer"].id)
    out = pdf.render_ncr_pdf(db_session, ncr)
    assert out[:5] == b"%PDF-"
    assert len(out) > 800


def test_capa_pdf_is_valid(db_session, seeded):
    _settings(db_session)
    capa = _capa(db_session, owner_id=seeded["users"]["engineer"].id)
    out = pdf.render_capa_pdf(db_session, capa)
    assert out[:5] == b"%PDF-"
    assert len(out) > 800


def test_digest_pdf_is_valid(db_session, seeded):
    _settings(db_session)
    out = pdf.render_digest_pdf(db_session)
    assert out[:5] == b"%PDF-"


def test_audit_pdf_is_valid(db_session, seeded):
    from app.models.audit_mgmt import Audit, AuditStatus, AuditType

    _settings(db_session)
    audit = Audit(
        audit_number="AUD-0001",
        title="Internal AS9100 audit",
        audit_type=AuditType.INTERNAL,
        status=AuditStatus.PLANNED,
        standard="AS9100D",
        lead_auditor_id=seeded["users"]["engineer"].id,
    )
    db_session.add(audit)
    db_session.commit()
    out = pdf.render_audit_pdf(db_session, audit)
    assert out[:5] == b"%PDF-"


def test_supplier_pdf_is_valid(db_session, seeded):
    from app.models.supplier import Supplier, SupplierStatus

    _settings(db_session)
    supplier = Supplier(
        supplier_code="SUP-0001",
        name="Precision Forge Inc.",
        status=SupplierStatus.APPROVED,
        certification="AS9100",
    )
    db_session.add(supplier)
    db_session.commit()
    out = pdf.render_supplier_pdf(db_session, supplier)
    assert out[:5] == b"%PDF-"


def test_complaint_pdf_is_valid(db_session, seeded):
    from app.models.complaint import Complaint, ComplaintSeverity, ComplaintStatus

    _settings(db_session)
    complaint = Complaint(
        complaint_number="CMP-0001",
        title="Late delivery",
        description="Order arrived two weeks late.",
        status=ComplaintStatus.RECEIVED,
        severity=ComplaintSeverity.MEDIUM,
        customer_name="Boeing",
    )
    db_session.add(complaint)
    db_session.commit()
    out = pdf.render_complaint_pdf(db_session, complaint)
    assert out[:5] == b"%PDF-"


def test_pdf_degrades_gracefully_on_bad_branding(db_session, seeded):
    # Unreachable SVG URL + invalid color must not break rendering.
    _settings(db_session, primary_color="not-a-color", logo_url="https://nope.invalid/l.svg")
    ncr = _ncr(db_session)
    out = pdf.render_ncr_pdf(db_session, ncr)
    assert out[:5] == b"%PDF-"


def test_hex_to_rgb_parsing():
    assert pdf._hex_to_rgb("#2563eb") == (37, 99, 235)
    assert pdf._hex_to_rgb("#abc") == (170, 187, 204)
    assert pdf._hex_to_rgb(None) == pdf._DEFAULT_ACCENT
    assert pdf._hex_to_rgb("garbage") == pdf._DEFAULT_ACCENT


def test_enum_humanizes():
    assert pdf._enum(NcStatus.UNDER_REVIEW) == "Under Review"
    assert pdf._enum(None) == "-"
