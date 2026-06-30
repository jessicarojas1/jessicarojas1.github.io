"""Aggregate all v1 routers under the configured API prefix."""

from __future__ import annotations

from fastapi import APIRouter

from app.api.routers import (
    analytics,
    api_tokens,
    apqp,
    attachments,
    audit_logs,
    audit_programs,
    audits,
    auth,
    calibration,
    capa,
    changes,
    comments,
    complaints,
    concessions,
    counterfeit,
    customer_satisfaction,
    customers,
    dashboard,
    documents,
    fmea,
    fod,
    iam,
    improvements,
    inspections,
    lessons,
    mgmt_reviews,
    msa,
    nonconformances,
    notifications,
    permissions,
    quality_objectives,
    record_shares,
    reports,
    risks,
    saved_views,
    search,
    settings,
    signatures,
    spc,
    standards,
    suppliers,
    training,
    users,
    webhooks,
)

api_router = APIRouter()

api_router.include_router(auth.router)
api_router.include_router(api_tokens.router)
api_router.include_router(users.router)
api_router.include_router(documents.router)
api_router.include_router(nonconformances.router)
api_router.include_router(capa.router)
api_router.include_router(audits.router)
api_router.include_router(suppliers.router)
api_router.include_router(calibration.router)
api_router.include_router(training.router)
api_router.include_router(changes.router)
api_router.include_router(risks.router)
api_router.include_router(inspections.router)
api_router.include_router(mgmt_reviews.router)
api_router.include_router(complaints.router)
api_router.include_router(comments.router)
api_router.include_router(dashboard.router)
api_router.include_router(attachments.router)
api_router.include_router(search.router)
api_router.include_router(audit_logs.router)
api_router.include_router(analytics.router)
api_router.include_router(reports.router)
api_router.include_router(notifications.router)
api_router.include_router(permissions.router)
api_router.include_router(iam.router)
api_router.include_router(settings.router)
api_router.include_router(standards.router)
api_router.include_router(counterfeit.router)
api_router.include_router(apqp.router)
api_router.include_router(fod.router)
api_router.include_router(concessions.router)
api_router.include_router(signatures.router)
api_router.include_router(customers.router)
api_router.include_router(audit_programs.router)
api_router.include_router(msa.router)
api_router.include_router(spc.router)
api_router.include_router(saved_views.router)
api_router.include_router(record_shares.router)
api_router.include_router(quality_objectives.router)
api_router.include_router(improvements.router)
api_router.include_router(lessons.router)
api_router.include_router(customer_satisfaction.router)
api_router.include_router(fmea.router)
api_router.include_router(webhooks.router)
