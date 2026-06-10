import type { ReactNode } from 'react';
import { History, Paperclip } from 'lucide-react';
import { formatBytes, formatDateTime, humanize } from '@/lib/format';
import { safeHref } from '@/lib/url';
import type { Attachment, AuditTrailEntry } from '@/types';
import { EmptyState } from './EmptyState';

/** Loading / error / not-found wrapper for detail pages. */
export function DetailState({
  loading,
  error,
  notFound,
  children,
}: {
  loading?: boolean;
  error?: string | null;
  notFound?: boolean;
  children: ReactNode;
}) {
  if (loading) {
    return (
      <div className="loading-block" style={{ minHeight: '50vh' }}>
        <span className="spinner spinner--lg" />
      </div>
    );
  }
  if (error) {
    return (
      <div className="card">
        <div className="card__body">
          <EmptyState title="Unable to load record" description={error} />
        </div>
      </div>
    );
  }
  if (notFound) {
    return (
      <div className="card">
        <div className="card__body">
          <EmptyState title="Record not found" description="It may have been deleted or you lack access." />
        </div>
      </div>
    );
  }
  return <>{children}</>;
}

export interface DataPoint {
  label: string;
  value: ReactNode;
}

export function DataList({ items }: { items: DataPoint[] }) {
  return (
    <dl className="dl">
      {items.map((it) => (
        <div key={it.label} style={{ display: 'contents' }}>
          <dt>{it.label}</dt>
          <dd>{it.value ?? '—'}</dd>
        </div>
      ))}
    </dl>
  );
}

export function AttachmentsCard({ attachments }: { attachments?: Attachment[] }) {
  return (
    <div className="card">
      <div className="card__header">
        <div className="card__title row" style={{ gap: 8 }}>
          <Paperclip size={15} /> Attachments
        </div>
      </div>
      <div className="card__body">
        {attachments?.length ? (
          <div className="stack" style={{ gap: 10 }}>
            {attachments.map((a) => (
              <a key={a.id} href={safeHref(a.url)} className="row text-sm" style={{ gap: 8 }}>
                <Paperclip size={14} />
                <span style={{ flex: 1 }}>{a.filename}</span>
                <span className="muted">{formatBytes(a.size_bytes)}</span>
              </a>
            ))}
          </div>
        ) : (
          <div className="empty-state-sm">No attachments uploaded.</div>
        )}
      </div>
    </div>
  );
}

export function AuditTrailCard({ entries }: { entries?: AuditTrailEntry[] }) {
  return (
    <div className="card">
      <div className="card__header">
        <div className="card__title row" style={{ gap: 8 }}>
          <History size={15} /> Audit Trail
        </div>
      </div>
      <div className="card__body">
        {entries?.length ? (
          <div className="stack" style={{ gap: 12 }}>
            {entries.map((e) => (
              <div key={e.id} className="text-sm">
                <div className="row" style={{ gap: 6 }}>
                  <strong>{humanize(e.action)}</strong>
                  <span className="muted">· {e.actor}</span>
                </div>
                <div className="muted">{formatDateTime(e.timestamp)}</div>
                {e.reason && <div className="text-sm">{e.reason}</div>}
              </div>
            ))}
          </div>
        ) : (
          <div className="empty-state-sm">No history recorded yet.</div>
        )}
      </div>
    </div>
  );
}
