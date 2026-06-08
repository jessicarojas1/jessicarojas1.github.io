"""Key Characteristics & SPC capability."""

from __future__ import annotations

from app.services.spc import capability


def test_capability_math():
    # symmetric data around 10, spec 7..13
    vals = [9, 10, 11, 10, 9, 11, 10, 10]
    cap = capability(vals, usl=13, lsl=7)
    assert cap["count"] == 8
    assert cap["mean"] == 10.0
    assert cap["cp"] is not None and cap["cpk"] is not None
    assert cap["ucl"] is not None and cap["lcl"] is not None
    # one-sided
    one = capability(vals, usl=13, lsl=None)
    assert one["cp"] is None and one["cpk"] is not None
    # empty
    assert capability([], 1, 0)["count"] == 0


def test_kc_crud_and_capability_endpoint(client, seeded, auth_headers):
    h = auth_headers("manager")  # inspection:write
    kc = client.post(
        "/api/v1/key-characteristics",
        json={
            "part_number": "PN-1",
            "characteristic": "Bore dia",
            "usl": 13,
            "lsl": 7,
            "nominal": 10,
            "kc_class": "critical",
        },
        headers=h,
    )
    assert kc.status_code == 201, kc.text
    assert kc.json()["kc_number"].startswith("KC-")
    kid = kc.json()["id"]

    for v in [9, 10, 11, 10, 9, 11, 10, 10]:
        r = client.post(
            f"/api/v1/key-characteristics/{kid}/measurements", json={"value": v}, headers=h
        )
        assert r.status_code == 201

    detail = client.get(f"/api/v1/key-characteristics/{kid}", headers=h).json()
    assert detail["capability"]["count"] == 8
    assert detail["capability"]["cpk"] is not None
    assert len(detail["measurements"]) == 8


def test_write_requires_inspection_write(client, seeded, auth_headers):
    resp = client.post(
        "/api/v1/key-characteristics",
        json={"part_number": "x", "characteristic": "y"},
        headers=auth_headers("readonly"),
    )
    assert resp.status_code == 403
    assert (
        client.get("/api/v1/key-characteristics", headers=auth_headers("readonly")).status_code
        == 200
    )
