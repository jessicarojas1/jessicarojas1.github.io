"""
styles.py — Custom CSS injected into the Streamlit app.
"""

import streamlit as st


CSS = """
/* ── Imports ────────────────────────────────────────────────────────────── */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

/* ── Global ──────────────────────────────────────────────────────────────── */
html, body, [data-testid="stAppViewContainer"] {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
}

[data-testid="stAppViewContainer"] {
    background: #0D1117;
}

[data-testid="stSidebar"] {
    background: #161B22 !important;
    border-right: 1px solid rgba(255,255,255,0.06);
}

/* ── KPI Cards ───────────────────────────────────────────────────────────── */
.kpi-card {
    background: linear-gradient(135deg, #1C2333 0%, #161B22 100%);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 12px;
    padding: 20px 24px;
    position: relative;
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 32px rgba(0,0,0,0.4);
}

.kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: var(--accent, #4F8EF7);
}

.kpi-label {
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #6B7280;
    margin-bottom: 8px;
}

.kpi-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #F3F4F6;
    line-height: 1;
    margin-bottom: 6px;
}

.kpi-delta {
    font-size: 0.78rem;
    font-weight: 500;
}

.kpi-delta.positive { color: #10B981; }
.kpi-delta.negative { color: #EF4444; }
.kpi-delta.neutral  { color: #6B7280; }

.kpi-icon {
    position: absolute;
    top: 16px; right: 16px;
    font-size: 1.5rem;
    opacity: 0.3;
}

/* ── Section Headers ─────────────────────────────────────────────────────── */
.section-header {
    font-size: 1rem;
    font-weight: 600;
    color: #E5E7EB;
    margin: 0 0 16px 0;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    letter-spacing: -0.01em;
}

/* ── Insight Cards ───────────────────────────────────────────────────────── */
.insight-card {
    border-radius: 10px;
    padding: 16px 20px;
    margin-bottom: 12px;
    border-left: 3px solid;
    position: relative;
}

.insight-card.positive {
    background: rgba(16, 185, 129, 0.08);
    border-color: #10B981;
}

.insight-card.negative {
    background: rgba(239, 68, 68, 0.08);
    border-color: #EF4444;
}

.insight-card.warning {
    background: rgba(245, 158, 11, 0.08);
    border-color: #F59E0B;
}

.insight-card.neutral {
    background: rgba(107, 114, 128, 0.08);
    border-color: #6B7280;
}

.insight-headline {
    font-weight: 600;
    font-size: 0.9rem;
    color: #F3F4F6;
    margin-bottom: 4px;
}

.insight-detail {
    font-size: 0.82rem;
    color: #9CA3AF;
    line-height: 1.55;
}

/* ── Upload Zone ─────────────────────────────────────────────────────────── */
[data-testid="stFileUploader"] {
    border: 2px dashed rgba(79,142,247,0.3) !important;
    border-radius: 10px !important;
    padding: 8px !important;
    background: rgba(79,142,247,0.04) !important;
    transition: border-color 0.2s;
}

[data-testid="stFileUploader"]:hover {
    border-color: rgba(79,142,247,0.6) !important;
}

/* ── Column Mapping Table ────────────────────────────────────────────────── */
.col-map-table {
    width: 100%;
    font-size: 0.78rem;
    border-collapse: collapse;
    margin-top: 8px;
}

.col-map-table th {
    color: #6B7280;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.68rem;
    letter-spacing: 0.06em;
    padding: 4px 8px;
    text-align: left;
}

.col-map-table td {
    padding: 4px 8px;
    color: #D1D5DB;
    border-top: 1px solid rgba(255,255,255,0.04);
}

.col-map-table .found   { color: #10B981; }
.col-map-table .missing { color: #6B7280; font-style: italic; }

/* ── Streamlit metric override ───────────────────────────────────────────── */
[data-testid="stMetric"] {
    background: linear-gradient(135deg, #1C2333, #161B22);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 10px;
    padding: 16px !important;
}

[data-testid="stMetricLabel"] { color: #6B7280 !important; font-size: 0.75rem !important; }
[data-testid="stMetricValue"] { color: #F3F4F6 !important; }
[data-testid="stMetricDelta"] { font-size: 0.78rem !important; }

/* ── Divider ─────────────────────────────────────────────────────────────── */
hr { border-color: rgba(255,255,255,0.06) !important; }

/* ── Scrollbar ───────────────────────────────────────────────────────────── */
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.12); border-radius: 3px; }
"""


def inject_css():
    st.markdown(f"<style>{CSS}</style>", unsafe_allow_html=True)


def kpi_card(label: str, value: str, delta: str = "",
             delta_dir: str = "neutral", icon: str = "",
             accent: str = "#4F8EF7") -> str:
    """Return an HTML KPI card string for st.markdown(unsafe_allow_html=True)."""
    delta_html = (
        f'<div class="kpi-delta {delta_dir}">{delta}</div>'
        if delta else ""
    )
    icon_html = f'<div class="kpi-icon">{icon}</div>' if icon else ""
    return f"""
    <div class="kpi-card" style="--accent:{accent}">
        {icon_html}
        <div class="kpi-label">{label}</div>
        <div class="kpi-value">{value}</div>
        {delta_html}
    </div>
    """


def insight_card(icon: str, headline: str, detail: str,
                 severity: str = "neutral") -> str:
    return f"""
    <div class="insight-card {severity}">
        <div class="insight-headline">{icon} &nbsp;{headline}</div>
        <div class="insight-detail">{detail}</div>
    </div>
    """


def section_header(title: str) -> None:
    st.markdown(f'<div class="section-header">{title}</div>', unsafe_allow_html=True)
