"""
kpis.py — KPI computation from a detected DataFrame.

All functions accept (df, col_map) and return plain Python values
so the UI layer stays clean.
"""

import pandas as pd
import numpy as np
from typing import Optional


def _col(col_map: dict, key: str) -> Optional[str]:
    return col_map.get(key)


def compute_kpis(df: pd.DataFrame, col_map: dict) -> dict:
    """
    Compute all headline KPIs.  Returns a flat dict — missing metrics are None.
    """
    kpis: dict = {}

    rev_col = _col(col_map, "revenue")
    lead_col = _col(col_map, "leads")
    conv_col = _col(col_map, "conversions")
    date_col = _col(col_map, "date")

    # ── Revenue ─────────────────────────────────────────────────────────────
    if rev_col:
        series = df[rev_col].dropna()
        kpis["total_revenue"] = series.sum()
        kpis["avg_revenue"] = series.mean()
        kpis["max_revenue"] = series.max()
        kpis["min_revenue"] = series.min()
        kpis["revenue_count"] = len(series)
    else:
        kpis["total_revenue"] = None
        kpis["avg_revenue"] = None
        kpis["max_revenue"] = None
        kpis["min_revenue"] = None
        kpis["revenue_count"] = None

    # ── Leads ────────────────────────────────────────────────────────────────
    if lead_col:
        series = df[lead_col].dropna()
        kpis["total_leads"] = series.sum()
        kpis["avg_leads"] = series.mean()
    else:
        kpis["total_leads"] = None
        kpis["avg_leads"] = None

    # ── Conversions ──────────────────────────────────────────────────────────
    if conv_col:
        series = df[conv_col].dropna()
        kpis["total_conversions"] = series.sum()
        kpis["avg_conversions"] = series.mean()
    else:
        kpis["total_conversions"] = len(df) if rev_col else None
        kpis["avg_conversions"] = None

    # ── Conversion Rate ──────────────────────────────────────────────────────
    if kpis["total_leads"] and kpis["total_conversions"]:
        total_leads = float(kpis["total_leads"])
        total_conv = float(kpis["total_conversions"])
        kpis["conversion_rate"] = (total_conv / total_leads * 100) if total_leads > 0 else None
    else:
        kpis["conversion_rate"] = None

    # ── Revenue per Conversion ───────────────────────────────────────────────
    if kpis["total_revenue"] and kpis["total_conversions"]:
        tc = float(kpis["total_conversions"])
        kpis["rev_per_conversion"] = kpis["total_revenue"] / tc if tc > 0 else None
    else:
        kpis["rev_per_conversion"] = None

    # ── Period-over-Period Growth (last half vs first half) ──────────────────
    if rev_col and date_col:
        try:
            sorted_df = df.sort_values(date_col).dropna(subset=[rev_col])
            mid = len(sorted_df) // 2
            if mid > 0:
                first_half = sorted_df.iloc[:mid][rev_col].sum()
                second_half = sorted_df.iloc[mid:][rev_col].sum()
                if first_half > 0:
                    kpis["growth_pct"] = (second_half - first_half) / first_half * 100
                else:
                    kpis["growth_pct"] = None
            else:
                kpis["growth_pct"] = None
        except Exception:
            kpis["growth_pct"] = None
    else:
        kpis["growth_pct"] = None

    # ── Best period ──────────────────────────────────────────────────────────
    if rev_col and date_col:
        try:
            idx = df[rev_col].idxmax()
            best_row = df.loc[idx]
            best_date = best_row[date_col]
            if hasattr(best_date, "strftime"):
                kpis["best_period"] = best_date.strftime("%b %d, %Y")
            else:
                kpis["best_period"] = str(best_date)
            kpis["best_period_revenue"] = best_row[rev_col]
        except Exception:
            kpis["best_period"] = None
            kpis["best_period_revenue"] = None
    else:
        kpis["best_period"] = None
        kpis["best_period_revenue"] = None

    # ── Unique counts ────────────────────────────────────────────────────────
    for key in ("product", "service", "source", "customer"):
        col = _col(col_map, key)
        if col:
            kpis[f"unique_{key}s"] = df[col].nunique()
        else:
            kpis[f"unique_{key}s"] = None

    return kpis


def revenue_by_period(df: pd.DataFrame, col_map: dict,
                       freq: str = "ME") -> Optional[pd.DataFrame]:
    """Aggregate revenue by time period. freq: 'D','W','M','Q','Y'."""
    rev_col = _col(col_map, "revenue")
    date_col = _col(col_map, "date")
    if not rev_col or not date_col:
        return None
    try:
        tmp = df[[date_col, rev_col]].dropna()
        tmp = tmp.set_index(date_col).resample(freq)[rev_col].sum().reset_index()
        tmp.columns = ["period", "revenue"]
        return tmp
    except Exception:
        return None


def leads_by_period(df: pd.DataFrame, col_map: dict,
                    freq: str = "ME") -> Optional[pd.DataFrame]:
    lead_col = _col(col_map, "leads")
    date_col = _col(col_map, "date")
    if not lead_col or not date_col:
        return None
    try:
        tmp = df[[date_col, lead_col]].dropna()
        tmp = tmp.set_index(date_col).resample(freq)[lead_col].sum().reset_index()
        tmp.columns = ["period", "leads"]
        return tmp
    except Exception:
        return None


def top_by_column(df: pd.DataFrame, col_map: dict,
                  group_key: str, n: int = 10) -> Optional[pd.DataFrame]:
    """Return top-N rows by revenue grouped on group_key column."""
    group_col = _col(col_map, group_key)
    rev_col = _col(col_map, "revenue")
    if not group_col or not rev_col:
        return None
    try:
        result = (
            df.groupby(group_col)[rev_col]
            .sum()
            .sort_values(ascending=False)
            .head(n)
            .reset_index()
        )
        result.columns = [group_key, "revenue"]
        return result
    except Exception:
        return None


def conversions_by_column(df: pd.DataFrame, col_map: dict,
                          group_key: str) -> Optional[pd.DataFrame]:
    """Return conversion counts grouped on group_key column."""
    group_col = _col(col_map, group_key)
    conv_col = _col(col_map, "conversions")
    if not group_col:
        return None
    try:
        if conv_col:
            result = (
                df.groupby(group_col)[conv_col]
                .sum()
                .sort_values(ascending=False)
                .reset_index()
            )
            result.columns = [group_key, "conversions"]
        else:
            result = (
                df.groupby(group_col)
                .size()
                .sort_values(ascending=False)
                .reset_index()
            )
            result.columns = [group_key, "conversions"]
        return result
    except Exception:
        return None
