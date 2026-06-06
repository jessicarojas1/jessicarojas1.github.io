import { History } from 'lucide-react';
import { useActivity } from '@/hooks/useActivity';
import { formatDateTime, humanize } from '@/lib/format';
import type { AuditLogRecord } from '@/types';
import { UserName } from './UserName';

export interface ActivityTimelineProps {
  entityType: string;
  entityId?: string | number | null;
}

/** Compact before -> after diff: render only the keys that actually changed. */
function ChangeDiff({ entry }: { entry: AuditLogRecord }) {
  const before = entry.before ?? {};
  const after = entry.after ?? {};
  const keys = Array.from(
    new Set([...Object.keys(before), ...Object.keys(after)]),
  ).filter((k) => {
    const b = (before as Record<string, unknown>)[k];
    const a = (after as Record<string, unknown>)[k];
    return JSON.stringify(b) !== JSON.stringify(a);
  });

  if (keys.length === 0) return null;

  const render = (v: unknown): string => {
    if (v == null || v === '') return '—';
    if (typeof v === 'object') return JSON.stringify(v);
    return String(v);
  };

  return (
    <ul className="timeline-diff">
      {keys.map((k) => (
        <li key={k}>
          <span className="timeline-diff__key">{humanize(k)}</span>
          <span className="timeline-diff__from">
            {render((before as Record<string, unknown>)[k])}
          </span>
          <span className="timeline-diff__arrow">&rarr;</span>
          <span className="timeline-diff__to">
            {render((after as Record<string, unknown>)[k])}
          </span>
        </li>
      ))}
    </ul>
  );
}

export function ActivityTimeline({ entityType, entityId }: ActivityTimelineProps) {
  const { data, isLoading } = useActivity(entityType, entityId);

  if (isLoading) {
    return (
      <div className="loading-block" style={{ minHeight: 80 }}>
        <span className="spinner" />
      </div>
    );
  }

  if (!data || data.length === 0) {
    return <div className="empty-state-sm">No activity recorded yet.</div>;
  }

  return (
    <ol className="timeline">
      {data.map((entry) => (
        <li key={entry.id} className="timeline__item">
          <span className="timeline__dot" aria-hidden="true" />
          <div className="timeline__content">
            <div className="row" style={{ gap: 6, flexWrap: 'wrap' }}>
              <strong>{humanize(entry.action)}</strong>
              <span className="muted">
                ·{' '}
                {entry.actor_id != null ? (
                  <UserName id={entry.actor_id} />
                ) : (
                  entry.actor_email ?? 'System'
                )}
              </span>
            </div>
            <div className="muted text-sm">{formatDateTime(entry.created_at)}</div>
            <ChangeDiff entry={entry} />
          </div>
        </li>
      ))}
    </ol>
  );
}

/** Card wrapper matching the detail-page section pattern. */
export function ActivityTimelineCard(props: ActivityTimelineProps) {
  return (
    <div className="card">
      <div className="card__header">
        <div className="card__title row" style={{ gap: 8 }}>
          <History size={15} /> Activity
        </div>
      </div>
      <div className="card__body">
        <ActivityTimeline {...props} />
      </div>
    </div>
  );
}
