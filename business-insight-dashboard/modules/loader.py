"""
loader.py — CSV ingestion and smart column detection.

Detects canonical column names (date, revenue, leads, conversions,
product, service, source, customer) from any reasonably named CSV.
Returns (DataFrame, col_map) where col_map maps canonical → actual column.
"""

import pandas as pd
import io
from typing import Optional

# ---------------------------------------------------------------------------
# Column alias registry — add more aliases as needed
# ---------------------------------------------------------------------------
ALIASES: dict[str, list[str]] = {
    "date":        ["date", "day", "period", "month", "week", "created_at",
                    "order_date", "sale_date", "transaction_date", "timestamp"],
    "revenue":     ["revenue", "sales", "amount", "total", "income", "gmv",
                    "gross_revenue", "net_revenue", "price", "value", "deal_value",
                    "total_revenue", "sale_amount", "payment"],
    "leads":       ["leads", "lead_count", "prospects", "inquiries", "contacts",
                    "new_leads", "inbound"],
    "conversions": ["conversions", "converted", "closed", "won", "deals_closed",
                    "orders", "purchases", "sign_ups", "signups"],
    "product":     ["product", "product_name", "item", "sku", "plan", "tier",
                    "package", "offering", "product_type"],
    "service":     ["service", "service_type", "service_name", "category",
                    "line_of_business", "lob", "department"],
    "source":      ["source", "channel", "utm_source", "marketing_channel",
                    "acquisition_source", "lead_source", "referral"],
    "customer":    ["customer", "customer_name", "client", "account", "company",
                    "buyer", "org", "organisation", "organization"],
}


def _normalize(s: str) -> str:
    return s.strip().lower().replace(" ", "_").replace("-", "_")


def detect_columns(df: pd.DataFrame) -> dict[str, Optional[str]]:
    """Return {canonical: actual_col | None} for each known canonical key."""
    actual_cols = {_normalize(c): c for c in df.columns}
    col_map: dict[str, Optional[str]] = {}

    for canonical, aliases in ALIASES.items():
        matched = None
        for alias in aliases:
            norm = _normalize(alias)
            if norm in actual_cols:
                matched = actual_cols[norm]
                break
        col_map[canonical] = matched

    return col_map


def parse_date_column(df: pd.DataFrame, col: str) -> pd.DataFrame:
    """Try to parse a column as dates; leave as-is on failure."""
    for fmt in (None, "%Y-%m-%d", "%m/%d/%Y", "%d/%m/%Y", "%Y/%m/%d",
                "%m-%d-%Y", "%d-%m-%Y", "%b %d %Y", "%B %d %Y"):
        try:
            kwargs = {"format": fmt} if fmt else {}
            parsed = pd.to_datetime(df[col], errors="raise", **kwargs)
            df = df.copy()
            df[col] = parsed
            return df
        except Exception:
            continue
    return df


def coerce_numeric(df: pd.DataFrame, col: str) -> pd.DataFrame:
    """Strip currency symbols and coerce to float."""
    if df[col].dtype == object:
        df[col] = (
            df[col]
            .astype(str)
            .str.replace(r"[$,£€¥\s]", "", regex=True)
            .str.replace(r"[^\d.\-]", "", regex=True)
        )
    df[col] = pd.to_numeric(df[col], errors="coerce")
    return df


def load_and_detect(file_obj) -> tuple[pd.DataFrame, dict[str, Optional[str]]]:
    """
    Load a CSV from a file-like object, detect canonical columns,
    coerce types, and return (df, col_map).
    """
    try:
        df = pd.read_csv(file_obj)
    except Exception as e:
        raise ValueError(f"Could not parse CSV: {e}")

    if df.empty or len(df.columns) < 1:
        raise ValueError("CSV is empty or has no columns.")

    df.columns = [str(c).strip() for c in df.columns]
    col_map = detect_columns(df)

    # Coerce date
    if col_map.get("date"):
        df = parse_date_column(df, col_map["date"])

    # Coerce numeric fields
    for key in ("revenue", "leads", "conversions"):
        if col_map.get(key):
            df = coerce_numeric(df, col_map[key])

    return df, col_map


def missing_columns(col_map: dict) -> list[str]:
    """Return list of canonical columns that were not found."""
    return [k for k, v in col_map.items() if v is None]


def available_columns(col_map: dict) -> list[str]:
    """Return list of canonical columns that were found."""
    return [k for k, v in col_map.items() if v is not None]
