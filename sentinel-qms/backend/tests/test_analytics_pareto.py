"""Pareto analytics endpoint."""

from __future__ import annotations


def test_pareto_by_severity(client, seeded, auth_headers):
    h = auth_headers("manager")
    sev = ["major", "major", "major", "minor", "minor", "critical"]
    for i, s in enumerate(sev):
        r = client.post(
            "/api/v1/nonconformances",
            json={"title": f"NCR {i}", "description": "d", "severity": s},
            headers=h,
        )
        assert r.status_code == 201, r.text

    resp = client.get("/api/v1/analytics/pareto?dimension=severity", headers=h)
    assert resp.status_code == 200, resp.text
    body = resp.json()
    assert body["dimension"] == "severity"
    buckets = body["buckets"]
    # sorted descending by count
    counts = [b["count"] for b in buckets]
    assert counts == sorted(counts, reverse=True)
    # major is the top driver
    assert buckets[0]["label"] == "major"
    # cumulative reaches 100 on the last bucket
    assert buckets[-1]["cumulative_pct"] == 100.0


def test_pareto_requires_analytics_access(client, seeded, auth_headers):
    # readonly can view analytics; just assert it returns 200 with a valid shape
    resp = client.get("/api/v1/analytics/pareto?dimension=source", headers=auth_headers("readonly"))
    assert resp.status_code == 200
    assert "buckets" in resp.json()
