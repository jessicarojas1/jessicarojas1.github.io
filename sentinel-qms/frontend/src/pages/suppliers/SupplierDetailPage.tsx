import { useParams } from 'react-router-dom';
import { Truck } from 'lucide-react';
import { supplierHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { PrintButton } from '@/components/PrintButton';
import { StatusBadge } from '@/components/StatusBadge';
import { DataList, DetailState } from '@/components/detail';
import { RecordSupplements } from '@/components/RecordSupplements';

export default function SupplierDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: s, isLoading, error } = supplierHooks.useDetail(id);

  return (
    <DetailState
      loading={isLoading}
      error={error ? getErrorMessage(error) : null}
      notFound={!isLoading && !error && !s}
    >
      {s && (
        <>
          <PageHeader
            title={
              <span className="row" style={{ gap: 10 }}>
                <Truck size={22} />
                {s.name}
                <StatusBadge status={s.status} />
              </span>
            }
            subtitle={`Supplier code ${s.supplier_code}`}
            breadcrumbs={[{ label: 'Suppliers', to: '/suppliers' }, { label: s.name }]}
            actions={<PrintButton />}
          />

          <div className="stack">
            <div className="detail-grid">
              <div className="stack">
                <div className="card">
                  <div className="card__header">
                    <div className="card__title">Identification</div>
                  </div>
                  <div className="card__body">
                    <DataList
                      items={[
                        { label: 'CAGE Code', value: s.cage_code ?? '—' },
                        { label: 'DUNS Number', value: s.duns_number ?? '—' },
                        { label: 'Country', value: s.country ?? '—' },
                        { label: 'Certification', value: s.certification ?? '—' },
                        { label: 'Cert Expiry', value: formatDate(s.cert_expiry) },
                      ]}
                    />
                  </div>
                </div>
              </div>

              <div className="stack">
                <div className="card">
                  <div className="card__header">
                    <div className="card__title">Contact</div>
                  </div>
                  <div className="card__body">
                    <DataList
                      items={[
                        { label: 'Contact', value: s.contact_name ?? '—' },
                        {
                          label: 'Email',
                          value: s.contact_email ? <a href={`mailto:${s.contact_email}`}>{s.contact_email}</a> : '—',
                        },
                      ]}
                    />
                  </div>
                </div>
                {s.notes && (
                  <div className="card">
                    <div className="card__header">
                      <div className="card__title">Notes</div>
                    </div>
                    <div className="card__body">
                      <p style={{ margin: 0 }}>{s.notes}</p>
                    </div>
                  </div>
                )}
              </div>
            </div>
          </div>

          <RecordSupplements entityType="supplier" entityId={s.id} canEditPage="suppliers" />
        </>
      )}
    </DetailState>
  );
}
