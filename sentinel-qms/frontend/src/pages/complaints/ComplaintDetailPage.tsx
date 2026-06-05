import { useParams } from 'react-router-dom';
import { MessageSquareWarning } from 'lucide-react';
import { complaintHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { StatusBadge } from '@/components/StatusBadge';
import { DataList, DetailState } from '@/components/detail';

export default function ComplaintDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: c, isLoading, error } = complaintHooks.useDetail(id);

  return (
    <DetailState
      loading={isLoading}
      error={error ? getErrorMessage(error) : null}
      notFound={!isLoading && !error && !c}
    >
      {c && (
        <>
          <PageHeader
            title={
              <span className="row" style={{ gap: 10 }}>
                <MessageSquareWarning size={22} />
                <span className="mono">{c.complaint_number}</span>
                <StatusBadge status={c.status} />
              </span>
            }
            subtitle={`${c.customer}${c.product ? ` · ${c.product}` : ''}`}
            breadcrumbs={[{ label: 'Complaints', to: '/complaints' }, { label: c.complaint_number }]}
          />

          <div className="detail-grid">
            <div className="stack">
              <div className="card">
                <div className="card__header"><div className="card__title">Complaint</div><StatusBadge status={c.severity} /></div>
                <div className="card__body">
                  <p style={{ marginTop: 0 }}>{c.description}</p>
                  {c.resolution && (
                    <>
                      <div className="section-title">Resolution</div>
                      <p style={{ margin: 0 }}>{c.resolution}</p>
                    </>
                  )}
                </div>
              </div>
            </div>

            <div className="stack">
              <div className="card">
                <div className="card__header"><div className="card__title">Details</div></div>
                <div className="card__body">
                  <DataList
                    items={[
                      { label: 'Part Number', value: c.part_number ?? '—' },
                      { label: 'RMA Number', value: c.rma_number ?? '—' },
                      { label: 'Assigned To', value: c.assigned_to ?? 'Unassigned' },
                      { label: 'Received', value: formatDateTime(c.received_at) },
                      { label: 'Closed', value: formatDate(c.closed_at) },
                    ]}
                  />
                </div>
              </div>
              <div className="card">
                <div className="card__header"><div className="card__title">Linked Records</div></div>
                <div className="card__body">
                  <DataList
                    items={[
                      { label: 'NCR', value: c.linked_ncr_id ? <a href={`/nonconformances/${c.linked_ncr_id}`}>View NCR</a> : 'None' },
                      { label: 'CAPA', value: c.linked_capa_id ? <a href={`/capa/${c.linked_capa_id}`}>View CAPA</a> : 'None' },
                    ]}
                  />
                </div>
              </div>
            </div>
          </div>
        </>
      )}
    </DetailState>
  );
}
