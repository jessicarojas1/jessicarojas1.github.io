"""Branded PDF generation for QMS records and the quality digest.

Pure-Python via :mod:`fpdf` (fpdf2). Every document carries the organization's
branding — logo, display name, and accent color drawn from the
:class:`OrgSettings` singleton — in a header band, with a paginated footer.

Branding degrades gracefully: a missing/broken/SVG logo falls back to the
text-only header, and a bad accent color falls back to the default brand blue,
so a document is always produced.
"""
from __future__ import annotations

import base64
import binascii
import logging
import urllib.request
from datetime import UTC, datetime
from io import BytesIO

from fpdf import FPDF
from sqlalchemy.orm import Session

from app.models.audit_mgmt import Audit
from app.models.capa import Capa
from app.models.complaint import Complaint
from app.models.nonconformance import Nonconformance
from app.models.settings import OrgSettings
from app.models.supplier import ScarStatus, Supplier
from app.models.user import User
from app.services import kpi

logger = logging.getLogger("app.pdf")

# Default brand blue, used when no/invalid accent color is configured.
_DEFAULT_ACCENT = (37, 99, 235)  # #2563eb
_INK = (31, 41, 55)  # body text
_MUTED = (107, 114, 128)  # labels / footer
_LOGO_FETCH_TIMEOUT = 5  # seconds
_LOGO_MAX_BYTES = 2 * 1024 * 1024  # never embed an absurdly large logo


def _hex_to_rgb(value: str | None) -> tuple[int, int, int]:
    """Parse ``#rgb`` / ``#rrggbb`` into an RGB tuple, else the default accent."""
    if not value:
        return _DEFAULT_ACCENT
    v = value.strip().lstrip("#")
    if len(v) == 3:
        v = "".join(ch * 2 for ch in v)
    if len(v) != 6:
        return _DEFAULT_ACCENT
    try:
        return (int(v[0:2], 16), int(v[2:4], 16), int(v[4:6], 16))
    except ValueError:
        return _DEFAULT_ACCENT


def _safe(text: object) -> str:
    """Coerce to a string the core PDF fonts (Latin-1) can always render."""
    s = "" if text is None else str(text)
    return s.encode("latin-1", "replace").decode("latin-1")


def _load_logo(logo_url: str | None) -> BytesIO | None:
    """Return a normalized PNG :class:`BytesIO` for the logo, or ``None``.

    Accepts ``data:image/...;base64,`` URIs and ``http(s)://`` URLs. SVG and any
    fetch/decode failure return ``None`` so the header degrades to text-only.
    """
    if not logo_url:
        return None
    url = logo_url.strip()
    raw: bytes | None = None
    try:
        if url.lower().startswith("data:image/"):
            if "svg" in url.split(",", 1)[0].lower():
                return None  # vector logos are not embeddable here
            header, _, payload = url.partition(",")
            raw = base64.b64decode(payload) if ";base64" in header else payload.encode()
        elif url.lower().startswith(("http://", "https://")):
            req = urllib.request.Request(url, headers={"User-Agent": "Sentinel-QMS"})
            with urllib.request.urlopen(req, timeout=_LOGO_FETCH_TIMEOUT) as resp:  # noqa: S310
                raw = resp.read(_LOGO_MAX_BYTES + 1)
        else:
            return None
    except (OSError, ValueError, binascii.Error):
        logger.warning("logo load failed", exc_info=True)
        return None

    if not raw or len(raw) > _LOGO_MAX_BYTES:
        return None
    # Normalize through Pillow to a flattened RGB PNG (handles webp/gif/alpha).
    try:
        from PIL import Image

        img = Image.open(BytesIO(raw))
        if img.mode in ("RGBA", "LA", "P"):
            img = img.convert("RGBA")
            bg = Image.new("RGBA", img.size, (255, 255, 255, 255))
            img = Image.alpha_composite(bg, img).convert("RGB")
        else:
            img = img.convert("RGB")
        out = BytesIO()
        img.save(out, format="PNG")
        out.seek(0)
        return out
    except Exception:  # noqa: BLE001 — any decode issue => no logo
        logger.warning("logo decode failed", exc_info=True)
        return None


class _BrandedPDF(FPDF):
    """FPDF subclass that renders a branded header band and paginated footer."""

    def __init__(self, *, org_name: str, title: str, subtitle: str,
                 accent: tuple[int, int, int], logo: BytesIO | None) -> None:
        super().__init__(orientation="P", unit="mm", format="A4")
        self._org_name = _safe(org_name)
        self._title = _safe(title)
        self._subtitle = _safe(subtitle)
        self._accent = accent
        self._logo = logo
        self.set_auto_page_break(auto=True, margin=20)
        self.set_title(self._title)

    def header(self) -> None:  # noqa: D401 — fpdf hook
        r, g, b = self._accent
        self.set_fill_color(r, g, b)
        self.rect(0, 0, self.w, 26, style="F")

        text_x = 12.0
        if self._logo is not None:
            try:
                self.image(self._logo, x=12, y=5, h=16)
                text_x = 34.0
                self._logo.seek(0)
            except Exception:  # noqa: BLE001 — bad image => skip, keep text header
                text_x = 12.0

        self.set_xy(text_x, 6)
        self.set_text_color(255, 255, 255)
        self.set_font("Helvetica", "B", 14)
        self.cell(0, 7, self._org_name, new_x="LMARGIN", new_y="NEXT")
        self.set_xy(text_x, 14)
        self.set_font("Helvetica", "", 10)
        self.cell(0, 6, self._title, new_x="LMARGIN", new_y="NEXT")

        self.set_y(32)
        if self._subtitle:
            self.set_text_color(*_MUTED)
            self.set_font("Helvetica", "", 9)
            self.cell(0, 5, self._subtitle, new_x="LMARGIN", new_y="NEXT")
        self.ln(2)
        self.set_text_color(*_INK)

    def footer(self) -> None:  # noqa: D401 — fpdf hook
        self.set_y(-15)
        self.set_text_color(*_MUTED)
        self.set_font("Helvetica", "", 8)
        stamp = datetime.now(UTC).strftime("%Y-%m-%d %H:%M UTC")
        self.cell(0, 5, _safe(f"{self._org_name} - Confidential"), align="L")
        self.set_y(-15)
        self.cell(0, 5, _safe(f"Generated {stamp}"), align="C")
        self.set_y(-15)
        self.cell(0, 5, f"Page {self.page_no()}/{{nb}}", align="R")


# ── Layout primitives ───────────────────────────────────────────────────────

def _section(pdf: _BrandedPDF, label: str) -> None:
    pdf.ln(1)
    r, g, b = pdf._accent
    pdf.set_text_color(r, g, b)
    pdf.set_font("Helvetica", "B", 11)
    pdf.cell(0, 7, _safe(label), new_x="LMARGIN", new_y="NEXT")
    pdf.set_draw_color(r, g, b)
    pdf.set_line_width(0.4)
    y = pdf.get_y()
    pdf.line(pdf.l_margin, y, pdf.w - pdf.r_margin, y)
    pdf.ln(2)
    pdf.set_text_color(*_INK)


def _kv(pdf: _BrandedPDF, label: str, value: object) -> None:
    """Render a label/value row (label fixed width, value wraps)."""
    pdf.set_font("Helvetica", "B", 9)
    pdf.set_text_color(*_MUTED)
    pdf.cell(45, 6, _safe(label), new_x="RIGHT", new_y="TOP")
    pdf.set_font("Helvetica", "", 9)
    pdf.set_text_color(*_INK)
    pdf.multi_cell(0, 6, _safe(value if value not in (None, "") else "-"),
                   new_x="LMARGIN", new_y="NEXT")


def _paragraph(pdf: _BrandedPDF, label: str, value: object) -> None:
    pdf.set_font("Helvetica", "B", 9)
    pdf.set_text_color(*_MUTED)
    pdf.cell(0, 6, _safe(label), new_x="LMARGIN", new_y="NEXT")
    pdf.set_font("Helvetica", "", 9)
    pdf.set_text_color(*_INK)
    pdf.multi_cell(0, 5, _safe(value if value not in (None, "") else "-"),
                   new_x="LMARGIN", new_y="NEXT")
    pdf.ln(1)


def _enum(value: object) -> str:
    if value is None:
        return "-"
    raw = value.value if hasattr(value, "value") else str(value)
    return raw.replace("_", " ").title()


def _date(value: object) -> str:
    if value is None:
        return "-"
    return value.strftime("%Y-%m-%d") if hasattr(value, "strftime") else str(value)


# ── Shared branding context ─────────────────────────────────────────────────

def _get_settings(db: Session) -> OrgSettings | None:
    org = db.get(OrgSettings, 1)
    if org is None:
        org = db.query(OrgSettings).order_by(OrgSettings.id.asc()).first()
    return org


def _branding(db: Session) -> tuple[str, tuple[int, int, int], BytesIO | None]:
    org = _get_settings(db)
    name = (org.organization_name if org else None) or "Sentinel QMS"
    accent = _hex_to_rgb(org.primary_color if org else None)
    logo = _load_logo(org.logo_url if org else None)
    return name, accent, logo


def _user_name(db: Session, user_id: int | None) -> str:
    if user_id is None:
        return "-"
    u = db.get(User, user_id)
    return u.full_name if u is not None else f"User #{user_id}"


def _new_pdf(db: Session, *, title: str, subtitle: str) -> _BrandedPDF:
    name, accent, logo = _branding(db)
    pdf = _BrandedPDF(org_name=name, title=title, subtitle=subtitle,
                      accent=accent, logo=logo)
    pdf.alias_nb_pages()
    pdf.add_page()
    return pdf


def _output(pdf: _BrandedPDF) -> bytes:
    return bytes(pdf.output())


# ── Document renderers ──────────────────────────────────────────────────────

def render_ncr_pdf(db: Session, ncr: Nonconformance) -> bytes:
    """Render a single Nonconformance (NCR) record as a branded PDF."""
    pdf = _new_pdf(
        db,
        title=f"Nonconformance {ncr.ncr_number}",
        subtitle=ncr.title or "",
    )

    _section(pdf, "Summary")
    _kv(pdf, "NCR Number", ncr.ncr_number)
    _kv(pdf, "Status", _enum(ncr.status))
    _kv(pdf, "Severity", _enum(ncr.severity))
    _kv(pdf, "Source", ncr.source)
    _kv(pdf, "Detected", _date(ncr.detected_at))
    _kv(pdf, "Assigned To", _user_name(db, ncr.assigned_to))

    _section(pdf, "Affected Material")
    _kv(pdf, "Part Number", ncr.part_number)
    _kv(pdf, "Lot Number", ncr.lot_number)
    _kv(pdf, "Serial Number", ncr.serial_number)
    _kv(pdf, "Quantity", ncr.quantity_affected)
    _kv(pdf, "Work Order", ncr.work_order)
    if ncr.estimated_cost is not None:
        _kv(pdf, "Estimated Cost", f"{ncr.estimated_cost}")

    _section(pdf, "Description")
    pdf.set_font("Helvetica", "", 9)
    pdf.multi_cell(0, 5, _safe(ncr.description or "-"), new_x="LMARGIN", new_y="NEXT")

    dispositions = list(getattr(ncr, "dispositions", []) or [])
    if dispositions:
        _section(pdf, "MRB Dispositions")
        for d in dispositions:
            _kv(pdf, "Disposition", _enum(d.disposition_type))
            _paragraph(pdf, "Justification", d.justification)
            _kv(pdf, "Decided By", _user_name(db, d.decided_by))
            _kv(
                pdf,
                "Customer Approval",
                "Approved" if d.customer_approved
                else ("Required" if d.customer_approval_required else "N/A"),
            )
            pdf.ln(2)

    return _output(pdf)


def render_capa_pdf(db: Session, capa: Capa) -> bytes:
    """Render a single CAPA (8D) record as a branded PDF."""
    pdf = _new_pdf(
        db,
        title=f"CAPA {capa.capa_number}",
        subtitle=capa.title or "",
    )

    _section(pdf, "Summary")
    _kv(pdf, "CAPA Number", capa.capa_number)
    _kv(pdf, "Type", _enum(capa.capa_type))
    _kv(pdf, "Status", _enum(capa.status))
    _kv(pdf, "Owner", _user_name(db, capa.owner_id))
    _kv(pdf, "Due Date", _date(capa.due_date))
    _kv(pdf, "Effectiveness Verified", "Yes" if capa.effectiveness_verified else "No")

    _section(pdf, "8D Problem Solving")
    steps = [
        ("D1 - Team", capa.d1_team),
        ("D2 - Problem Description", capa.d2_problem_description),
        ("D3 - Interim Containment", capa.d3_containment),
        ("D4 - Root Cause", capa.d4_root_cause),
        ("D5 - Corrective Action", capa.d5_corrective_action),
        ("D6 - Implement & Validate", capa.d6_implementation),
        ("D7 - Prevent Recurrence", capa.d7_preventive_action),
        ("D8 - Closure", capa.d8_closure),
    ]
    for label, value in steps:
        _paragraph(pdf, label, value)

    actions = list(getattr(capa, "actions", []) or [])
    if actions:
        _section(pdf, "Action Items")
        for a in actions:
            _paragraph(pdf, f"[{_enum(a.status)}] {a.action_kind}", a.description)
            _kv(pdf, "Owner", _user_name(db, a.owner_id))
            _kv(pdf, "Due", _date(a.due_date))
            pdf.ln(1)

    return _output(pdf)


def render_audit_pdf(db: Session, audit: Audit) -> bytes:
    """Render a single Audit record (with findings) as a branded PDF."""
    pdf = _new_pdf(
        db,
        title=f"Audit {audit.audit_number}",
        subtitle=audit.title or "",
    )

    _section(pdf, "Summary")
    _kv(pdf, "Audit Number", audit.audit_number)
    _kv(pdf, "Type", _enum(audit.audit_type))
    _kv(pdf, "Status", _enum(audit.status))
    _kv(pdf, "Standard", audit.standard)
    _kv(pdf, "Lead Auditor", _user_name(db, audit.lead_auditor_id))
    _kv(pdf, "Auditee Area", audit.auditee_area)
    _kv(pdf, "Planned Date", _date(audit.planned_date))
    _kv(pdf, "Actual Date", _date(audit.actual_date))

    if audit.scope:
        _section(pdf, "Scope")
        pdf.set_font("Helvetica", "", 9)
        pdf.multi_cell(0, 5, _safe(audit.scope), new_x="LMARGIN", new_y="NEXT")

    findings = list(getattr(audit, "findings", []) or [])
    if findings:
        _section(pdf, f"Findings ({len(findings)})")
        for f in findings:
            _paragraph(
                pdf,
                f"{f.finding_number} - {_enum(f.finding_type)} [{_enum(f.status)}]",
                f.description,
            )
            if f.clause_reference:
                _kv(pdf, "Clause", f.clause_reference)
            if f.evidence:
                _kv(pdf, "Evidence", f.evidence)
            _kv(pdf, "Response Due", _date(f.response_due_date))
            pdf.ln(1)

    return _output(pdf)


def render_supplier_pdf(db: Session, supplier: Supplier) -> bytes:
    """Render a single Supplier scorecard (profile, latest rating, open SCARs)."""
    pdf = _new_pdf(
        db,
        title=f"Supplier {supplier.supplier_code}",
        subtitle=supplier.name or "",
    )

    _section(pdf, "Profile")
    _kv(pdf, "Supplier Code", supplier.supplier_code)
    _kv(pdf, "Name", supplier.name)
    _kv(pdf, "Status", _enum(supplier.status))
    _kv(pdf, "CAGE Code", supplier.cage_code)
    _kv(pdf, "Certification", supplier.certification)
    _kv(pdf, "Cert Expiry", _date(supplier.cert_expiry))
    _kv(pdf, "Country", supplier.country)
    _kv(pdf, "Contact", supplier.contact_name)
    _kv(pdf, "Contact Email", supplier.contact_email)

    ratings = list(getattr(supplier, "ratings", []) or [])
    if ratings:
        latest = max(ratings, key=lambda r: (r.period or "", r.id))
        _section(pdf, f"Latest Rating ({latest.period or '-'})")
        _kv(pdf, "Quality Score", latest.quality_score)
        _kv(pdf, "On-Time Delivery", latest.on_time_delivery)
        _kv(pdf, "PPM Defects", latest.ppm_defects)
        _kv(pdf, "Composite Score", latest.composite_score)
        _kv(pdf, "Grade", latest.grade)

    scars = [s for s in getattr(supplier, "scars", []) or [] if s.status != ScarStatus.CLOSED]
    if scars:
        _section(pdf, f"Open SCARs ({len(scars)})")
        for s in scars:
            _paragraph(pdf, f"{s.scar_number} [{_enum(s.status)}] - {s.title}", s.description)
            _kv(pdf, "Response Due", _date(s.response_due_date))
            pdf.ln(1)

    return _output(pdf)


def render_complaint_pdf(db: Session, complaint: Complaint) -> bytes:
    """Render a single customer Complaint record as a branded PDF."""
    pdf = _new_pdf(
        db,
        title=f"Complaint {complaint.complaint_number}",
        subtitle=complaint.title or "",
    )

    _section(pdf, "Summary")
    _kv(pdf, "Complaint Number", complaint.complaint_number)
    _kv(pdf, "Status", _enum(complaint.status))
    _kv(pdf, "Severity", _enum(complaint.severity))
    _kv(pdf, "Customer", complaint.customer_name)
    _kv(pdf, "Customer Contact", complaint.customer_contact)
    _kv(pdf, "Part Number", complaint.part_number)
    _kv(pdf, "Serial Number", complaint.serial_number)
    _kv(pdf, "RMA", complaint.rma_number if complaint.is_rma else "No")
    _kv(pdf, "Received", _date(complaint.received_date))
    _kv(pdf, "Response Due", _date(complaint.response_due_date))
    _kv(pdf, "Assigned To", _user_name(db, complaint.assigned_to))

    _section(pdf, "Description")
    pdf.set_font("Helvetica", "", 9)
    pdf.multi_cell(0, 5, _safe(complaint.description or "-"), new_x="LMARGIN", new_y="NEXT")

    if complaint.resolution:
        _section(pdf, "Resolution")
        pdf.set_font("Helvetica", "", 9)
        pdf.multi_cell(0, 5, _safe(complaint.resolution), new_x="LMARGIN", new_y="NEXT")

    return _output(pdf)


def render_digest_pdf(db: Session, *, now: datetime | None = None) -> bytes:
    """Render the organization-wide quality digest as a branded PDF."""
    stamp = (now or datetime.now(UTC)).strftime("%Y-%m-%d")
    pdf = _new_pdf(db, title="Quality Digest", subtitle=f"Snapshot as of {stamp}")
    k = kpi.dashboard_kpis(db)

    _section(pdf, "Open Items")
    _kv(pdf, "Open NCRs", k.get("open_ncrs", 0))
    _kv(pdf, "Open CAPAs", k.get("open_capas", 0))
    _kv(pdf, "Overdue CAPAs", k.get("overdue_capas", 0))
    _kv(pdf, "Open Audits", k.get("open_audits", 0))
    _kv(pdf, "Open Complaints", k.get("open_complaints", 0))

    _section(pdf, "Calibration")
    _kv(pdf, "Due (next 30 days)", k.get("calibration_due", 0))
    _kv(pdf, "Overdue", k.get("calibration_overdue", 0))

    _section(pdf, "Suppliers")
    _kv(pdf, "Average Quality Rating", k.get("supplier_avg_rating", 0))

    return _output(pdf)
