"""SPC Western Electric violation detection."""

from __future__ import annotations

from app.services.spc import detect_violations

# Tight in-control baseline (small sigma) so a single outlier truly exceeds 3 sigma.
_BASE = [10.0, 9.9, 10.1, 10.0, 9.95, 10.05, 10.0, 9.98, 10.02, 9.97, 10.03, 10.0]


def test_rule1_point_beyond_3sigma():
    v = detect_violations([*_BASE, 12.0])
    assert any(x["rule"] == 1 for x in v)


def test_rule2_nine_on_one_side():
    vals = [10.1] * 9 + [9.0]
    v = detect_violations(vals)
    assert any(x["rule"] == 2 for x in v)


def test_rule3_six_point_trend():
    vals = [1, 2, 3, 4, 5, 6, 7]
    v = detect_violations(vals)
    assert any(x["rule"] == 3 for x in v)


def test_stable_process_no_violations():
    vals = [10, 9.9, 10.1, 10, 9.95, 10.05, 10, 9.98]
    assert detect_violations(vals) == []


def test_kc_detail_exposes_violations(client, seeded, auth_headers):
    h = auth_headers("manager")
    kid = client.post(
        "/api/v1/key-characteristics",
        json={"part_number": "PN-V", "characteristic": "dia", "usl": 20, "lsl": 0},
        headers=h,
    ).json()["id"]
    for val in [*_BASE, 13.0]:
        client.post(
            f"/api/v1/key-characteristics/{kid}/measurements",
            json={"value": val},
            headers=h,
        )
    detail = client.get(f"/api/v1/key-characteristics/{kid}", headers=h).json()
    assert "violations" in detail
    assert any(x["rule"] == 1 for x in detail["violations"])
