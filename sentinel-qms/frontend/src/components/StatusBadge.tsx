import { humanize } from '@/lib/format';

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'primary';

/** Maps domain status strings to a visual tone. */
const STATUS_TONE: Record<string, Tone> = {
  // generic
  open: 'warning',
  closed: 'neutral',
  cancelled: 'neutral',
  draft: 'neutral',
  approved: 'success',
  released: 'success',
  rejected: 'danger',
  obsolete: 'neutral',
  in_review: 'info',
  under_review: 'info',
  in_progress: 'info',
  // document control workflow
  concept: 'neutral',
  work_in_progress: 'info',
  peer_review: 'info',
  qa_review: 'warning',
  completed: 'success',
  pending: 'warning',
  // ncr
  disposition_pending: 'warning',
  dispositioned: 'info',
  // capa
  investigation: 'info',
  action_planned: 'info',
  implementation: 'info',
  verification: 'info',
  // severity / priority
  minor: 'info',
  major: 'warning',
  critical: 'danger',
  low: 'neutral',
  medium: 'info',
  high: 'danger',
  // supplier
  conditional: 'warning',
  probation: 'warning',
  disqualified: 'danger',
  // calibration
  in_tolerance: 'success',
  out_of_tolerance: 'danger',
  limited_use: 'warning',
  out_of_service: 'neutral',
  // inspection / finding
  pass: 'success',
  fail: 'danger',
  effective: 'success',
  not_effective: 'danger',
  // training
  assigned: 'info',
  overdue: 'danger',
  // misc
  identified: 'warning',
  assessed: 'info',
  mitigating: 'info',
  accepted: 'success',
  scheduled: 'info',
  received: 'info',
  investigating: 'warning',
  rma_issued: 'info',
  resolved: 'success',
  issued: 'warning',
  responded: 'info',
  verified: 'success',
};

export function StatusBadge({
  status,
  label,
  noDot = false,
}: {
  status?: string;
  label?: string;
  noDot?: boolean;
}) {
  const key = (status ?? '').toLowerCase();
  const tone = STATUS_TONE[key] ?? 'neutral';
  return (
    <span className={`badge badge--${tone}${noDot ? ' badge--no-dot' : ''}`}>
      {label ?? humanize(status)}
    </span>
  );
}
