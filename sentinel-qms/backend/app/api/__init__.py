"""Aggregate all v1 routers under the configured API prefix."""

from __future__ import annotations

from fastapi import APIRouter

from app.api.routers import (
    analytics,
    apqp,
    attachments,
    audit_logs,
    audits,
    auth,
    calibration,
    capa,
    changes,
    comments,
    complaints,
    counterfeit,
    dashboard,
    documents,
    iam,
    inspections,
    mgmt_reviews,
    nonconformances,
    notifications,
    permissions,
    reports,
    risks,
    search,
    settings,
    standards,
    suppliers,
    training,
    users,
)

api_router = APIRouter()

api_router.include_router(auth.router)
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
