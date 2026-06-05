/**
 * Domain types mirroring the Sentinel QMS backend schemas.
 * Each interface corresponds to a REST resource under /api/v1.
 */
import type { Attachment, AuditTrailEntry, Iso8601 } from './common';

/* ------------------------------------------------------------------ */
/* Documents — controlled document register w/ revisions & approvals   */
/* ------------------------------------------------------------------ */

export type DocumentStatus =
  | 'draft'
  | 'in_review'
  | 'approved'
  | 'effective'
  | 'obsolete';

export interface DocumentRevision {
  id: string;
  document_id: string;
  revision: string;
  change_summary?: string;
  status: DocumentStatus;
  attachment_id?: string;
  effective_date?: Iso8601;
  created_at: Iso8601;
}

export interface ControlledDocument {
  id: string;
  document_number: string;
  title: string;
  doc_type: string;
  status: DocumentStatus;
  description?: string;
  owner_id?: string;
  current_revision?: string;
  effective_date?: Iso8601;
  next_review_date?: Iso8601;
  as9100_clause?: string;
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
  | 'dispositioned'
  | 'closed'
  | 'void';

export type NcrSeverity = 'minor' | 'major' | 'critical';

export type DispositionType =
  | 'use_as_is'
  | 'rework'
  | 'repair'
  | 'scrap'
  | 'return';

export interface Disposition {
  id: string;
  nonconformance_id: string;
  disposition_type: DispositionType;
  justification: string;
  mrb_members?: string;
  customer_approval_required: boolean;
  customer_approved: boolean;
  decided_by: string;
  signature_id?: string;
  created_at: Iso8601;
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
  serial_number?: string;
  quantity_affected?: number;
  source: string;
  detected_at: Iso8601;
  work_order?: string;
  assigned_to?: string;
  supplier_id?: string;
  dispositions?: Disposition[];
  capa_id?: string;
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
  | 'containment'
  | 'root_cause'
  | 'action_plan'
  | 'implementation'
  | 'verification'
  | 'closed'
  | 'cancelled';

export type CapaType = 'corrective' | 'preventive';

export type CapaActionStatus = 'open' | 'in_progress' | 'completed' | 'verified';

export type EightDStepKey =
  | 'd1_team'
  | 'd2_problem_description'
  | 'd3_containment'
  | 'd4_root_cause'
  | 'd5_corrective_action'
  | 'd6_implementation'
  | 'd7_preventive_action'
  | 'd8_closure';

export interface EightDStep {
  key: EightDStepKey;
  title: string;
  content: string;
  completed: boolean;
}

export interface CapaAction {
  id: string;
  capa_id: string;
  description: string;
  action_kind: string;
  owner_id?: string;
  status: CapaActionStatus;
  due_date?: Iso8601;
  completed_at?: Iso8601;
  created_at: Iso8601;
}

export interface Capa {
  id: string;
  capa_number: string;
  title: string;
  capa_type: CapaType;
  status: CapaStatus;
  d1_team?: string;
  d2_problem_description: string;
  d3_containment?: string;
  d4_root_cause?: string;
  root_cause_method?: string;
  d5_corrective_action?: string;
  d6_implementation?: string;
  d7_preventive_action?: string;
  d8_closure?: string;
  effectiveness_verified: boolean;
  effectiveness_notes?: string;
  effectiveness_verified_by?: string;
  effectiveness_verified_at?: Iso8601;
  owner_id?: string;
  supplier_id?: string;
  due_date?: Iso8601;
  closed_at?: Iso8601;
  closure_signature_id?: string;
  created_at: Iso8601;
  updated_at: Iso8601;
  actions?: CapaAction[];
  attachments?: Attachment[];
}

/* ------------------------------------------------------------------ */
/* Audits + findings                                                   */
/* ------------------------------------------------------------------ */

export type AuditType = 'internal' | 'external' | 'supplier' | 'certification' | 'process';
export type AuditStatus = 'planned' | 'in_progress' | 'reporting' | 'closed';
export type FindingType =
  | 'major_nonconformity'
  | 'minor_nonconformity'
  | 'observation'
  | 'opportunity_for_improvement';
export type FindingStatus = 'open' | 'response_submitted' | 'verified' | 'closed';

export interface AuditFinding {
  id: string;
  audit_id: string;
  finding_number: string;
  finding_type: FindingType;
  status: FindingStatus;
  clause_reference?: string;
  description: string;
  evidence?: string;
  response_due_date?: Iso8601;
  capa_id?: string;
  created_at: Iso8601;
}

export interface AuditChecklistItem {
  id: string;
  audit_id: string;
  clause_reference?: string;
  question: string;
  result?: string;
  notes?: string;
}

export interface Audit {
  id: string;
  audit_number: string;
  title: string;
  audit_type: AuditType;
  status: AuditStatus;
  standard?: string;
  scope?: string;
  lead_auditor_id?: string;
  auditee_area?: string;
  supplier_id?: string;
  planned_date?: Iso8601;
  actual_date?: Iso8601;
  findings?: AuditFinding[];
  checklist_items?: AuditChecklistItem[];
  created_at: Iso8601;
  updated_at: Iso8601;
}

/* ------------------------------------------------------------------ */
/* Suppliers — ASL, SCAR, ratings                                      */
/* ------------------------------------------------------------------ */

export type SupplierStatus =
  | 'prospective'
  | 'approved'
  | 'conditional'
  | 'probation'
  | 'disqualified';

export type ScarStatus =
  | 'issued'
  | 'acknowledged'
  | 'response_received'
  | 'verified'
  | 'closed';

export interface Scar {
  id: string;
  scar_number: string;
  supplier_id: string;
  title: string;
  description: string;
  status: ScarStatus;
  nonconformance_id?: string;
  issued_date?: Iso8601;
  response_due_date?: Iso8601;
  supplier_response?: string;
  closed_at?: Iso8601;
  created_at: Iso8601;
}

export interface SupplierRating {
  id: string;
  supplier_id: string;
  period: string;
  quality_score?: number;
  on_time_delivery?: number;
  ppm_defects?: number;
  composite_score?: number;
  grade?: string;
  notes?: string;
  created_at: Iso8601;
}

export interface Supplier {
  id: string;
  supplier_code: string;
  name: string;
  status: SupplierStatus;
  cage_code?: string;
  duns_number?: string;
  certification?: string;
  cert_expiry?: Iso8601;
  contact_name?: string;
  contact_email?: string;
  country?: string;
  notes?: string;
  created_at: Iso8601;
  updated_at: Iso8601;
}

/* ------------------------------------------------------------------ */
/* Calibration — equipment register                                    */
/* ------------------------------------------------------------------ */

export type EquipmentStatus = 'active' | 'out_of_service' | 'lost' | 'retired';

export type CalibrationResult = 'pass' | 'pass_with_adjustment' | 'fail' | 'limited';

export interface CalibrationRecord {
  id: string;
  equipment_id: string;
  calibration_date: Iso8601;
  due_date: Iso8601;
  result: CalibrationResult;
  certificate_number?: string;
  performed_by?: string;
  calibration_vendor?: string;
  standard_used?: string;
  as_found?: string;
  as_left?: string;
  uncertainty?: number;
  notes?: string;
  created_at: Iso8601;
}

export interface Equipment {
  id: string;
  asset_tag: string;
  name: string;
  equipment_type?: string;
  manufacturer?: string;
  model?: string;
  serial_number?: string;
  location?: string;
  status: EquipmentStatus;
  calibration_interval_days: number;
  last_calibration_date?: Iso8601;
  next_due_date?: Iso8601;
  custodian_id?: string;
  records?: CalibrationRecord[];
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

export type ChangeStatus =
  | 'draft'
  | 'submitted'
  | 'under_review'
  | 'approved'
  | 'rejected'
  | 'implemented'
  | 'closed';

export type ChangeType = 'ecn' | 'eco' | 'deviation' | 'waiver';
export type ChangePriority = 'low' | 'medium' | 'high' | 'emergency';

export interface ChangeRequest {
  id: string;
  change_number: string;
  title: string;
  change_type: ChangeType;
  status: ChangeStatus;
  priority: ChangePriority;
  description: string;
  reason?: string;
  affected_items?: string;
  impact_analysis?: string;
  requested_by?: string;
  owner_id?: string;
  document_id?: string;
  target_date?: Iso8601;
  approved_by?: string;
  approved_at?: Iso8601;
  implemented_at?: Iso8601;
  created_at: Iso8601;
  updated_at: Iso8601;
}

/* ------------------------------------------------------------------ */
/* Risk register — RPN heat map                                        */
/* ------------------------------------------------------------------ */

export type RiskStatus =
  | 'identified'
  | 'assessed'
  | 'treatment_planned'
  | 'mitigating'
  | 'monitoring'
  | 'closed';

export type RiskCategory =
  | 'quality'
  | 'supply_chain'
  | 'operational'
  | 'compliance'
  | 'safety'
  | 'cybersecurity'
  | 'program';

export type TreatmentStrategy = 'avoid' | 'mitigate' | 'transfer' | 'accept';

export interface Risk {
  id: string;
  risk_number: string;
  title: string;
  description: string;
  category: RiskCategory;
  status: RiskStatus;
  severity: number; // 1-10
  likelihood: number; // 1-10
  detectability: number; // 1-10
  rpn: number; // severity * likelihood * detectability
  treatment_strategy?: TreatmentStrategy;
  treatment_plan?: string;
  residual_severity?: number;
  residual_likelihood?: number;
  residual_detectability?: number;
  residual_rpn?: number;
  owner_id?: string;
  review_date?: Iso8601;
  capa_id?: string;
  created_at: Iso8601;
  updated_at: Iso8601;
}

/* ------------------------------------------------------------------ */
/* Inspections — FAI / AS9102                                          */
/* ------------------------------------------------------------------ */

export type InspectionResult = 'pending' | 'accept' | 'reject' | 'accept_with_deviation';
export type InspectionType =
  | 'receiving'
  | 'in_process'
  | 'final'
  | 'first_article'
  | 'source';
export type FaiType = 'full' | 'partial' | 'delta';

export interface FaiCharacteristic {
  id: string;
  fai_report_id: string;
  balloon_number: string;
  characteristic: string;
  requirement?: string;
  nominal?: number;
  tol_minus?: number;
  tol_plus?: number;
  measured_value?: number;
  measurement_method?: string;
  result?: string;
  notes?: string;
}

export interface FaiReport {
  id: string;
  fai_number: string;
  inspection_id?: string;
  part_number: string;
  part_name?: string;
  part_revision?: string;
  drawing_number?: string;
  fai_type: FaiType;
  supplier_id?: string;
  baseline_part_number?: string;
  disposition?: string;
  prepared_by?: string;
  fai_date?: Iso8601;
  created_at: Iso8601;
  characteristics?: FaiCharacteristic[];
}

export interface Inspection {
  id: string;
  inspection_number: string;
  inspection_type: InspectionType;
  result: InspectionResult;
  part_number?: string;
  lot_number?: string;
  quantity_inspected?: number;
  quantity_accepted?: number;
  quantity_rejected?: number;
  inspector_id?: string;
  supplier_id?: string;
  work_order?: string;
  inspection_date?: Iso8601;
  nonconformance_id?: string;
  notes?: string;
  created_at: Iso8601;
  updated_at: Iso8601;
  fai_report?: FaiReport;
}

/* ------------------------------------------------------------------ */
/* Management reviews                                                   */
/* ------------------------------------------------------------------ */

export type MgmtReviewStatus = 'scheduled' | 'in_progress' | 'completed' | 'closed';

export type ActionItemStatus = 'open' | 'in_progress' | 'completed' | 'overdue';

export interface ReviewInput {
  id: string;
  review_id: string;
  category: string;
  content: string;
  metric_value?: string;
}

export interface MgmtReviewAction {
  id: string;
  review_id?: string;
  description: string;
  owner_id?: string;
  status: ActionItemStatus;
  due_date?: Iso8601;
  completed_at?: Iso8601;
  created_at: Iso8601;
}

export interface MgmtReview {
  id: string;
  review_number: string;
  title: string;
  status: MgmtReviewStatus;
  meeting_date?: Iso8601;
  attendees?: string;
  chairperson_id?: string;
  summary?: string;
  minutes?: string;
  inputs?: ReviewInput[];
  action_items?: MgmtReviewAction[];
  created_at: Iso8601;
  updated_at: Iso8601;
}

/* ------------------------------------------------------------------ */
/* Complaints — RMA                                                    */
/* ------------------------------------------------------------------ */

export type ComplaintStatus =
  | 'received'
  | 'under_investigation'
  | 'awaiting_customer'
  | 'resolved'
  | 'closed';

export type ComplaintSeverity = 'low' | 'medium' | 'high' | 'critical';

export interface Complaint {
  id: string;
  complaint_number: string;
  title: string;
  description: string;
  status: ComplaintStatus;
  severity: ComplaintSeverity;
  customer_name: string;
  customer_contact?: string;
  part_number?: string;
  serial_number?: string;
  rma_number?: string;
  is_rma: boolean;
  received_date?: Iso8601;
  response_due_date?: Iso8601;
  resolution?: string;
  assigned_to?: string;
  nonconformance_id?: string;
  capa_id?: string;
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
