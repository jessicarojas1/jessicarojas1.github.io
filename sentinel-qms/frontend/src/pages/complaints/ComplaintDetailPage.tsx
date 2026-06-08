import { useParams } from 'react-router-dom';
import { MessageSquareWarning } from 'lucide-react';
import { complaintHooks } from '@/hooks';
import { useAuth } from '@/lib/auth';
import { can } from '@/lib/rbac';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { PrintButton } from '@/components/PrintButton';
import { PdfButton } from '@/components/PdfButton';
import { StatusBadge } from '@/components/StatusBadge';
import { DataList, DetailState } from '@/components/detail';
import { RecordSupplements } from '@/components/RecordSupplements';
import { UserName } from '@/components/UserName';

export default function ComplaintDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: c, isLoading, error } = complaintHooks.useDetail(id);
  const { user } = useAuth();
  const { notify } = useToast();
  const createCapa = complaintHooks.useAction<undefined, { capa_id: number; capa_number: string }>(
    'create-capa',
  );
  const canCreateCapa = can(user?.roles, 'capa.write');

  const handleCreateCapa = () => {
    if (!id) return;
    createCapa.mutate(
      { id },
      {
        onSuccess: (res) => notify(`Created ${res.capa_number}`, 'success'),
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

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
            subtitle={`${c.customer_name} · ${c.title}`}
            breadcrumbs={[{ label: 'Complaints', to: '/complaints' }, { label: c.complaint_number }]}
            actions={
              <>
                <PrintButton />
                <PdfButton path={`/reports/complaint/${c.id}/pdf`} filename={`${c.complaint_number}.pdf`} />
              </>
            }
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
                      { label: 'Serial Number', value: c.serial_number ?? '—' },
                      { label: 'RMA Number', value: c.rma_number ?? '—' },
                      { label: 'Assigned To', value: c.assigned_to == null ? 'Unassigned' : <UserName id={c.assigned_to} /> },
                      { label: 'Received', value: formatDate(c.received_date) },
                      { label: 'Closed', value: formatDateTime(c.closed_at) },
                    ]}
                  />
                </div>
              </div>
              <div className="card">
                <div className="card__header"><div className="card__title">Linked Records</div></div>
                <div className="card__body">
                  <DataList
                    items={[
                      { label: 'NCR', value: c.nonconformance_id ? <a href={`/nonconformances/${c.nonconformance_id}`}>View NCR</a> : 'None' },
                      {
                        label: 'CAPA',
                        value: c.capa_id ? (
                          <a href={`/capa/${c.capa_id}`}>View CAPA</a>
                        ) : canCreateCapa ? (
                          <button
                            type="button"
                            className="btn btn-sm btn-secondary"
                            onClick={handleCreateCapa}
                            disabled={createCapa.isPending}
                          >
                            Create CAPA
                          </button>
                        ) : (
                          'None'
                        ),
                      },
                    ]}
                  />
                </div>
              </div>
            </div>
          </div>

          <RecordSupplements entityType="complaint" entityId={c.id} canEditPage="complaints" />
        </>
      )}
    </DetailState>
  );
}
