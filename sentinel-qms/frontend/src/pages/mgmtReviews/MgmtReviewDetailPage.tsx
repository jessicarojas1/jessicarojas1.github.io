import { useParams } from 'react-router-dom';
import { GaugeCircle } from 'lucide-react';
import { mgmtReviewHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { PrintButton } from '@/components/PrintButton';
import { StatusBadge } from '@/components/StatusBadge';
import { DataList, DetailState } from '@/components/detail';
import type { ReviewInput } from '@/types';

function InputsCard({ title, items }: { title: string; items?: ReviewInput[] }) {
  return (
    <div className="card">
      <div className="card__header"><div className="card__title">{title}</div></div>
      <div className="card__body">
        {items?.length ? (
          <ul style={{ margin: 0, paddingLeft: 18 }}>
            {items.map((it) => (
              <li key={it.id} style={{ marginBottom: 6 }}>
                <strong>{it.category}:</strong> {it.content}
                {it.metric_value ? ` (${it.metric_value})` : ''}
              </li>
            ))}
          </ul>
        ) : (
          <div className="empty-state-sm">None recorded.</div>
        )}
      </div>
    </div>
  );
}

export default function MgmtReviewDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: mr, isLoading, error } = mgmtReviewHooks.useDetail(id);

  return (
    <DetailState
      loading={isLoading}
      error={error ? getErrorMessage(error) : null}
      notFound={!isLoading && !error && !mr}
    >
      {mr && (
        <>
          <PageHeader
            title={
              <span className="row" style={{ gap: 10 }}>
                <GaugeCircle size={22} />
                <span className="mono">{mr.review_number}</span>
                <StatusBadge status={mr.status} />
              </span>
            }
            subtitle={mr.title}
            breadcrumbs={[{ label: 'Management Review', to: '/mgmt-reviews' }, { label: mr.review_number }]}
            actions={<PrintButton />}
          />

          <div className="detail-grid">
            <div className="stack">
              <InputsCard title="Review Inputs" items={mr.inputs} />
              <div className="card">
                <div className="card__header"><div className="card__title">Action Items</div></div>
                <div className="table-wrap">
                  <table className="data-table">
                    <thead>
                      <tr><th>Action</th><th>Owner</th><th>Due</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                      {mr.action_items?.length ? (
                        mr.action_items.map((a) => (
                          <tr key={a.id}>
                            <td>{a.description}</td>
                            <td>{a.owner_id ?? '—'}</td>
                            <td>{formatDate(a.due_date)}</td>
                            <td><StatusBadge status={a.status} /></td>
                          </tr>
                        ))
                      ) : (
                        <tr className="empty-row"><td colSpan={4}><div className="empty-state-sm">No action items.</div></td></tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div className="stack">
              <div className="card">
                <div className="card__header"><div className="card__title">Meeting</div></div>
                <div className="card__body">
                  <DataList
                    items={[
                      { label: 'Chairperson', value: mr.chairperson_id ?? '—' },
                      { label: 'Meeting Date', value: formatDate(mr.meeting_date) },
                    ]}
                  />
                </div>
              </div>
              <div className="card">
                <div className="card__header"><div className="card__title">Attendees</div></div>
                <div className="card__body">
                  {mr.attendees ? (
                    <p style={{ margin: 0 }}>{mr.attendees}</p>
                  ) : (
                    <div className="empty-state-sm">No attendees recorded.</div>
                  )}
                </div>
              </div>
            </div>
          </div>
        </>
      )}
    </DetailState>
  );
}
