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


def detect_violations(values: list[float]) -> list[dict]:
    """Detect Western Electric control-chart rule violations.

    Returns one entry per flagged point: ``{rule, index, value, description}``
    (index is 1-based, matching the chart's sample numbering). Implements the
    four classic rules; needs >=2 points for a meaningful sigma.
    """
    n = len(values)
    if n < 2:
        return []
    mean = sum(values) / n
    std = statistics.stdev(values)
    out: list[dict] = []
    if std <= 0:
        return out

    def z(v: float) -> float:
        return (v - mean) / std

    # Rule 1 — any single point beyond 3 sigma.
    for i, v in enumerate(values):
        if abs(z(v)) > 3:
            out.append(
                {"rule": 1, "index": i + 1, "value": v, "description": "Point beyond 3σ"}
            )

    # Rule 2 — 9 consecutive points on the same side of the mean.
    run_side = 0
    run_len = 0
    for i, v in enumerate(values):
        side = 1 if v > mean else -1 if v < mean else 0
        if side != 0 and side == run_side:
            run_len += 1
        else:
            run_side, run_len = side, 1 if side != 0 else 0
        if run_len == 9:
            out.append(
                {"rule": 2, "index": i + 1, "value": v, "description": "9 points one side of mean"}
            )

    # Rule 3 — 6 consecutive points steadily increasing or decreasing.
    inc = dec = 1
    for i in range(1, n):
        inc = inc + 1 if values[i] > values[i - 1] else 1
        dec = dec + 1 if values[i] < values[i - 1] else 1
        if inc == 6 or dec == 6:
            out.append(
                {"rule": 3, "index": i + 1, "value": values[i], "description": "6-point trend"}
            )

    # Rule 4 — 2 of 3 consecutive points beyond 2 sigma on the same side.
    for i in range(2, n):
        window = values[i - 2 : i + 1]
        for sign in (1, -1):
            beyond = [v for v in window if z(v) * sign > 2]
            if len(beyond) >= 2 and z(values[i]) * sign > 2:
                out.append(
                    {
                        "rule": 4,
                        "index": i + 1,
                        "value": values[i],
                        "description": "2 of 3 beyond 2σ",
                    }
                )
                break
    return out
