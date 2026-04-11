"""
Business Insight Dashboard
--------------------------
Upload a CSV → instant KPIs, charts, and plain-English insights.

Run:
    streamlit run app.py
"""

import streamlit as st
import pandas as pd

# ── Must be the very first Streamlit call ────────────────────────────────────
st.set_page_config(
    page_title="Business Insight Dashboard",
    page_icon="📊",
    layout="wide",
    initial_sidebar_state="expanded",
    menu_items={
        "Get Help": None,
        "Report a bug": None,
        "About": "Business Insight Dashboard — instant analytics from any CSV.",
    },
)

from modules.styles  import inject_css, kpi_card, insight_card, section_header
from modules.loader  import load_and_detect, missing_columns, available_columns
from modules.kpis    import (compute_kpis, revenue_by_period, leads_by_period,
                              top_by_column, conversions_by_column)
from modules.charts  import (revenue_trend, performance_bar, source_donut,
                              conversions_bar, scatter_by_product, growth_waterfall)
from modules.insights import generate_insights

inject_css()

PLOTLY_CONFIG = dict(
    displayModeBar=False,
    responsive=True,
)

# ─────────────────────────────────────────────────────────────────────────────
# Sidebar
# ─────────────────────────────────────────────────────────────────────────────

with st.sidebar:
    st.markdown(
        """
        <div style="padding:8px 0 20px;">
          <div style="font-size:1.4rem;font-weight:700;color:#F3F4F6;letter-spacing:-0.02em;">
            📊 Business Insight
          </div>
          <div style="font-size:0.75rem;color:#6B7280;margin-top:2px;">
            Instant analytics · No code required
          </div>
        </div>
        """,
        unsafe_allow_html=True,
    )

    uploaded_file = st.file_uploader(
        "Upload your CSV",
        type=["csv"],
        help="Supports: date, revenue, leads, conversions, product, service, source, customer",
    )

    st.divider()

    # ── Aggregation frequency ─────────────────────────────────────────────
    freq_label = st.selectbox(
        "Chart aggregation",
        ["Monthly", "Weekly", "Daily", "Quarterly"],
        index=0,
        help="Time-series charts will group data by this interval.",
    )
    FREQ_MAP = {"Monthly": "ME", "Weekly": "W", "Daily": "D", "Quarterly": "QE"}
    # Note: ME/QE are pandas 2.x month-end / quarter-end aliases
    freq = FREQ_MAP[freq_label]

    # ── Top-N ─────────────────────────────────────────────────────────────
    top_n = st.slider("Top N products/services", min_value=3, max_value=15, value=8)

    st.divider()

    # ── Sample data ───────────────────────────────────────────────────────
    with open("sample_data/sample_business.csv", "rb") as f:
        st.download_button(
            label="⬇ Download sample CSV",
            data=f,
            file_name="sample_business.csv",
            mime="text/csv",
            use_container_width=True,
        )

    st.markdown(
        """
        <div style="font-size:0.7rem;color:#4B5563;padding-top:16px;line-height:1.6;">
        Detected columns are mapped automatically.<br>
        Required: <b style="color:#6B7280">date</b> + <b style="color:#6B7280">revenue</b><br>
        Optional: leads · conversions · product · service · source · customer
        </div>
        """,
        unsafe_allow_html=True,
    )


# ─────────────────────────────────────────────────────────────────────────────
# Landing screen (no file uploaded)
# ─────────────────────────────────────────────────────────────────────────────

if not uploaded_file:
    st.markdown(
        """
        <div style="text-align:center;padding:60px 20px 20px;">
          <div style="font-size:3.5rem;margin-bottom:20px;">📊</div>
          <h1 style="font-size:2rem;font-weight:700;color:#F3F4F6;margin-bottom:12px;
                     letter-spacing:-0.03em;">
            Business Insight Dashboard
          </h1>
          <p style="font-size:1rem;color:#9CA3AF;max-width:520px;margin:0 auto 32px;
                    line-height:1.65;">
            Upload a CSV and instantly get KPI summaries, revenue trends,
            product performance charts, and plain-English business insights —
            no code, no setup, no waiting.
          </p>
        </div>
        """,
        unsafe_allow_html=True,
    )

    c1, c2, c3 = st.columns(3)
    with c1:
        st.markdown(
            kpi_card("Revenue Trends", "Real-time", icon="📈", accent="#4F8EF7"),
            unsafe_allow_html=True,
        )
    with c2:
        st.markdown(
            kpi_card("Smart Insights", "AI-style logic", icon="🧠", accent="#7C3AED"),
            unsafe_allow_html=True,
        )
    with c3:
        st.markdown(
            kpi_card("Instant Setup", "Drop a CSV", icon="⚡", accent="#10B981"),
            unsafe_allow_html=True,
        )

    st.markdown("<br>", unsafe_allow_html=True)

    with st.expander("What columns does it support?"):
        st.markdown(
            """
| Column | Description | Required |
|--------|-------------|----------|
| `date` | Transaction or report date | ✅ Recommended |
| `revenue` | Revenue / sales amount | ✅ Recommended |
| `leads` | Number of leads generated | Optional |
| `conversions` | Number of deals closed | Optional |
| `product` | Product name or SKU | Optional |
| `service` | Service or category name | Optional |
| `source` | Marketing channel / source | Optional |
| `customer` | Customer or account name | Optional |

Column names are detected automatically — variations like *Sales*, *Amount*, *Channel* are all recognised.
            """
        )

    with st.expander("What insights will I get?"):
        for item in [
            "Revenue trend direction and growth rate",
            "Best and worst performing periods",
            "Top products and services by revenue",
            "Conversion rate and efficiency analysis",
            "Top acquisition channels",
            "Anomaly detection (spikes and drops)",
            "Lead volume trend",
            "Customer concentration risk score",
        ]:
            st.markdown(f"- {item}")

    st.stop()


# ─────────────────────────────────────────────────────────────────────────────
# Load & detect
# ─────────────────────────────────────────────────────────────────────────────

try:
    df, col_map = load_and_detect(uploaded_file)
except ValueError as e:
    st.error(f"Could not load file: {e}")
    st.stop()

found   = available_columns(col_map)
missing = missing_columns(col_map)

# ── Column detection summary (sidebar) ────────────────────────────────────
with st.sidebar:
    st.markdown("**Column detection**")
    rows = []
    for canonical, actual in col_map.items():
        status = "✅" if actual else "—"
        mapped = actual if actual else "not found"
        css_cls = "found" if actual else "missing"
        rows.append(f"<tr><td>{status} {canonical}</td><td class='{css_cls}'>{mapped}</td></tr>")

    st.markdown(
        f"""
        <table class="col-map-table">
          <thead><tr><th>Field</th><th>Mapped to</th></tr></thead>
          <tbody>{"".join(rows)}</tbody>
        </table>
        """,
        unsafe_allow_html=True,
    )

    if missing:
        st.caption(f"Missing: {', '.join(missing)} — charts requiring these will be hidden.")

# ─────────────────────────────────────────────────────────────────────────────
# Data preview toggle
# ─────────────────────────────────────────────────────────────────────────────

with st.expander(f"Data preview  ·  {len(df):,} rows · {len(df.columns)} columns", expanded=False):
    st.dataframe(df.head(100), use_container_width=True, height=260)

st.divider()

# ─────────────────────────────────────────────────────────────────────────────
# KPI Row
# ─────────────────────────────────────────────────────────────────────────────

kpis = compute_kpis(df, col_map)

section_header("Key Performance Indicators")

kpi_cols = st.columns(4)

# Total Revenue
with kpi_cols[0]:
    val = f"${kpis['total_revenue']:,.0f}" if kpis["total_revenue"] is not None else "—"
    growth = kpis.get("growth_pct")
    delta = f"{'▲' if growth and growth >= 0 else '▼'} {abs(growth):.1f}% H2 vs H1" if growth is not None else ""
    delta_dir = "positive" if growth and growth >= 0 else ("negative" if growth is not None else "neutral")
    st.markdown(kpi_card("Total Revenue", val, delta, delta_dir, "💰", "#4F8EF7"),
                unsafe_allow_html=True)

# Avg Transaction / Period Revenue
with kpi_cols[1]:
    val = f"${kpis['avg_revenue']:,.0f}" if kpis["avg_revenue"] is not None else "—"
    st.markdown(kpi_card("Avg Revenue / Row", val, icon="📊", accent="#7C3AED"),
                unsafe_allow_html=True)

# Conversion Rate or Total Leads
with kpi_cols[2]:
    if kpis["conversion_rate"] is not None:
        val = f"{kpis['conversion_rate']:.1f}%"
        label = "Conversion Rate"
        icon = "🎯"
        accent = "#10B981"
    elif kpis["total_leads"] is not None:
        val = f"{kpis['total_leads']:,.0f}"
        label = "Total Leads"
        icon = "🌱"
        accent = "#10B981"
    else:
        val = f"{len(df):,}"
        label = "Total Records"
        icon = "📋"
        accent = "#10B981"
    st.markdown(kpi_card(label, val, icon=icon, accent=accent), unsafe_allow_html=True)

# Rev per Conversion or unique products
with kpi_cols[3]:
    if kpis["rev_per_conversion"] is not None:
        val = f"${kpis['rev_per_conversion']:,.0f}"
        label = "Rev / Conversion"
        icon = "💎"
        accent = "#F59E0B"
    elif kpis["unique_products"] is not None:
        val = str(kpis["unique_products"])
        label = "Products Tracked"
        icon = "📦"
        accent = "#F59E0B"
    elif kpis["unique_customers"] is not None:
        val = str(kpis["unique_customers"])
        label = "Unique Customers"
        icon = "👥"
        accent = "#F59E0B"
    else:
        val = f"${kpis['max_revenue']:,.0f}" if kpis["max_revenue"] else "—"
        label = "Peak Revenue"
        icon = "🏆"
        accent = "#F59E0B"
    st.markdown(kpi_card(label, val, icon=icon, accent=accent), unsafe_allow_html=True)

# Optional second row for leads / conversions if present
extra_kpis = []
if kpis["total_leads"] is not None:
    extra_kpis.append(("Total Leads", f"{kpis['total_leads']:,.0f}", "🌱", "#06B6D4"))
if kpis["total_conversions"] is not None:
    extra_kpis.append(("Total Conversions", f"{kpis['total_conversions']:,.0f}", "✅", "#14B8A6"))
if kpis["best_period"] is not None:
    extra_kpis.append(("Best Period", kpis["best_period"], "🏆", "#A855F7"))
if kpis["unique_customers"] is not None and kpis["rev_per_conversion"] is not None:
    extra_kpis.append(("Unique Customers", str(kpis["unique_customers"]), "👥", "#EC4899"))

if extra_kpis:
    st.markdown("<br>", unsafe_allow_html=True)
    ecols = st.columns(len(extra_kpis))
    for i, (label, val, icon, accent) in enumerate(extra_kpis):
        with ecols[i]:
            st.markdown(kpi_card(label, val, icon=icon, accent=accent),
                        unsafe_allow_html=True)

st.divider()

# ─────────────────────────────────────────────────────────────────────────────
# Revenue Trend Chart
# ─────────────────────────────────────────────────────────────────────────────

section_header("Revenue Trend")

rev_period  = revenue_by_period(df, col_map, freq)
lead_period = leads_by_period(df, col_map, freq)

if rev_period is not None:
    fig = revenue_trend(rev_period, lead_period)
    if fig:
        st.plotly_chart(fig, use_container_width=True, config=PLOTLY_CONFIG)

    # Waterfall
    if len(rev_period) >= 3:
        fig_wf = growth_waterfall(rev_period)
        if fig_wf:
            with st.expander("Period-over-Period Change (Waterfall)", expanded=False):
                st.plotly_chart(fig_wf, use_container_width=True, config=PLOTLY_CONFIG)
else:
    st.info("Add **date** and **revenue** columns to see trend charts.")

st.divider()

# ─────────────────────────────────────────────────────────────────────────────
# Product / Service & Source
# ─────────────────────────────────────────────────────────────────────────────

section_header("Performance Breakdown")

left, right = st.columns(2)

# Left: product or service bar
prod_or_svc = "product" if col_map.get("product") else "service"
top_df = top_by_column(df, col_map, prod_or_svc, top_n)

with left:
    if top_df is not None:
        label = prod_or_svc.capitalize()
        fig = performance_bar(top_df, label=label, title=f"Revenue by {label}")
        if fig:
            st.plotly_chart(fig, use_container_width=True, config=PLOTLY_CONFIG)
    else:
        st.info("Add a **product** or **service** column to see performance breakdown.")

# Right: source donut
source_df = top_by_column(df, col_map, "source", 10)
with right:
    if source_df is not None:
        fig = source_donut(source_df, title="Revenue by Source / Channel")
        if fig:
            st.plotly_chart(fig, use_container_width=True, config=PLOTLY_CONFIG)
    elif top_df is None:
        st.info("Add a **source** column to see channel breakdown.")
    else:
        # Second chart: customer breakdown
        cust_df = top_by_column(df, col_map, "customer", top_n)
        if cust_df is not None:
            fig = performance_bar(cust_df, label="Customer",
                                  title=f"Revenue by Customer (Top {top_n})")
            if fig:
                st.plotly_chart(fig, use_container_width=True, config=PLOTLY_CONFIG)

st.divider()

# ─────────────────────────────────────────────────────────────────────────────
# Conversions breakdown
# ─────────────────────────────────────────────────────────────────────────────

conv_source = conversions_by_column(df, col_map, "source")
conv_prod   = conversions_by_column(df, col_map, prod_or_svc)

if conv_source is not None or conv_prod is not None:
    section_header("Conversion Analysis")
    cc1, cc2 = st.columns(2)
    with cc1:
        if conv_source is not None:
            fig = conversions_bar(conv_source, title="Conversions by Source")
            if fig:
                st.plotly_chart(fig, use_container_width=True, config=PLOTLY_CONFIG)
    with cc2:
        if conv_prod is not None:
            fig = conversions_bar(conv_prod,
                                  title=f"Conversions by {prod_or_svc.capitalize()}")
            if fig:
                st.plotly_chart(fig, use_container_width=True, config=PLOTLY_CONFIG)
    st.divider()

# ─────────────────────────────────────────────────────────────────────────────
# Scatter — transactions by product over time
# ─────────────────────────────────────────────────────────────────────────────

if col_map.get("date") and col_map.get("revenue"):
    fig_sc = scatter_by_product(df, col_map)
    if fig_sc:
        with st.expander("Individual Transactions (scatter)", expanded=False):
            st.plotly_chart(fig_sc, use_container_width=True, config=PLOTLY_CONFIG)

st.divider()

# ─────────────────────────────────────────────────────────────────────────────
# Business Insights Panel
# ─────────────────────────────────────────────────────────────────────────────

section_header("Business Insights")

insights = generate_insights(df, col_map, kpis)

sev_order = {"negative": 0, "warning": 1, "positive": 2, "neutral": 3}
insights_sorted = sorted(insights, key=lambda x: sev_order.get(x["severity"], 9))

# Group by severity
neg_insights  = [i for i in insights_sorted if i["severity"] == "negative"]
warn_insights = [i for i in insights_sorted if i["severity"] == "warning"]
pos_insights  = [i for i in insights_sorted if i["severity"] == "positive"]
neu_insights  = [i for i in insights_sorted if i["severity"] == "neutral"]

if neg_insights or warn_insights:
    st.markdown("##### 🚨 Needs Attention")
    for ins in neg_insights + warn_insights:
        st.markdown(
            insight_card(ins["icon"], ins["headline"], ins["detail"], ins["severity"]),
            unsafe_allow_html=True,
        )

if pos_insights:
    st.markdown("##### ✅ What's Working")
    for ins in pos_insights:
        st.markdown(
            insight_card(ins["icon"], ins["headline"], ins["detail"], ins["severity"]),
            unsafe_allow_html=True,
        )

if neu_insights:
    for ins in neu_insights:
        st.markdown(
            insight_card(ins["icon"], ins["headline"], ins["detail"], ins["severity"]),
            unsafe_allow_html=True,
        )

st.divider()

# ─────────────────────────────────────────────────────────────────────────────
# Footer
# ─────────────────────────────────────────────────────────────────────────────

st.markdown(
    """
    <div style="text-align:center;padding:24px 0 8px;color:#374151;font-size:0.72rem;">
      Business Insight Dashboard &nbsp;·&nbsp; Built with Streamlit &amp; Plotly
      &nbsp;·&nbsp; Data processed locally — nothing is stored or transmitted
    </div>
    """,
    unsafe_allow_html=True,
)
