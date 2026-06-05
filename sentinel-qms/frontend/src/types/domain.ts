/**
 * Domain types mirroring the Sentinel QMS backend schemas.
 * Each interface corresponds to a REST resource under /api/v1.
 */
import type { Attachment, AuditTrailEntry, ElectronicSignature, Iso8601 } from './common';

/* ------------------------------------------------------------------ */
/* Documents — controlled document register w/ revisions & approvals   */
/* ------------------------------------------------------------------ */

export type DocumentStatus =
  | 'draft'
  | 'in_review'
  | 'approved'
  | 'released'
  | 'obsolete';

export interface DocumentRevision {
  id: string;
  revision: string;
  status: DocumentStatus;
  summary: string;
  author: string;
  approved_by?: string;
  approved_at?: Iso8601;
  effective_date?: Iso8601;
  created_at: Iso8601;
  signature?: ElectronicSignature;
}

export interface ControlledDocument {
  id: string;
  doc_number: string;
  title: string;
  doc_type: string;
  category?: string;
  current_revision: string;
  status: DocumentStatus;
  owner: string;
  department?: string;
  effective_date?: Iso8601;
  next_review_date?: Iso8601;
  created_at: Iso8601;
  updated_at: Iso8601;
  revisions?: DocumentRevision[];
  attachments?: Attachment[];
}

/* ------------------------------------------------------------------ */
/* Nonconformances (NCR) + MRB disposition                             */
/* ------------------------------------------------------------------ */

export type NcrStatus =
  | 'open'
  | 'under_review'
  | 'disposition_pending'
  | 'dispositioned'
  | 'closed'
  | 'cancelled';

export type NcrSeverity = 'minor' | 'major' | 'critical';

export type DispositionType =
  | 'use_as_is'
  | 'rework'
  | 'repair'
  | 'scrap'
  | 'return_to_supplier'
  | 'regrade';

export interface Disposition {
  id: string;
  type: DispositionType;
  justification: string;
  mrb_required: boolean;
  mrb_members?: string[];
  dispositioned_by: string;
  dispositioned_at: Iso8601;
  signature?: ElectronicSignature;
}

export interface Nonconformance {
  id: string;
  ncr_number: string;
  title: string;
  description: string;
  status: NcrStatus;
  severity: NcrSeverity;
  part_number?: string;
  lot_number?: string;
  quantity_affected?: number;
  source: string;
  detected_at: Iso8601;
  detected_by: string;
  assigned_to?: string;
  supplier_id?: string;
  supplier_name?: string;
  disposition?: Disposition;
  linked_capa_id?: string;
  due_date?: Iso8601;
  closed_at?: Iso8601;
  created_at: Iso8601;
  updated_at: Iso8601;
  attachments?: Attachment[];
  audit_trail?: AuditTrailEntry[];
}

/* ------------------------------------------------------------------ */
/* CAPA — 8D problem solving + effectiveness verification              */
/* ------------------------------------------------------------------ */

export type CapaStatus =
  | 'open'
  | 'investigation'
  | 'action_planned'
  | 'implementation'
  | 'verification'
  | 'closed'
  | 'cancelled';

export type CapaType = 'corrective' | 'preventive';

export type EightDStepKey =
  | 'd1_team'
  | 'd2_problem'
  | 'd3_containment'
  | 'd4_root_cause'
  | 'd5_corrective_action'
  | 'd6_implementation'
  | 'd7_prevention'
  | 'd8_closure';

export interface EightDStep {
  key: EightDStepKey;
  title: string;
  content: string;
  completed: boolean;
  completed_by?: string;
  completed_at?: Iso8601;
}

export interface EffectivenessCheck {
  id: string;
  method: string;
  criteria: string;
  result?: 'effective' | 'not_effective' | 'pending';
  verified_by?: string;
  verified_at?: Iso8601;
  due_date?: Iso8601;
}

export interface Capa {
  id: string;
  capa_number: string;
  title: string;
  description: string;
  type: CapaType;
  status: CapaStatus;
  priority: 'low' | 'medium' | 'high' | 'critical';
  owner: string;
  source: string;
  source_ref?: string;
  root_cause?: string;
  eight_d?: EightDStep[];
  effectiveness?: EffectivenessCheck;
  opened_at: Iso8601;
  due_date?: Iso8601;
  closed_at?: Iso8601;
  created_at: Iso8601;
  updated_at: Iso8601;
  attachments?: Attachment[];
  signature?: ElectronicSignature;
}

/* ------------------------------------------------------------------ */
/* Audits + findings                                                   */
/* ------------------------------------------------------------------ */

export type AuditType = 'internal' | 'external' | 'supplier' | 'certification';
export type AuditStatus = 'planned' | 'in_progress' | 'reporting' | 'closed';
export type FindingType = 'major_nc' | 'minor_nc' | 'observation' | 'ofi';

export interface AuditFinding {
  id: string;
  reference: string;
  clause: string;
  type: FindingType;
  description: string;
  evidence?: string;
  linked_capa_id?: string;
  status: 'open' | 'responded' | 'closed';
}

export interface Audit {
  id: string;
  audit_number: string;
  title: string;
  type: AuditType;
  status: AuditStatus;
  standard: string;
  scope: string;
  lead_auditor: string;
  auditee?: string;
  supplier_id?: string;
  planned_date?: Iso8601;
  start_date?: Iso8601;
  end_date?: Iso8601;
  findings?: AuditFinding[];
  created_at: Iso8601;
  updated_at: Iso8601;
}

/* ------------------------------------------------------------------ */
/* Suppliers — ASL, SCAR, ratings                                      */
/* ------------------------------------------------------------------ */

export type SupplierStatus = 'approved' | 'conditional' | 'probation' | 'disqualified';

export interface Scar {
  id: string;
  scar_number: string;
  issue: string;
  status: 'issued' | 'responded' | 'verified' | 'closed';
  issued_at: Iso8601;
  due_date?: Iso8601;
}

export interface Supplier {
  id: string;
  code: string;
  name: string;
  status: SupplierStatus;
  category?: string;
  approved_scope?: string;
  certifications?: string[];
  rating: number;
  on_time_delivery: number;
  quality_ppm: number;
  open_scars: number;
  contact_name?: string;
  contact_email?: string;
  last_audit_date?: Iso8601;
  next_audit_date?: Iso8601;
  scars?: Scar[];
  created_at: Iso8601;
  updated_at: Iso8601;
}

/* ------------------------------------------------------------------ */
/* Calibration — equipment register                                    */
/* ------------------------------------------------------------------ */

export type CalibrationStatus = 'in_tolerance' | 'out_of_tolerance' | 'limited_use' | 'out_of_service';

export interface CalibrationRecord {
  id: string;
  performed_at: Iso8601;
  performed_by: string;
  result: CalibrationStatus;
  certificate_number?: string;
  next_due?: Iso8601;
}

export interface Equipment {
  id: string;
  asset_tag: string;
  name: string;
  manufacturer?: string;
  model?: string;
  serial_number?: string;
  location?: string;
  status: CalibrationStatus;
  calibration_interval_days: number;
  last_calibrated?: Iso8601;
  next_due?: Iso8601;
  custodian?: string;
  history?: CalibrationRecord[];
  created_at: Iso8601;
  updated_at: Iso8601;
}

/* ------------------------------------------------------------------ */
/* Training + competency                                               */
/* ------------------------------------------------------------------ */

export interface TrainingRecord {
  id: string;
  employee_id: string;
  employee_name: string;
  course: string;
  course_code?: string;
  status: 'assigned' | 'in_progress' | 'completed' | 'overdue';
  assigned_at: Iso8601;
  completed_at?: Iso8601;
  due_date?: Iso8601;
  score?: number;
}

export interface CompetencyCell {
  competency: string;
  level: 0 | 1 | 2 | 3 | 4;
}

export interface CompetencyRow {
  employee_id: string;
  employee_name: string;
  department?: string;
  cells: CompetencyCell[];
}

export interface CompetencyMatrix {
  competencies: string[];
  rows: CompetencyRow[];
}

/* ------------------------------------------------------------------ */
/* Engineering Changes — ECN / ECO                                     */
/* ------------------------------------------------------------------ */

export type ChangeStatus = 'draft' | 'submitted' | 'in_review' | 'approved' | 'implemented' | 'rejected';

export interface ChangeRequest {
  id: string;
  change_number: string;
  title: string;
  type: 'ecn' | 'eco' | 'deviation' | 'waiver';
  status: ChangeStatus;
  description: string;
  reason: string;
  affected_items?: string[];
  originator: string;
  priority: 'low' | 'medium' | 'high';
  ccb_required: boolean;
  submitted_at?: Iso8601;
  approved_at?: Iso8601;
  effective_date?: Iso8601;
  created_at: Iso8601;
  updated_at: Iso8601;
  signature?: ElectronicSignature;
}

/* ------------------------------------------------------------------ */
/* Risk register — RPN heat map                                        */
/* ------------------------------------------------------------------ */

export type RiskStatus = 'identified' | 'assessed' | 'mitigating' | 'accepted' | 'closed';

export interface Risk {
  id: string;
  risk_number: string;
  title: string;
  description: string;
  category: string;
  status: RiskStatus;
  severity: number; // 1-10
  occurrence: number; // 1-10
  detection: number; // 1-10
  rpn: number; // severity * occurrence * detection
  mitigation?: string;
  owner: string;
  residual_rpn?: number;
  review_date?: Iso8601;
  created_at: Iso8601;
  updated_at: Iso8601;
}

/* ------------------------------------------------------------------ */
/* Inspections — FAI / AS9102                                          */
/* ------------------------------------------------------------------ */

export type InspectionResult = 'pass' | 'fail' | 'conditional' | 'pending';

export interface InspectionCharacteristic {
  id: string;
  number: string;
  requirement: string;
  nominal?: string;
  tolerance?: string;
  actual?: string;
  result: InspectionResult;
}

export interface Inspection {
  id: string;
  fai_number: string;
  part_number: string;
  part_name?: string;
  revision?: string;
  type: 'fai' | 'in_process' | 'final' | 'receiving';
  result: InspectionResult;
  inspector: string;
  supplier_id?: string;
  drawing_number?: string;
  characteristics?: InspectionCharacteristic[];
  performed_at?: Iso8601;
  created_at: Iso8601;
  updated_at: Iso8601;
}

/* ------------------------------------------------------------------ */
/* Management reviews                                                   */
/* ------------------------------------------------------------------ */

export type MgmtReviewStatus = 'scheduled' | 'in_progress' | 'completed';

export interface MgmtReviewAction {
  id: string;
  description: string;
  owner: string;
  due_date?: Iso8601;
  status: 'open' | 'closed';
}

export interface MgmtReview {
  id: string;
  review_number: string;
  title: string;
  status: MgmtReviewStatus;
  scheduled_date: Iso8601;
  held_date?: Iso8601;
  chair: string;
  attendees?: string[];
  inputs?: string[];
  outputs?: string[];
  actions?: MgmtReviewAction[];
  created_at: Iso8601;
  updated_at: Iso8601;
}

/* ------------------------------------------------------------------ */
/* Complaints — RMA                                                    */
/* ------------------------------------------------------------------ */

export type ComplaintStatus = 'received' | 'investigating' | 'rma_issued' | 'resolved' | 'closed';

export interface Complaint {
  id: string;
  complaint_number: string;
  customer: string;
  product?: string;
  part_number?: string;
  description: string;
  status: ComplaintStatus;
  severity: 'low' | 'medium' | 'high';
  rma_number?: string;
  received_at: Iso8601;
  assigned_to?: string;
  linked_ncr_id?: string;
  linked_capa_id?: string;
  resolution?: string;
  closed_at?: Iso8601;
  created_at: Iso8601;
  updated_at: Iso8601;
}

/* ------------------------------------------------------------------ */
/* Dashboard                                                            */
/* ------------------------------------------------------------------ */

export interface TrendPoint {
  period: string;
  value: number;
}

export interface DashboardSummary {
  kpis: {
    open_ncrs: number;
    open_capas: number;
    overdue_capas: number;
    calibration_due: number;
    calibration_overdue: number;
    open_audits: number;
    supplier_avg_rating: number;
    open_complaints: number;
  };
  ncr_trend: TrendPoint[];
  capa_aging: { bucket: string; count: number }[];
  calibration_status: { name: string; value: number }[];
  supplier_performance: { name: string; rating: number; otd: number }[];
  findings_by_clause: { clause: string; count: number }[];
}
