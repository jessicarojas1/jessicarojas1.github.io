"""
insights.py — Rule-based business insight engine.

Generates plain-English insights from the data — no API calls required.
Returns a list of (icon, headline, detail, severity) tuples.
severity: 'positive' | 'warning' | 'neutral' | 'negative'
"""

import pandas as pd
import numpy as np
from typing import Optional


def _col(col_map: dict, key: str) -> Optional[str]:
    return col_map.get(key)


def _pct_fmt(v: float) -> str:
    sign = "+" if v >= 0 else ""
    return f"{sign}{v:.1f}%"


def _dollar_fmt(v: float) -> str:
    if v >= 1_000_000:
        return f"${v/1_000_000:.2f}M"
    if v >= 1_000:
        return f"${v/1_000:.1f}K"
    return f"${v:,.2f}"


# ---------------------------------------------------------------------------
# Individual insight generators
# ---------------------------------------------------------------------------

def _revenue_trend_insight(df, col_map):
    """Determine if revenue is trending up, down, or flat."""
    rev_col  = _col(col_map, "revenue")
    date_col = _col(col_map, "date")
    if not rev_col or not date_col:
        return None

    try:
        tmp = df[[date_col, rev_col]].dropna().sort_values(date_col)
        if len(tmp) < 4:
            return None

        monthly = tmp.set_index(date_col).resample("ME")[rev_col].sum()
        if len(monthly) < 3:
            return None

        # Linear regression slope
        x = np.arange(len(monthly))
        y = monthly.values
        slope, intercept = np.polyfit(x, y, 1)
        pct_change = slope / (y.mean() + 1e-9) * 100

        if pct_change > 3:
            return ("📈", "Revenue is on a clear upward trend",
                    f"Average monthly growth rate is approximately {pct_change:.1f}%. "
                    f"If this pace holds, you're on track to hit {_dollar_fmt(y[-1]*1.1)} next period.",
                    "positive")
        elif pct_change < -3:
            return ("📉", "Revenue has been declining",
                    f"Revenue is contracting at ~{abs(pct_change):.1f}% per period. "
                    f"Investigate customer churn, seasonal effects, or competitive pressure.",
                    "negative")
        else:
            return ("➡️", "Revenue is holding steady",
                    f"Revenue fluctuates within a flat band — consider growth levers "
                    f"like upsells, new channels, or pricing adjustments.",
                    "neutral")
    except Exception:
        return None


def _best_period_insight(df, col_map):
    rev_col  = _col(col_map, "revenue")
    date_col = _col(col_map, "date")
    if not rev_col or not date_col:
        return None

    try:
        tmp = df[[date_col, rev_col]].dropna().sort_values(date_col)
        monthly = tmp.set_index(date_col).resample("ME")[rev_col].sum()
        if monthly.empty:
            return None

        best_month = monthly.idxmax()
        best_val   = monthly.max()
        worst_month = monthly.idxmin()
        worst_val   = monthly.min()

        label = best_month.strftime("%B %Y") if hasattr(best_month, "strftime") else str(best_month)
        worst_label = worst_month.strftime("%B %Y") if hasattr(worst_month, "strftime") else str(worst_month)

        gap_pct = (best_val - worst_val) / (worst_val + 1e-9) * 100

        return ("🏆", f"Best month: {label} at {_dollar_fmt(best_val)}",
                f"Your weakest month was {worst_label} ({_dollar_fmt(worst_val)}). "
                f"The {gap_pct:.0f}% gap between peak and trough suggests seasonality or campaign impact. "
                f"Study what drove {label} and replicate it.",
                "positive")
    except Exception:
        return None


def _top_product_insight(df, col_map):
    rev_col  = _col(col_map, "revenue")
    prod_col = _col(col_map, "product") or _col(col_map, "service")
    if not rev_col or not prod_col:
        return None

    try:
        grouped = df.groupby(prod_col)[rev_col].sum().sort_values(ascending=False)
        if grouped.empty:
            return None

        top_name = grouped.index[0]
        top_val  = grouped.iloc[0]
        total    = grouped.sum()
        share    = top_val / total * 100

        if len(grouped) > 1:
            second_name = grouped.index[1]
            second_val  = grouped.iloc[1]
            gap = (top_val - second_val) / (second_val + 1e-9) * 100
            detail = (
                f"\"{top_name}\" generates {_dollar_fmt(top_val)} ({share:.0f}% of total revenue), "
                f"outperforming \"{second_name}\" by {gap:.0f}%. "
                f"{'This concentration is a risk — diversify.' if share > 70 else 'Healthy spread across offerings.'}"
            )
        else:
            detail = f"\"{top_name}\" is your only tracked offering at {_dollar_fmt(top_val)}."

        severity = "warning" if share > 70 else "positive"
        return ("🥇", f"Top performer: {top_name}", detail, severity)
    except Exception:
        return None


def _conversion_efficiency_insight(df, col_map):
    lead_col = _col(col_map, "leads")
    conv_col = _col(col_map, "conversions")
    if not lead_col or not conv_col:
        return None

    try:
        total_leads = df[lead_col].sum()
        total_conv  = df[conv_col].sum()
        if total_leads <= 0:
            return None

        rate = total_conv / total_leads * 100

        if rate >= 20:
            verdict = "excellent — top-quartile performance"
            severity = "positive"
        elif rate >= 10:
            verdict = "solid. There's still room to optimize your funnel"
            severity = "positive"
        elif rate >= 5:
            verdict = "average. Focus on lead qualification and follow-up cadence"
            severity = "warning"
        else:
            verdict = "below average. Audit your lead sources and sales process"
            severity = "negative"

        return ("🎯", f"Conversion rate: {rate:.1f}%",
                f"You're converting {total_conv:.0f} out of {total_leads:.0f} leads — {verdict}. "
                f"Even a 2-point improvement would add {_dollar_fmt(total_conv * 0.02 / (total_conv + 1e-9) * df[_col(col_map, 'revenue')].sum() if _col(col_map, 'revenue') else 0)} in estimated revenue.",
                severity)
    except Exception:
        return None


def _top_source_insight(df, col_map):
    rev_col    = _col(col_map, "revenue")
    source_col = _col(col_map, "source")
    if not rev_col or not source_col:
        return None

    try:
        grouped = df.groupby(source_col)[rev_col].sum().sort_values(ascending=False)
        if grouped.empty or len(grouped) < 2:
            return None

        top_source = grouped.index[0]
        top_val    = grouped.iloc[0]
        total      = grouped.sum()
        share      = top_val / total * 100

        return ("📡", f"Top acquisition channel: {top_source}",
                f"\"{top_source}\" drives {_dollar_fmt(top_val)} ({share:.0f}% of revenue). "
                f"{'Double down with increased budget here.' if share > 40 else 'Balanced channel mix — good resilience.'} "
                f"Consider A/B testing messaging on your top channel.",
                "positive" if share <= 70 else "warning")
    except Exception:
        return None


def _anomaly_insight(df, col_map):
    """Detect revenue spikes and drops using z-score."""
    rev_col  = _col(col_map, "revenue")
    date_col = _col(col_map, "date")
    if not rev_col or not date_col:
        return None

    try:
        tmp = df[[date_col, rev_col]].dropna().sort_values(date_col)
        monthly = tmp.set_index(date_col).resample("ME")[rev_col].sum()

        if len(monthly) < 4:
            return None

        z = (monthly - monthly.mean()) / (monthly.std() + 1e-9)
        max_z_idx = z.abs().idxmax()
        max_z     = z[max_z_idx]

        if abs(max_z) < 1.8:
            return None  # No significant anomaly

        label = max_z_idx.strftime("%B %Y") if hasattr(max_z_idx, "strftime") else str(max_z_idx)
        val   = monthly[max_z_idx]

        if max_z > 0:
            return ("⚡", f"Revenue spike detected in {label}",
                    f"Revenue of {_dollar_fmt(val)} was {abs(max_z):.1f} standard deviations above average. "
                    f"Investigate what drove this — if it was a one-time deal, don't plan on it recurring.",
                    "warning")
        else:
            return ("⚠️", f"Revenue dip detected in {label}",
                    f"Revenue dropped to {_dollar_fmt(val)}, {abs(max_z):.1f} standard deviations below average. "
                    f"Was this seasonal, a lost account, or a pipeline gap? Identify the root cause.",
                    "negative")
    except Exception:
        return None


def _lead_trend_insight(df, col_map):
    lead_col = _col(col_map, "leads")
    date_col = _col(col_map, "date")
    if not lead_col or not date_col:
        return None

    try:
        tmp = df[[date_col, lead_col]].dropna().sort_values(date_col)
        monthly = tmp.set_index(date_col).resample("ME")[lead_col].sum()
        if len(monthly) < 3:
            return None

        x = np.arange(len(monthly))
        slope, _ = np.polyfit(x, monthly.values, 1)
        pct = slope / (monthly.mean() + 1e-9) * 100

        if pct > 5:
            return ("🌱", "Lead volume is growing",
                    f"You're generating approximately {pct:.1f}% more leads per period. "
                    f"Ensure your sales capacity can absorb the volume — don't let leads go cold.",
                    "positive")
        elif pct < -5:
            return ("🔻", "Lead volume is shrinking",
                    f"Lead generation is declining ~{abs(pct):.1f}% per period. "
                    f"Review top-of-funnel activities: SEO, ads, referral programs, and outbound.",
                    "negative")
    except Exception:
        pass
    return None


def _customer_concentration_insight(df, col_map):
    rev_col  = _col(col_map, "revenue")
    cust_col = _col(col_map, "customer")
    if not rev_col or not cust_col:
        return None

    try:
        grouped = df.groupby(cust_col)[rev_col].sum().sort_values(ascending=False)
        if len(grouped) < 2:
            return None

        total     = grouped.sum()
        top1_share = grouped.iloc[0] / total * 100
        top3_share = grouped.iloc[:3].sum() / total * 100

        top1_name = grouped.index[0]

        if top1_share > 40:
            return ("🚨", f"High customer concentration risk",
                    f"\"{top1_name}\" accounts for {top1_share:.0f}% of revenue. "
                    f"Losing this single client would be catastrophic. Prioritize diversification immediately.",
                    "negative")
        elif top3_share > 70:
            return ("⚠️", "Top 3 customers dominate revenue",
                    f"Your top 3 customers represent {top3_share:.0f}% of revenue. "
                    f"Healthy businesses aim for no single customer above 15-20% of revenue.",
                    "warning")
        else:
            return ("✅", "Healthy customer diversity",
                    f"Revenue is spread across {len(grouped)} customers. "
                    f"Top customer is {top1_share:.0f}% of total — a manageable concentration.",
                    "positive")
    except Exception:
        return None


# ---------------------------------------------------------------------------
# Main entry point
# ---------------------------------------------------------------------------

def generate_insights(df: pd.DataFrame, col_map: dict, kpis: dict) -> list[dict]:
    """
    Return a list of insight dicts:
      {icon, headline, detail, severity}
    Severity: 'positive' | 'warning' | 'negative' | 'neutral'
    """
    generators = [
        _revenue_trend_insight,
        _best_period_insight,
        _top_product_insight,
        _conversion_efficiency_insight,
        _top_source_insight,
        _anomaly_insight,
        _lead_trend_insight,
        _customer_concentration_insight,
    ]

    results = []
    for gen in generators:
        try:
            result = gen(df, col_map)
            if result:
                icon, headline, detail, severity = result
                results.append({
                    "icon": icon,
                    "headline": headline,
                    "detail": detail,
                    "severity": severity,
                })
        except Exception:
            continue

    if not results:
        results.append({
            "icon": "ℹ️",
            "headline": "Upload more data to unlock insights",
            "detail": "The insight engine needs at least date + revenue columns with several rows of data. "
                      "Include product, source, leads, and conversions for the full analysis.",
            "severity": "neutral",
        })

    return results
