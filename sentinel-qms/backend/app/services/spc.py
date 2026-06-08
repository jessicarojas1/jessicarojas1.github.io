"""Process-capability statistics for Key Characteristics (Cp / Cpk)."""

from __future__ import annotations

import statistics


def capability(values: list[float], usl: float | None, lsl: float | None) -> dict:
    """Compute capability + control-chart stats for a list of measurements.

    Returns count, mean, std (sample), Cp, Cpk and individuals-chart control
    limits (mean ± 3σ). Cp needs both spec limits; Cpk uses whichever limits
    are present (one- or two-sided).
    """
    n = len(values)
    if n == 0:
        return {
            "count": 0,
            "mean": None,
            "std": None,
            "cp": None,
            "cpk": None,
            "ucl": None,
            "lcl": None,
            "min": None,
            "max": None,
        }

    mean = sum(values) / n
    std = statistics.stdev(values) if n >= 2 else 0.0

    cp: float | None = None
    cpk: float | None = None
    if std > 0:
        if usl is not None and lsl is not None:
            cp = (usl - lsl) / (6 * std)
            cpk = min((usl - mean) / (3 * std), (mean - lsl) / (3 * std))
        elif usl is not None:
            cpk = (usl - mean) / (3 * std)
        elif lsl is not None:
            cpk = (mean - lsl) / (3 * std)

    return {
        "count": n,
        "mean": round(mean, 4),
        "std": round(std, 4),
        "cp": round(cp, 3) if cp is not None else None,
        "cpk": round(cpk, 3) if cpk is not None else None,
        "ucl": round(mean + 3 * std, 4) if std > 0 else None,
        "lcl": round(mean - 3 * std, 4) if std > 0 else None,
        "min": round(min(values), 4),
        "max": round(max(values), 4),
    }
