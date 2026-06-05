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
