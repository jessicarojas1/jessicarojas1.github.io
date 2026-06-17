/**
 * Domain types mirroring the Sentinel QMS backend schemas.
 * Each interface corresponds to a REST resource under /api/v1.
 */
import type { Attachment, AuditTrailEntry, Iso8601 } from './common';

/* ------------------------------------------------------------------ */
/* Documents — controlled document register w/ revisions & approvals   */
/* ------------------------------------------------------------------ */

export type DocumentStatus =
  | 'concept'
  | 'work_in_progress'
  | 'peer_review'
  | 'qa_review'
  | 'approved'
  | 'obsolete';

export type DocumentType =
  | 'work_instruction'
  | 'policy'
  | 'process'
  | 'procedure'
  | 'form'
  | 'guide';

export type DocumentDepartment =
  | 'ens'
  | 'exec'
  | 'qual'
  | 'ilm'
  | 'ins'
  | 'ts'
  | 'fin'
  | 'ops';

/** Workflow action accepted by POST /documents/{id}/transition. */
export type DocumentTransitionAction = 'advance' | 'approve' | 'obsolete' | 'revise';

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
  doc_type: DocumentType;
  status: DocumentStatus;
  department?: DocumentDepartment;
  description?: string;
  owner_id?: string;
  approved_by?: string;
  version?: string;
  current_revision?: string;
  effective_date?: Iso8601;
  next_review_date?: Iso8601;
  last_review_date?: Iso8601;
  as9100_clause?: string;
  // Fixed-template body sections.
  purpose?: string;
  scope?: string;
  definitions?: string;
  responsibilities?: string;
  detail?: string;
  revision_history?: string;
  appendix?: string;
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
/* Attachments / evidence (matches backend AttachmentRead)             */
/* ------------------------------------------------------------------ */

export interface AttachmentRecord {
  id: number;
  entity_type: string;
  entity_id: string;
  original_filename: string;
  content_type: string;
  size_bytes: number;
  checksum_sha256?: string | null;
  storage_backend: string;
  uploaded_by?: number | null;
  created_at?: Iso8601 | null;
}

/* ------------------------------------------------------------------ */
/* Record-scoped audit log (matches backend AuditLogRead)              */
/* ------------------------------------------------------------------ */

export interface AuditLogRecord {
  id: number;
  actor_id: number | null;
  actor_email: string | null;
  action: string;
  entity_type: string;
  entity_id: string | null;
  before?: Record<string, unknown> | null;
  after?: Record<string, unknown> | null;
  ip_address?: string | null;
  request_id?: string | null;
  created_at: Iso8601;
}

/* ------------------------------------------------------------------ */
/* Dashboard                                                            */
/* ------------------------------------------------------------------ */

export interface DashboardTrendPoint {
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
  ncr_trend: DashboardTrendPoint[];
  capa_aging: { bucket: string; count: number }[];
  calibration_status: { name: string; value: number }[];
  supplier_performance: { name: string; rating: number; otd: number }[];
  findings_by_clause: { clause: string; count: number }[];
}

export interface ExecKpi {
  key: string;
  label: string;
  value: number;
  unit: string;
  target: number | null;
  direction: 'lower_better' | 'higher_better';
  status: 'good' | 'warn' | 'bad' | 'neutral';
}

export interface CoqMonth {
  month: string;
  prevention: number;
  appraisal: number;
  internal_failure: number;
  external_failure: number;
  prevention_cost: number;
  appraisal_cost: number;
  internal_failure_cost: number;
  external_failure_cost: number;
}

export interface ClauseHeat {
  clause: string;
  title: string;
  major: number;
  minor: number;
  observation: number;
  ofi: number;
  total: number;
}

export interface CalendarItem {
  type: string;
  label: string;
  date: string;
  days_remaining: number;
  status: 'overdue' | 'due_soon' | 'upcoming';
}

export type ApqpPhase =
  | 'planning'
  | 'product_design'
  | 'process_design'
  | 'validation'
  | 'production';
export type ApqpStatus = 'active' | 'on_hold' | 'complete' | 'cancelled';
export type PpapElementStatus =
  | 'not_started'
  | 'in_progress'
  | 'submitted'
  | 'approved'
  | 'rejected'
  | 'not_applicable';

export interface PpapElement {
  id: number;
  project_id: number;
  element_key: string;
  name: string;
  status: PpapElementStatus;
  notes: string | null;
}

export interface PpapProgress {
  total: number;
  approved: number;
  applicable: number;
  approved_pct: number;
}

export interface ApqpProject {
  id: number;
  project_number: string;
  part_number: string;
  part_name: string;
  customer: string | null;
  supplier_id: number | null;
  contract_id: number | null;
  current_phase: ApqpPhase;
  status: ApqpStatus;
  submission_level: number;
  target_date: string | null;
  ppap: PpapProgress;
}

export interface ApqpDetail extends ApqpProject {
  notes: string | null;
  elements: PpapElement[];
}

export type SourceType = 'ocm' | 'franchised' | 'independent' | 'broker' | 'other';
export type RiskLevel = 'low' | 'medium' | 'high' | 'critical';
export type VerificationStatus = 'pending' | 'verified' | 'suspect' | 'rejected';
export type AlertSource = 'gidep' | 'erai' | 'internal' | 'customer' | 'supplier' | 'other';
export type AlertStatus = 'open' | 'under_assessment' | 'closed';

export interface SourcingRecord {
  id: number;
  record_number: string;
  part_number: string;
  description: string | null;
  supplier_id: number | null;
  source_type: SourceType;
  lot_date_code: string | null;
  quantity: number | null;
  coc_received: boolean;
  traceability_to_oem: boolean;
  inspection_method: string | null;
  risk_level: RiskLevel;
  status: VerificationStatus;
  notes: string | null;
  ncr_id: number | null;
}

export interface CounterfeitAlert {
  id: number;
  alert_number: string;
  source: AlertSource;
  external_ref: string | null;
  title: string;
  part_numbers: string | null;
  description: string | null;
  alert_date: string | null;
  status: AlertStatus;
  impact_assessment: string | null;
  affects_inventory: boolean;
  ncr_id: number | null;
}

export interface RecordShare {
  id: number;
  entity_type: string;
  entity_id: string;
  label: string;
  shared_with_user_id: number;
  shared_by_user_id: number;
  note: string | null;
  created_at?: string | null;
}

export interface ParetoBucket {
  label: string;
  count: number;
  cumulative_pct: number;
}

export interface ParetoResponse {
  dimension: string;
  total: number;
  buckets: ParetoBucket[];
}

export type KcClass = 'critical' | 'major' | 'minor';

export interface SpcCapability {
  count: number;
  mean: number | null;
  std: number | null;
  cp: number | null;
  cpk: number | null;
  ucl: number | null;
  lcl: number | null;
  min: number | null;
  max: number | null;
}

export interface KcMeasurement {
  id: number;
  kc_id: number;
  value: number;
  measured_at: string | null;
  operator: string | null;
}

export interface KcSummary {
  id: number;
  kc_number: string;
  part_number: string;
  characteristic: string;
  nominal: number | null;
  usl: number | null;
  lsl: number | null;
  unit: string | null;
  kc_class: KcClass;
  owner_id: number | null;
  owner_name: string | null;
  capability: SpcCapability;
}

export interface SpcViolation {
  rule: number;
  index: number;
  value: number;
  description: string;
}

export interface KcDetail extends KcSummary {
  notes: string | null;
  measurements: KcMeasurement[];
  violations: SpcViolation[];
}

export type MsaType = 'gage_rr' | 'bias' | 'linearity' | 'stability';
export type MsaResult = 'acceptable' | 'marginal' | 'unacceptable' | 'pending';

export interface MsaStudy {
  id: number;
  study_number: string;
  equipment_id: number | null;
  characteristic: string;
  study_type: MsaType;
  num_parts: number | null;
  num_operators: number | null;
  num_trials: number | null;
  grr_percent: number | null;
  ndc: number | null;
  result: MsaResult;
  study_date: string | null;
  notes: string | null;
}

export type ProgramStatus = 'draft' | 'active' | 'closed';
export type ProgramItemStatus = 'planned' | 'scheduled' | 'completed' | 'cancelled';

export interface AuditProgramItem {
  id: number;
  program_id: number;
  area: string;
  clause_reference: string | null;
  planned_period: string | null;
  lead_auditor_id: number | null;
  status: ProgramItemStatus;
  audit_id: number | null;
}

export interface AuditProgramSummary {
  id: number;
  name: string;
  year: number;
  status: ProgramStatus;
  progress: { total: number; completed: number; completed_pct: number };
}

export interface AuditProgramDetail extends AuditProgramSummary {
  objectives: string | null;
  items: AuditProgramItem[];
}

export type CustomerStatus = 'active' | 'inactive';
export type ContractStatus = 'active' | 'on_hold' | 'closed';
export type FlowDownTo = 'internal' | 'supplier' | 'both';
export type FlowDownStatus = 'open' | 'flowed_down' | 'verified' | 'not_applicable';

export interface Customer {
  id: number;
  code: string;
  name: string;
  cage_code: string | null;
  country: string | null;
  contact_name: string | null;
  contact_email: string | null;
  status: CustomerStatus;
  notes: string | null;
  contract_count: number;
}

export interface ContractRequirement {
  id: number;
  contract_id: number;
  clause: string | null;
  description: string;
  flow_down_to: FlowDownTo;
  status: FlowDownStatus;
}

export interface ContractSummary {
  id: number;
  contract_number: string;
  customer_id: number;
  title: string;
  dpas_rating: string | null;
  itar_controlled: boolean;
  status: ContractStatus;
  start_date: string | null;
  end_date: string | null;
}

export interface ContractDetail extends ContractSummary {
  dfars_clauses: string | null;
  value: number | null;
  notes: string | null;
  requirements: ContractRequirement[];
}

export type ConcessionType = 'deviation' | 'waiver' | 'concession';
export type ConcessionStatus =
  | 'draft'
  | 'submitted'
  | 'under_review'
  | 'approved'
  | 'rejected'
  | 'expired'
  | 'closed';

export interface Concession {
  id: number;
  concession_number: string;
  concession_type: ConcessionType;
  title: string;
  part_number: string | null;
  description: string;
  justification: string | null;
  quantity: number | null;
  status: ConcessionStatus;
  supplier_id: number | null;
  customer_approval_required: boolean;
  customer_approved: boolean;
  expiry_date: string | null;
}

export type FodRisk = 'low' | 'medium' | 'high';
export type FodSeverity = 'low' | 'medium' | 'high' | 'critical';
export type FodStatus = 'open' | 'investigating' | 'contained' | 'closed';

export interface FodZone {
  id: number;
  code: string;
  name: string;
  risk_level: FodRisk;
  description: string | null;
  is_active: boolean;
}

export interface FodEvent {
  id: number;
  event_number: string;
  zone_id: number | null;
  title: string;
  description: string | null;
  object_type: string | null;
  location: string | null;
  severity: FodSeverity;
  status: FodStatus;
  discovered_date: string | null;
  root_cause: string | null;
  corrective_action: string | null;
  ncr_id: number | null;
}

export type CoverageStatus = 'covered' | 'partial' | 'gap' | 'not_applicable';

export interface StandardRequirement {
  id: number;
  standard_id: number;
  clause: string;
  title: string;
  module_key: string | null;
  coverage_status: CoverageStatus;
  evidence_note: string | null;
}

export interface CoverageSummary {
  total: number;
  covered: number;
  partial: number;
  gap: number;
  not_applicable: number;
  coverage_pct: number;
}

export interface StandardSummary {
  id: number;
  code: string;
  name: string;
  description: string | null;
  is_active: boolean;
  coverage: CoverageSummary;
}

export interface StandardDetail extends StandardSummary {
  requirements: StandardRequirement[];
}

export interface ExecutiveDashboard {
  generated_at: string;
  kpis: ExecKpi[];
  coq_trend: CoqMonth[];
  coq_current: {
    prevention: number;
    appraisal: number;
    internal_failure: number;
    external_failure: number;
    total: number;
    prevention_cost: number;
    appraisal_cost: number;
    internal_failure_cost: number;
    external_failure_cost: number;
    total_cost: number;
  };
  clause_heatmap: ClauseHeat[];
  compliance_calendar: CalendarItem[];
  counterfeit: { suspect_parts: number; open_alerts: number };
  standards_coverage: { code: string; coverage_pct: number }[];
  fod: { open_events: number; trend: { month: string; count: number }[] };
}

/* ------------------------------------------------------------------ */
/* Quality Objectives & KPIs (clause 6.2)                              */
/* ------------------------------------------------------------------ */

export type ObjectiveDirection = 'higher_better' | 'lower_better';
export type ObjectiveCadence = 'monthly' | 'quarterly' | 'annual';
export type ObjectiveStatus = 'active' | 'met' | 'at_risk' | 'missed' | 'archived';

export interface QObjectiveMeasurement {
  id: number;
  objective_id: number;
  value: number;
  measured_at?: Iso8601 | null;
  note?: string | null;
}

export interface QualityObjective {
  id: number;
  objective_number: string;
  title: string;
  description?: string | null;
  category?: string | null;
  owner_id?: number | null;
  owner_name?: string | null;
  target_value: number;
  baseline_value?: number | null;
  current_value?: number | null;
  unit?: string | null;
  direction: ObjectiveDirection;
  cadence: ObjectiveCadence;
  status: ObjectiveStatus;
  target_date?: Iso8601 | null;
  clause_ref?: string | null;
  attainment_pct?: number | null;
  measurements?: QObjectiveMeasurement[];
}
