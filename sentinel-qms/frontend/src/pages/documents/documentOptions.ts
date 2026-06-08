import type { DocumentDepartment, DocumentStatus, DocumentType } from '@/types';

/** Document type options (value -> label). */
export const DOC_TYPE_OPTIONS: { value: DocumentType; label: string }[] = [
  { value: 'work_instruction', label: 'Work Instruction' },
  { value: 'policy', label: 'Policy' },
  { value: 'process', label: 'Process' },
  { value: 'procedure', label: 'Procedure' },
  { value: 'form', label: 'Form' },
  { value: 'guide', label: 'Guide' },
];

/** Department options (value -> label). Note "ins" renders as "I&S". */
export const DEPARTMENT_OPTIONS: { value: DocumentDepartment; label: string }[] = [
  { value: 'ens', label: 'ENS' },
  { value: 'exec', label: 'EXEC' },
  { value: 'qual', label: 'QUAL' },
  { value: 'ilm', label: 'ILM' },
  { value: 'ins', label: 'I&S' },
  { value: 'ts', label: 'TS' },
  { value: 'fin', label: 'FIN' },
  { value: 'ops', label: 'OPS' },
];

/** Workflow stages shown in the stepper (excludes terminal Obsolete). */
export const WORKFLOW_STAGES: { value: DocumentStatus; label: string }[] = [
  { value: 'concept', label: 'Concept' },
  { value: 'work_in_progress', label: 'Work In Progress' },
  { value: 'peer_review', label: 'Peer Review' },
  { value: 'qa_review', label: 'QA Review' },
  { value: 'approved', label: 'Approved' },
];

export const STATUS_OPTIONS: { value: DocumentStatus; label: string }[] = [
  ...WORKFLOW_STAGES,
  { value: 'obsolete', label: 'Obsolete' },
];

export function docTypeLabel(value?: string): string {
  return DOC_TYPE_OPTIONS.find((o) => o.value === value)?.label ?? value ?? '—';
}

export function departmentLabel(value?: string): string {
  return DEPARTMENT_OPTIONS.find((o) => o.value === value)?.label ?? value ?? '—';
}
