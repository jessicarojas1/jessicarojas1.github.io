"""
branding.py — Settings → Branding for the Business Insight Dashboard.

Lets the user set:
  - a logo (via URL or file upload, stored as a data: URL so it persists/works offline)
  - the organization / product display name
  - a primary accent / brand color

Branding is persisted to a small JSON file on disk (branding.json next to app.py)
so it survives reruns, and mirrored into st.session_state for live updates.

All user-supplied values are sanitized before being injected into HTML/CSS:
  - logo URLs must be http(s):// or data:image/...
  - the accent must be a valid #RRGGBB / #RGB hex color
  - the display name is HTML-escaped wherever it is rendered as markup
"""

from __future__ import annotations

import base64
import html
import json
import re
from pathlib import Path

import streamlit as st

# ── Defaults (built-in brand mark) ───────────────────────────────────────────
DEFAULT_NAME = "Business Insight Dashboard"
DEFAULT_ACCENT = "#4F8EF7"
DEFAULT_ICON = "📊"
DEFAULT_TAGLINE = "Instant analytics · No code required"

# branding.json lives next to app.py (parent of this modules/ dir)
_BRANDING_FILE = Path(__file__).resolve().parent.parent / "branding.json"

# Allowed mime types for uploaded logos
_ALLOWED_IMAGE_MIME = {
    "png": "image/png",
    "jpg": "image/jpeg",
    "jpeg": "image/jpeg",
    "gif": "image/gif",
    "webp": "image/webp",
    "svg": "image/svg+xml",
}

_HEX_RE = re.compile(r"^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$")
_DATA_IMAGE_RE = re.compile(r"^data:image/[a-zA-Z0-9.+-]+;base64,[A-Za-z0-9+/=\s]+$")
_HTTP_URL_RE = re.compile(r"^https?://[^\s\"'<>]+$", re.IGNORECASE)


# ── Sanitizers / validators ──────────────────────────────────────────────────
def sanitize_logo(value: str | None) -> str:
    """Return the logo URL if it is a safe http(s):// or data:image/... value,
    otherwise an empty string. Never raises."""
    if not value or not isinstance(value, str):
        return ""
    candidate = value.strip()
    if _HTTP_URL_RE.match(candidate) or _DATA_IMAGE_RE.match(candidate):
        return candidate
    return ""


def validate_accent(value: str | None) -> str:
    """Return a valid #hex color, falling back to the default accent. Never raises."""
    if isinstance(value, str):
        candidate = value.strip()
        if _HEX_RE.match(candidate):
            return candidate
    return DEFAULT_ACCENT


def sanitize_name(value: str | None) -> str:
    """Return a trimmed display name (defaulting when blank). Raw text only —
    callers must escape it before injecting into markup."""
    if isinstance(value, str) and value.strip():
        # Keep it reasonable; collapse whitespace, cap length.
        return re.sub(r"\s+", " ", value.strip())[:120]
    return DEFAULT_NAME


def file_to_data_url(uploaded_file) -> str:
    """Convert a Streamlit UploadedFile to a sanitized data: URL, or '' on failure."""
    try:
        name = (getattr(uploaded_file, "name", "") or "").lower()
        ext = name.rsplit(".", 1)[-1] if "." in name else ""
        mime = _ALLOWED_IMAGE_MIME.get(ext)
        if not mime:
            return ""
        raw = uploaded_file.getvalue()
        if not raw:
            return ""
        b64 = base64.b64encode(raw).decode("ascii")
        data_url = f"data:{mime};base64,{b64}"
        # Round-trip through the sanitizer to be safe.
        return sanitize_logo(data_url)
    except Exception:
        return ""


# ── Persistence ──────────────────────────────────────────────────────────────
def _default_branding() -> dict:
    return {"logo": "", "name": DEFAULT_NAME, "accent": DEFAULT_ACCENT}


def load_branding() -> dict:
    """Load branding from disk, sanitizing every field. Always returns a valid dict."""
    data = _default_branding()
    try:
        if _BRANDING_FILE.exists():
            raw = json.loads(_BRANDING_FILE.read_text(encoding="utf-8"))
            if isinstance(raw, dict):
                data["logo"] = sanitize_logo(raw.get("logo"))
                data["name"] = sanitize_name(raw.get("name"))
                data["accent"] = validate_accent(raw.get("accent"))
    except Exception:
        # Corrupt / unreadable file must never crash the app.
        pass
    return data


def save_branding(logo: str, name: str, accent: str) -> dict:
    """Sanitize, persist to disk, and return the stored branding dict."""
    data = {
        "logo": sanitize_logo(logo),
        "name": sanitize_name(name),
        "accent": validate_accent(accent),
    }
    try:
        _BRANDING_FILE.write_text(json.dumps(data, indent=2), encoding="utf-8")
    except Exception:
        # If disk write fails we still keep the in-session copy.
        pass
    return data


def get_branding() -> dict:
    """Return the active branding, loading from disk into session_state once."""
    if "branding" not in st.session_state:
        st.session_state["branding"] = load_branding()
    return st.session_state["branding"]


# ── Live application ─────────────────────────────────────────────────────────
def apply_accent_css(accent: str) -> None:
    """Inject CSS overriding Streamlit's primary color + the app's --accent var."""
    accent = validate_accent(accent)
    st.markdown(
        f"""
        <style>
        :root {{
            --accent: {accent};
            --primary-color: {accent};
        }}
        .kpi-card::before {{ background: {accent}; }}
        .stButton > button[kind="primary"],
        [data-testid="stBaseButton-primary"] {{
            background-color: {accent} !important;
            border-color: {accent} !important;
        }}
        [data-testid="stSidebar"] a {{ color: {accent} !important; }}
        .brand-mark {{ border-bottom: 2px solid {accent}33; }}
        </style>
        """,
        unsafe_allow_html=True,
    )


def render_sidebar_brand(branding: dict) -> None:
    """Render the brand mark (logo + name) at the top of the sidebar.
    A broken/unreachable logo degrades gracefully to the default icon mark."""
    logo = sanitize_logo(branding.get("logo"))
    name = html.escape(sanitize_name(branding.get("name")))

    if logo:
        # st.image handles fetch/decoding; a bad URL is caught and we fall back.
        try:
            st.image(logo, use_container_width=True)
            st.markdown(
                f"""
                <div class="brand-mark" style="padding:2px 0 18px;">
                  <div style="font-size:0.95rem;font-weight:700;color:#F3F4F6;
                              letter-spacing:-0.02em;">{name}</div>
                  <div style="font-size:0.72rem;color:#6B7280;margin-top:2px;">
                    {html.escape(DEFAULT_TAGLINE)}
                  </div>
                </div>
                """,
                unsafe_allow_html=True,
            )
            return
        except Exception:
            pass  # fall through to the default text mark

    st.markdown(
        f"""
        <div class="brand-mark" style="padding:8px 0 20px;">
          <div style="font-size:1.4rem;font-weight:700;color:#F3F4F6;letter-spacing:-0.02em;">
            {DEFAULT_ICON} {name}
          </div>
          <div style="font-size:0.75rem;color:#6B7280;margin-top:2px;">
            {html.escape(DEFAULT_TAGLINE)}
          </div>
        </div>
        """,
        unsafe_allow_html=True,
    )


def render_settings_ui() -> dict:
    """Render the Settings → Branding controls (intended for a sidebar expander).
    Returns the active branding dict. Saving updates disk + session_state live."""
    branding = get_branding()

    st.caption("Customize the logo, name, and accent color. Saved settings persist across reruns.")

    name_in = st.text_input(
        "Organization / product name",
        value=branding.get("name", DEFAULT_NAME),
        max_chars=120,
        help="Shown in the sidebar brand mark, page header, and browser tab title.",
    )

    accent_in = st.color_picker(
        "Primary accent color",
        value=validate_accent(branding.get("accent")),
        help="Applied live across KPI cards, buttons, and links.",
    )

    st.markdown("**Logo**")
    logo_url_in = st.text_input(
        "Logo URL",
        value=branding.get("logo", "") if str(branding.get("logo", "")).startswith("http") else "",
        placeholder="https://example.com/logo.png",
        help="Paste an http(s):// image URL, or upload a file below.",
    )

    uploaded_logo = st.file_uploader(
        "…or upload a logo",
        type=["png", "jpg", "jpeg", "gif", "webp", "svg"],
        help="Stored inline (data URL) so it persists and works offline.",
        key="branding_logo_upload",
    )
    st.caption(
        "Field reference — **Logo URL**: http(s):// or data:image/... · "
        "**Upload**: png / jpg / jpeg / gif / webp / svg (stored as data URL)."
    )

    chosen_logo = sanitize_logo(logo_url_in)
    if uploaded_logo is not None:
        data_url = file_to_data_url(uploaded_logo)
        if data_url:
            chosen_logo = data_url
        else:
            st.warning("That file could not be used as a logo. Allowed: png, jpg, gif, webp, svg.")

    save = st.button("Save branding", type="primary", use_container_width=True, key="branding_save")
    reset = st.button("Reset to defaults", use_container_width=True, key="branding_reset")

    if save:
        st.session_state["branding"] = save_branding(chosen_logo, name_in, accent_in)
        st.success("Branding saved.")
        st.rerun()

    if reset:
        st.session_state["branding"] = save_branding("", DEFAULT_NAME, DEFAULT_ACCENT)
        st.success("Branding reset to defaults.")
        st.rerun()

    return st.session_state.get("branding", branding)
