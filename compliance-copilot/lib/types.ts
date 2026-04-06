export type ControlStatus =
  | 'implemented'
  | 'partially_implemented'
  | 'not_implemented'
  | 'not_applicable'
  | 'planned';

export type EvidenceType =
  | 'policy'
  | 'procedure'
  | 'screenshot'
  | 'log'
  | 'configuration'
  | 'test_result'
  | 'interview'
  | 'other';

export type Priority = 'critical' | 'high' | 'medium' | 'low';

export interface Control {
  id: string;
  control_id: string;      // e.g. "3.1.1"
  domain: string;          // e.g. "AC"
  domain_name: string;     // e.g. "Access Control"
  title: string;
  requirement: string;
  discussion: string;
  status: ControlStatus;
  priority: Priority;
  implementation_statement: string | null;
  policy_references: string[];
  notes: string | null;
  responsible_role: string | null;
  cmmc_level: 1 | 2 | 3;
  nist_mapping: string[];
  last_reviewed: string | null;
  next_review: string | null;
  created_at: string;
  updated_at: string;
}

export interface Evidence {
  id: string;
  control_ids: string[];
  title: string;
  description: string | null;
  type: EvidenceType;
  file_url: string | null;
  file_name: string | null;
  file_size: number | null;
  tags: string[];
  uploaded_by: string | null;
  reviewed: boolean;
  expiry_date: string | null;
  created_at: string;
  updated_at: string;
}

export interface POAMItem {
  id: string;
  control_id: string;
  weakness: string;
  remediation: string;
  responsible_party: string | null;
  scheduled_completion: string | null;
  resources_required: string | null;
  milestones: string[];
  status: 'open' | 'in_progress' | 'completed' | 'risk_accepted';
  created_at: string;
}

export interface DomainSummary {
  domain: string;
  domain_name: string;
  total: number;
  implemented: number;
  partially: number;
  not_implemented: number;
  not_applicable: number;
  planned: number;
  score: number;
}

export interface ComplianceSummary {
  total_controls: number;
  implemented: number;
  partially_implemented: number;
  not_implemented: number;
  not_applicable: number;
  planned: number;
  overall_score: number;
  domains: DomainSummary[];
}
