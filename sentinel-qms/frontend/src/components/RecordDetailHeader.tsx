import type { ReactNode } from 'react';
import { PageHeader } from './PageHeader';
import { StatusBadge } from './StatusBadge';
import type { Crumb } from './Breadcrumbs';

/**
 * Canonical detail-page header for any record: a leading icon, the immutable
 * record number (monospace), a lifecycle status badge, optional extra badges,
 * a subtitle, breadcrumbs back to the module list, and an actions slot.
 *
 * Replaces the hand-written `<span class="row">icon + mono + StatusBadge</span>`
 * title block repeated across every detail page so the layout stays consistent.
 */
export function RecordDetailHeader({
  icon,
  recordNumber,
  title,
  status,
  statusLabel,
  badges,
  listLabel,
  listTo,
  actions,
}: {
  icon: ReactNode;
  recordNumber: string;
  title?: ReactNode;
  status?: string;
  statusLabel?: string;
  /** Extra badges rendered after the status (e.g. severity, type). */
  badges?: ReactNode;
  listLabel: string;
  listTo: string;
  actions?: ReactNode;
}) {
  const breadcrumbs: Crumb[] = [{ label: listLabel, to: listTo }, { label: recordNumber }];
  return (
    <PageHeader
      title={
        <span className="row" style={{ gap: 10 }}>
          {icon}
          <span className="mono">{recordNumber}</span>
          {status !== undefined && <StatusBadge status={status} label={statusLabel} />}
          {badges}
        </span>
      }
      subtitle={title}
      breadcrumbs={breadcrumbs}
      actions={actions}
    />
  );
}
