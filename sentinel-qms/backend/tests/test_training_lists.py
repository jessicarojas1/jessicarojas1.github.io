"""Training records list + competency matrix endpoints."""

from __future__ import annotations


def _setup(client, h):
    pid = client.post(
        "/api/v1/training/personnel",
        json={"employee_id": "E-1", "full_name": "Dana Lee", "department": "Quality"},
        headers=h,
    ).json()["id"]
    cid = client.post(
        "/api/v1/training/courses",
        json={"course_code": "TRN-1", "title": "AS9100 Awareness"},
        headers=h,
    ).json()["id"]
    client.post(
        "/api/v1/training/assign",
        json={"personnel_id": pid, "course_id": cid},
        headers=h,
    )
    client.post(
        "/api/v1/training/competency",
        json={
            "personnel_id": pid,
            "skill": "Inspection",
            "required_level": "expert",
            "current_level": "practitioner",
        },
        headers=h,
    )
    return pid, cid


def test_training_records_list(client, seeded, auth_headers):
    h = auth_headers("manager")
    _setup(client, h)
    r = client.get("/api/v1/training", headers=h)
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["total"] >= 1
    item = body["items"][0]
    assert item["employee_name"] == "Dana Lee"
    assert item["course"] == "AS9100 Awareness"
    assert item["course_code"] == "TRN-1"


def test_competency_matrix(client, seeded, auth_headers):
    h = auth_headers("manager")
    _setup(client, h)
    r = client.get("/api/v1/training/competency-matrix", headers=h)
    assert r.status_code == 200, r.text
    m = r.json()
    assert "Inspection" in m["competencies"]
    row = next(x for x in m["rows"] if x["employee_id"] == "E-1")
    cell = next(c for c in row["cells"] if c["competency"] == "Inspection")
    assert cell["level"] == 2  # practitioner
