"""
charts.py — Plotly figure builders.

All functions return go.Figure objects ready for st.plotly_chart().
Return None if required data is missing.
"""

import pandas as pd
import numpy as np
import plotly.graph_objects as go
import plotly.express as px
from typing import Optional

# Brand palette
PRIMARY   = "#4F8EF7"   # blue
SECONDARY = "#7C3AED"   # violet
SUCCESS   = "#10B981"   # emerald
WARNING   = "#F59E0B"   # amber
DANGER    = "#EF4444"   # red
MUTED     = "#6B7280"   # slate

PALETTE   = [PRIMARY, SECONDARY, SUCCESS, WARNING, DANGER,
             "#06B6D4", "#EC4899", "#14B8A6", "#F97316", "#A855F7"]

FONT_FAMILY = "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"

BASE_LAYOUT = dict(
    font=dict(family=FONT_FAMILY, color="#E5E7EB"),
    paper_bgcolor="rgba(0,0,0,0)",
    plot_bgcolor="rgba(0,0,0,0)",
    margin=dict(l=16, r=16, t=40, b=16),
    legend=dict(
        bgcolor="rgba(255,255,255,0.04)",
        bordercolor="rgba(255,255,255,0.08)",
        borderwidth=1,
    ),
)

GRID_STYLE = dict(
    xaxis=dict(gridcolor="rgba(255,255,255,0.06)", linecolor="rgba(255,255,255,0.1)"),
    yaxis=dict(gridcolor="rgba(255,255,255,0.06)", linecolor="rgba(255,255,255,0.1)"),
)


def _apply_base(fig: go.Figure, title: str = "") -> go.Figure:
    layout_kwargs = {**BASE_LAYOUT, **GRID_STYLE}
    if title:
        layout_kwargs["title"] = dict(text=title, font=dict(size=15, color="#F3F4F6"))
    fig.update_layout(**layout_kwargs)
    return fig


# ---------------------------------------------------------------------------
# Revenue trend
# ---------------------------------------------------------------------------

def revenue_trend(period_df: Optional[pd.DataFrame],
                  leads_df: Optional[pd.DataFrame] = None) -> Optional[go.Figure]:
    if period_df is None or period_df.empty:
        return None

    fig = go.Figure()

    # Revenue area
    fig.add_trace(go.Scatter(
        x=period_df["period"],
        y=period_df["revenue"],
        name="Revenue",
        mode="lines+markers",
        line=dict(color=PRIMARY, width=2.5),
        marker=dict(size=5, color=PRIMARY),
        fill="tozeroy",
        fillcolor="rgba(79,142,247,0.12)",
        hovertemplate="<b>%{x|%b %Y}</b><br>Revenue: $%{y:,.0f}<extra></extra>",
    ))

    # Rolling average
    if len(period_df) >= 3:
        roll = period_df["revenue"].rolling(3, center=True).mean()
        fig.add_trace(go.Scatter(
            x=period_df["period"],
            y=roll,
            name="3-period avg",
            mode="lines",
            line=dict(color=WARNING, width=1.5, dash="dot"),
            hovertemplate="<b>Avg</b>: $%{y:,.0f}<extra></extra>",
        ))

    # Leads on secondary axis
    if leads_df is not None and not leads_df.empty:
        fig.add_trace(go.Bar(
            x=leads_df["period"],
            y=leads_df["leads"],
            name="Leads",
            yaxis="y2",
            marker_color="rgba(124,58,237,0.35)",
            hovertemplate="<b>%{x|%b %Y}</b><br>Leads: %{y}<extra></extra>",
        ))
        fig.update_layout(
            yaxis2=dict(
                title="Leads",
                overlaying="y",
                side="right",
                gridcolor="rgba(255,255,255,0)",
                showgrid=False,
                color=SECONDARY,
            )
        )

    _apply_base(fig, "Revenue Trend")
    fig.update_layout(
        yaxis=dict(
            title="Revenue ($)",
            tickprefix="$",
            tickformat=",.0f",
            gridcolor="rgba(255,255,255,0.06)",
        ),
        hovermode="x unified",
        legend=dict(orientation="h", yanchor="bottom", y=1.02, xanchor="right", x=1),
    )
    return fig


# ---------------------------------------------------------------------------
# Product / service bar chart
# ---------------------------------------------------------------------------

def performance_bar(top_df: Optional[pd.DataFrame],
                    label: str = "Product",
                    title: str = "Revenue by Product") -> Optional[go.Figure]:
    if top_df is None or top_df.empty:
        return None

    col_name = top_df.columns[0]
    sorted_df = top_df.sort_values("revenue", ascending=True)

    fig = go.Figure(go.Bar(
        y=sorted_df[col_name].astype(str),
        x=sorted_df["revenue"],
        orientation="h",
        marker=dict(
            color=sorted_df["revenue"],
            colorscale=[[0, "rgba(79,142,247,0.4)"], [1, PRIMARY]],
            showscale=False,
        ),
        text=sorted_df["revenue"].apply(lambda v: f"${v:,.0f}"),
        textposition="outside",
        hovertemplate=f"<b>%{{y}}</b><br>Revenue: $%{{x:,.0f}}<extra></extra>",
    ))

    _apply_base(fig, title)
    fig.update_layout(
        xaxis=dict(
            title="Revenue ($)",
            tickprefix="$",
            tickformat=",.0f",
            gridcolor="rgba(255,255,255,0.06)",
        ),
        yaxis=dict(automargin=True),
        height=max(250, len(sorted_df) * 42 + 80),
    )
    return fig


# ---------------------------------------------------------------------------
# Source / channel pie / donut
# ---------------------------------------------------------------------------

def source_donut(source_df: Optional[pd.DataFrame],
                 title: str = "Revenue by Source") -> Optional[go.Figure]:
    if source_df is None or source_df.empty:
        return None

    col_name = source_df.columns[0]

    fig = go.Figure(go.Pie(
        labels=source_df[col_name].astype(str),
        values=source_df["revenue"],
        hole=0.52,
        marker=dict(colors=PALETTE),
        textinfo="label+percent",
        textfont=dict(size=12),
        hovertemplate="<b>%{label}</b><br>Revenue: $%{value:,.0f}<br>Share: %{percent}<extra></extra>",
    ))

    fig.update_layout(
        font=dict(family=FONT_FAMILY, color="#E5E7EB"),
        paper_bgcolor="rgba(0,0,0,0)",
        plot_bgcolor="rgba(0,0,0,0)",
        margin=dict(l=16, r=16, t=40, b=16),
        title=dict(text=title, font=dict(size=15, color="#F3F4F6")),
        showlegend=True,
        legend=dict(
            bgcolor="rgba(255,255,255,0.04)",
            bordercolor="rgba(255,255,255,0.08)",
            borderwidth=1,
            orientation="v",
        ),
        height=320,
    )
    return fig


# ---------------------------------------------------------------------------
# Conversions by source / product
# ---------------------------------------------------------------------------

def conversions_bar(conv_df: Optional[pd.DataFrame],
                    title: str = "Conversions by Source") -> Optional[go.Figure]:
    if conv_df is None or conv_df.empty:
        return None

    col_name = conv_df.columns[0]
    sorted_df = conv_df.sort_values("conversions", ascending=False)

    colors = [PALETTE[i % len(PALETTE)] for i in range(len(sorted_df))]

    fig = go.Figure(go.Bar(
        x=sorted_df[col_name].astype(str),
        y=sorted_df["conversions"],
        marker_color=colors,
        text=sorted_df["conversions"].apply(lambda v: f"{int(v)}"),
        textposition="outside",
        hovertemplate=f"<b>%{{x}}</b><br>Conversions: %{{y}}<extra></extra>",
    ))

    _apply_base(fig, title)
    fig.update_layout(
        yaxis=dict(title="Conversions", gridcolor="rgba(255,255,255,0.06)"),
        xaxis=dict(automargin=True),
    )
    return fig


# ---------------------------------------------------------------------------
# Revenue scatter (date × revenue coloured by product)
# ---------------------------------------------------------------------------

def scatter_by_product(df: pd.DataFrame, col_map: dict) -> Optional[go.Figure]:
    rev_col  = col_map.get("revenue")
    date_col = col_map.get("date")
    prod_col = col_map.get("product") or col_map.get("service")

    if not rev_col or not date_col:
        return None

    fig = go.Figure()
    if prod_col:
        groups = df.groupby(prod_col)
        for i, (name, grp) in enumerate(groups):
            grp = grp.sort_values(date_col)
            fig.add_trace(go.Scatter(
                x=grp[date_col],
                y=grp[rev_col],
                mode="markers",
                name=str(name),
                marker=dict(
                    color=PALETTE[i % len(PALETTE)],
                    size=8,
                    opacity=0.8,
                    line=dict(width=1, color="rgba(0,0,0,0.2)"),
                ),
                hovertemplate=(
                    f"<b>{name}</b><br>"
                    "Date: %{x|%b %d, %Y}<br>"
                    "Revenue: $%{y:,.0f}<extra></extra>"
                ),
            ))
    else:
        tmp = df.sort_values(date_col)
        fig.add_trace(go.Scatter(
            x=tmp[date_col],
            y=tmp[rev_col],
            mode="markers",
            marker=dict(color=PRIMARY, size=8, opacity=0.7),
            hovertemplate="Date: %{x|%b %d, %Y}<br>Revenue: $%{y:,.0f}<extra></extra>",
        ))

    _apply_base(fig, "Individual Transactions")
    fig.update_layout(
        yaxis=dict(
            title="Revenue ($)",
            tickprefix="$",
            tickformat=",.0f",
            gridcolor="rgba(255,255,255,0.06)",
        ),
        hovermode="closest",
        legend=dict(orientation="h", yanchor="bottom", y=1.02, xanchor="right", x=1),
    )
    return fig


# ---------------------------------------------------------------------------
# Monthly growth waterfall
# ---------------------------------------------------------------------------

def growth_waterfall(period_df: Optional[pd.DataFrame]) -> Optional[go.Figure]:
    if period_df is None or len(period_df) < 2:
        return None

    periods = period_df["period"].dt.strftime("%b '%y").tolist()
    revenues = period_df["revenue"].tolist()

    deltas = [revenues[0]] + [revenues[i] - revenues[i-1] for i in range(1, len(revenues))]
    measures = ["absolute"] + [
        "relative" for _ in range(1, len(revenues))
    ]
    colors = [PRIMARY if d >= 0 else DANGER for d in deltas]

    fig = go.Figure(go.Waterfall(
        x=periods,
        y=deltas,
        measure=measures,
        connector=dict(line=dict(color="rgba(255,255,255,0.15)", width=1)),
        increasing=dict(marker_color=SUCCESS),
        decreasing=dict(marker_color=DANGER),
        totals=dict(marker_color=PRIMARY),
        text=[f"${abs(d):,.0f}" for d in deltas],
        textposition="outside",
        hovertemplate="<b>%{x}</b><br>Change: $%{y:,.0f}<extra></extra>",
    ))

    _apply_base(fig, "Month-over-Month Revenue Change")
    fig.update_layout(
        yaxis=dict(
            title="Change ($)",
            tickprefix="$",
            tickformat=",.0f",
            gridcolor="rgba(255,255,255,0.06)",
        ),
    )
    return fig
