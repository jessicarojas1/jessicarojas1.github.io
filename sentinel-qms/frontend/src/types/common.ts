/** Shared API envelope and primitive types mirroring the backend schemas. */

export interface Paginated<T> {
  items: T[];
  total: number;
  page: number;
  page_size: number;
  pages: number;
}

export interface ListParams {
  page?: number;
  page_size?: number;
  search?: string;
  sort?: string;
  order?: 'asc' | 'desc';
  status?: string;
  [key: string]: string | number | undefined;
}

export interface ApiError {
  detail: string;
  code?: string;
  fields?: Record<string, string[]>;
}

export interface Attachment {
  id: string;
  filename: string;
  content_type: string;
  size_bytes: number;
  uploaded_by: string;
  uploaded_at: string;
  url: string;
}

export interface AuditTrailEntry {
  id: string;
  entity_type: string;
  entity_id: string;
  action: string;
  actor: string;
  actor_role?: string;
  timestamp: string;
  reason?: string;
  changes?: Record<string, { from: unknown; to: unknown }>;
}

/** Electronic signature record per 21 CFR Part 11 expectations. */
export interface ElectronicSignature {
  id: string;
  signed_by: string;
  signed_by_name: string;
  role: string;
  meaning: string;
  reason: string;
  signed_at: string;
}

export type Iso8601 = string;

/* ------------------------------------------------------------------ */
/* Global search                                                       */
/* ------------------------------------------------------------------ */
export interface SearchResult {
  type: string;
  id: number;
  number: string;
  title: string;
  url: string;
}

export interface SearchResponse {
  results: SearchResult[];
}

/* ------------------------------------------------------------------ */
/* Notifications                                                       */
/* ------------------------------------------------------------------ */
export interface NotificationItem {
  id: number;
  title?: string;
  message?: string;
  entity_type?: string | null;
  entity_id?: number | null;
  url?: string | null;
  is_read: boolean;
  created_at: Iso8601;
}

/* ------------------------------------------------------------------ */
/* Audit logs (admin)                                                 */
/* ------------------------------------------------------------------ */
export interface AuditLogEntry {
  id: number;
  actor_id: number | null;
  actor_email: string | null;
  action: string;
  entity_type: string;
  entity_id: number | null;
  created_at: Iso8601;
  before?: unknown;
  after?: unknown;
  changes?: unknown;
}

/* ------------------------------------------------------------------ */
/* Analytics trends                                                   */
/* ------------------------------------------------------------------ */
export interface TrendPoint {
  month: string;
  opened: number;
  closed: number;
}

export interface AnalyticsTrends {
  ncr_trend: TrendPoint[];
  capa_trend: TrendPoint[];
  open_by_module: Record<string, number>;
  nc_by_severity: Record<string, number>;
  audit_findings_by_type: Record<string, number>;
}

/* ------------------------------------------------------------------ */
/* Reports & Exports                                                   */
/* ------------------------------------------------------------------ */
export interface LabelCount {
  label: string;
  count: number;
}

export interface ReportMonthTrend {
  month: string;
  opened: number;
  closed: number;
}

export interface AgingBucket {
  bucket: string;
  count: number;
}

export interface NcrSummaryReport {
  by_status: LabelCount[];
  by_severity: LabelCount[];
  by_month: ReportMonthTrend[];
  total_open: number;
  total: number;
}

export interface CapaSummaryReport {
  by_status: LabelCount[];
  aging: AgingBucket[];
  overdue: number;
  total_open: number;
  avg_days_open: number;
}

export interface SupplierScorecardRow {
  name: string;
  status: string;
  quality_score: number | null;
  on_time_delivery: number | null;
  open_scars: number;
  rating_count: number;
}

export interface SupplierScorecardReport {
  suppliers: SupplierScorecardRow[];
}

export interface AuditSummaryReport {
  by_type: LabelCount[];
  by_status: LabelCount[];
  findings_by_type: LabelCount[];
  total: number;
}
