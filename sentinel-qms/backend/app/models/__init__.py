"""Aggregate all ORM models so ``Base.metadata`` is fully populated on import."""

from __future__ import annotations

from app.models.apqp import (
    ApqpPhase,
    ApqpProject,
    ApqpStatus,
    PpapElement,
    PpapElementStatus,
)
from app.models.audit_mgmt import (
    Audit,
    AuditChecklistItem,
    AuditFinding,
    AuditStatus,
    AuditType,
    FindingStatus,
    FindingType,
)
from app.models.base import SoftDeleteMixin, TimestampMixin
from app.models.calibration import (
    CalibrationRecord,
    CalibrationResult,
    Equipment,
    EquipmentStatus,
)
from app.models.capa import (
    Capa,
    CapaAction,
    CapaActionStatus,
    CapaStatus,
    CapaType,
)
from app.models.change import (
    ChangeOrder,
    ChangePriority,
    ChangeStatus,
    ChangeType,
)
from app.models.comment import Comment
from app.models.complaint import Complaint, ComplaintSeverity, ComplaintStatus
from app.models.counterfeit import (
    AlertSource,
    AlertStatus,
    CounterfeitAlert,
    PartSourcingRecord,
    RiskLevel,
    SourceType,
    VerificationStatus,
)
from app.models.document import (
    Department,
    Document,
    DocumentApproval,
    DocumentRevision,
    DocumentStatus,
    DocumentType,
)
from app.models.fod import FodEvent, FodRisk, FodSeverity, FodStatus, FodZone
from app.models.iam import UserPermissionGrant
from app.models.inspection import (
    FaiCharacteristic,
    FaiReport,
    FaiType,
    Inspection,
    InspectionResult,
    InspectionType,
)
from app.models.mgmt_review import (
    ActionItem,
    ActionItemStatus,
    ManagementReview,
    ManagementReviewInput,
    ReviewStatus,
)
from app.models.nonconformance import (
    DispositionType,
    NcSeverity,
    NcStatus,
    Nonconformance,
    NonconformanceDisposition,
)
from app.models.permission import RolePagePermission, UserPagePermission
from app.models.risk import (
    Risk,
    RiskCategory,
    RiskStatus,
    TreatmentStrategy,
)
from app.models.settings import OrgSettings
from app.models.sla import SlaEscalation
from app.models.standard import CoverageStatus, Standard, StandardRequirement
from app.models.supplier import (
    ApprovedSupplierListEntry,
    ScarStatus,
    Supplier,
    SupplierRating,
    SupplierScar,
    SupplierStatus,
)
from app.models.training import (
    CompetencyLevel,
    CompetencyMatrixEntry,
    Personnel,
    TrainingCourse,
    TrainingRecord,
    TrainingStatus,
)
from app.models.user import (
    Attachment,
    AuditLog,
    ElectronicSignature,
    Notification,
    Role,
    User,
    user_roles,
)

__all__ = [
    "TimestampMixin",
    "SoftDeleteMixin",
    "User",
    "Role",
    "AuditLog",
    "ElectronicSignature",
    "Attachment",
    "Notification",
    "RolePagePermission",
    "UserPagePermission",
    "UserPermissionGrant",
    "user_roles",
    "Department",
    "Document",
    "DocumentRevision",
    "DocumentApproval",
    "DocumentStatus",
    "DocumentType",
    "Nonconformance",
    "NonconformanceDisposition",
    "NcSeverity",
    "NcStatus",
    "DispositionType",
    "Capa",
    "CapaAction",
    "CapaStatus",
    "CapaType",
    "CapaActionStatus",
    "Audit",
    "AuditFinding",
    "AuditChecklistItem",
    "AuditType",
    "AuditStatus",
    "FindingType",
    "FindingStatus",
    "Supplier",
    "SupplierScar",
    "ApprovedSupplierListEntry",
    "SupplierRating",
    "SupplierStatus",
    "ScarStatus",
    "Equipment",
    "CalibrationRecord",
    "EquipmentStatus",
    "CalibrationResult",
    "Personnel",
    "TrainingCourse",
    "TrainingRecord",
    "CompetencyMatrixEntry",
    "TrainingStatus",
    "CompetencyLevel",
    "ChangeOrder",
    "ChangeType",
    "ChangeStatus",
    "ChangePriority",
    "Risk",
    "RiskCategory",
    "RiskStatus",
    "TreatmentStrategy",
    "Inspection",
    "FaiReport",
    "FaiCharacteristic",
    "InspectionType",
    "InspectionResult",
    "FaiType",
    "ManagementReview",
    "ManagementReviewInput",
    "ActionItem",
    "ReviewStatus",
    "ActionItemStatus",
    "Complaint",
    "ComplaintStatus",
    "ComplaintSeverity",
    "Comment",
    "OrgSettings",
    "SlaEscalation",
    "Standard",
    "StandardRequirement",
    "CoverageStatus",
    "PartSourcingRecord",
    "CounterfeitAlert",
    "SourceType",
    "RiskLevel",
    "VerificationStatus",
    "AlertSource",
    "AlertStatus",
    "ApqpProject",
    "PpapElement",
    "ApqpPhase",
    "ApqpStatus",
    "PpapElementStatus",
    "FodZone",
    "FodEvent",
    "FodRisk",
    "FodSeverity",
    "FodStatus",
]
