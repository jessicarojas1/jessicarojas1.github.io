"""Management-review auto-inputs include the new clause-9.3 modules."""

from __future__ import annotations

from app.models.customer import Customer
from app.models.customer_satisfaction import CustomerSurvey
from app.models.improvement import Improvement, ImprovementStatus
from app.models.quality_objective import ObjectiveDirection, QualityObjective
from app.services import kpi


def test_review_inputs_include_objectives_improvement_and_csat(db_session, seeded):
    db_session.add(
        QualityObjective(
            objective_number="QO-T-1",
            title="On-time delivery",
            target_value=98,
            current_value=99,  # meeting target
            direction=ObjectiveDirection.HIGHER_BETTER,
        )
    )
    db_session.add(
        Improvement(
            improvement_number="KAI-T-1",
            title="Cell 3 setup reduction",
            status=ImprovementStatus.DONE,
            realized_benefit=5000,
        )
    )
    cust = Customer(code="CST-T", name="Lockheed", status="active")
    db_session.add(cust)
    db_session.flush()
    db_session.add(
        CustomerSurvey(survey_number="CSAT-T-1", customer_id=cust.id, overall_score=88)
    )
    db_session.commit()

    rows = kpi.management_review_inputs(db_session)
    by_cat = {r["category"]: r for r in rows}

    assert "Quality Objectives & KPI Performance" in by_cat
    assert "1/1 met" in by_cat["Quality Objectives & KPI Performance"]["metric_value"]

    assert "Continual Improvement" in by_cat
    assert "5,000" in by_cat["Continual Improvement"]["content"]

    # Customer-satisfaction row is enriched with the survey average.
    assert "88.0% avg" in by_cat["Customer Satisfaction & Complaints"]["content"]


def test_objective_metrics_handles_no_data(db_session, seeded):
    m = kpi.quality_objective_metrics(db_session)
    assert m == {"total": 0, "measured": 0, "met": 0, "avg_attainment": None}
    assert kpi.customer_survey_metrics(db_session)["average_overall"] is None
