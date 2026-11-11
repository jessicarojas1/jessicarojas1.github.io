// Shapes returned by the documents API (see backend DocumentPresenter / LibraryQuery).

import { api } from '@/api/client'
import type { RichTextNode } from '@/richText'

export interface Named {
  code: string
  name: string
}

export interface FileInfo {
  filename: string
  sizeBytes: number
  mime: string
  sha256: string
  available: boolean
}

export interface WorkflowInfo {
  status: string
  taskId: string
  approver: string
  decision: string | null
  comment: string | null
  decidedAt: string | null
}

export interface RevisionView {
  id: string
  state: 'DRAFT' | 'IN_WORKFLOW' | 'CHANGES_REQUESTED' | 'REJECTED' | 'APPROVED' | 'WITHDRAWN'
  label: string
  labelConfirmed: boolean
  title: string
  description: string | null
  keywords: string[]
  changeSummary: string | null
  rowVersion: number
  manifestHash: string | null
  /** Content written in PAL's editor (canonical JSON), or null when there is none. */
  body: RichTextNode | null
  /** Who is editing this draft right now (soft checkout), or null. */
  editing: Editing | null
  /** The template version this revision was started from, if any. */
  template: { id: string; name: string; version: number } | null
  file: FileInfo | null
  workflow: WorkflowInfo | null
  actions: string[]
  releasedAt?: string
  effectiveFrom?: string
}

export interface Editing {
  userId: string
  displayName: string
  since: string
}

export interface Grant {
  id: string
  role: 'AUTHOR' | 'APPROVER' | 'READER'
  principal: { type: 'user' | 'group'; id: string; displayName: string }
  grantedAt: string
}

export interface HistoryEntry {
  revisionId: string
  label: string
  title: string
  changeSummary: string | null
  releasedAt: string
  releasedBy: string | null
  effectiveFrom: string
  effectiveTo: string | null
  isCurrent: boolean
}

export interface DocumentDetail {
  id: string
  documentNumber: string
  type: Named
  department: Named
  owner: { id: string; displayName: string }
  audience: 'ALL_EMPLOYEES' | 'RESTRICTED'
  disposition: string
  relations: string[]
  current: RevisionView | null
  openRevision: RevisionView | null
  awaitingRelease: RevisionView | null
  history: HistoryEntry[]
  grants: Grant[] | null
  actions: string[]
}

export interface LibraryItem {
  id: string
  documentNumber: string
  title: string
  description: string | null
  type: Named
  department: Named
  revision: string
  releasedAt: string
  restricted: boolean
}

export interface ReferenceItem {
  id: string
  code: string
  name: string
  isActive: boolean
}

export interface Person {
  id: string
  displayName: string
  email: string | null
}

export const STATE_LABELS: Record<RevisionView['state'], string> = {
  DRAFT: 'Draft',
  IN_WORKFLOW: 'Awaiting approval',
  CHANGES_REQUESTED: 'Changes requested',
  REJECTED: 'Rejected',
  APPROVED: 'Approved, awaiting release',
  WITHDRAWN: 'Withdrawn',
}

export const GRANT_ROLE_LABELS: Record<Grant['role'], string> = {
  AUTHOR: 'Author',
  APPROVER: 'Approver',
  READER: 'Reader',
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

export interface ReferenceData {
  documentTypes: ReferenceItem[]
  departments: ReferenceItem[]
  maxUploadBytes: number
}

let referencePromise: Promise<ReferenceData> | null = null

/** Active types, departments and the upload limit; fetched once per page load and shared. */
export function loadReference(): Promise<ReferenceData> {
  referencePromise ??= api<ReferenceData>('/api/reference').catch((e: unknown) => {
    referencePromise = null
    throw e
  })
  return referencePromise
}

export interface TemplateOption {
  id: string
  name: string
  description: string | null
}

export interface TemplateSummary extends TemplateOption {
  isActive: boolean
  documentType: { id: string; code: string; name: string }
  updatedAt: string
  updatedBy: string
  rowVersion: number
  /** Current content version; every content change adds one (decision #27). */
  version: number
}

export interface TemplateVersionSummary {
  version: number
  name: string
  description: string | null
  createdBy: string
  createdAt: string
  bodySha256: string
}

export interface TemplateVersionDetail {
  version: number
  name: string
  description: string | null
  body: RichTextNode
  createdBy: string
  createdAt: string
}

export interface TemplateDetail extends TemplateSummary {
  body: RichTextNode
}
