import { useParams } from 'react-router-dom';
import { Gauge, PackageCheck, Star, TriangleAlert, Truck } from 'lucide-react';
import { supplierHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { formatDate, formatPercent } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { StatusBadge } from '@/components/StatusBadge';
import { KpiCard } from '@/components/KpiCard';
import { DataList, DetailState } from '@/components/detail';

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
            subtitle={`Supplier code ${s.code}`}
            breadcrumbs={[{ label: 'Suppliers', to: '/suppliers' }, { label: s.name }]}
          />

          <div className="stack">
            <div className="kpi-grid">
              <KpiCard icon={Star} value={s.rating.toFixed(1)} label="Overall Rating" tone="success" />
              <KpiCard icon={PackageCheck} value={formatPercent(s.on_time_delivery)} label="On-Time Delivery" tone="primary" />
              <KpiCard icon={Gauge} value={s.quality_ppm.toLocaleString()} label="Quality PPM" tone="warning" />
              <KpiCard icon={TriangleAlert} value={s.open_scars} label="Open SCARs" tone={s.open_scars ? 'danger' : 'primary'} />
            </div>

            <div className="detail-grid">
              <div className="stack">
                <div className="card">
                  <div className="card__header">
                    <div className="card__title">Supplier Corrective Action Requests (SCAR)</div>
                  </div>
                  <div className="table-wrap">
                    <table className="data-table">
                      <thead>
                        <tr>
                          <th>SCAR #</th>
                          <th>Issue</th>
                          <th>Status</th>
                          <th>Issued</th>
                          <th>Due</th>
                        </tr>
                      </thead>
                      <tbody>
                        {s.scars?.length ? (
                          s.scars.map((scar) => (
                            <tr key={scar.id}>
                              <td className="mono">{scar.scar_number}</td>
                              <td>{scar.issue}</td>
                              <td>
                                <StatusBadge status={scar.status} />
                              </td>
                              <td>{formatDate(scar.issued_at)}</td>
                              <td>{formatDate(scar.due_date)}</td>
                            </tr>
                          ))
                        ) : (
                          <tr className="empty-row">
                            <td colSpan={5}>
                              <div className="empty-state-sm">No SCARs on record.</div>
                            </td>
                          </tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <div className="stack">
                <div className="card">
                  <div className="card__header">
                    <div className="card__title">Qualification</div>
                  </div>
                  <div className="card__body">
                    <DataList
                      items={[
                        { label: 'Category', value: s.category ?? '—' },
                        { label: 'Approved Scope', value: s.approved_scope ?? '—' },
                        { label: 'Contact', value: s.contact_name ?? '—' },
                        {
                          label: 'Email',
                          value: s.contact_email ? <a href={`mailto:${s.contact_email}`}>{s.contact_email}</a> : '—',
                        },
                        { label: 'Last Audit', value: formatDate(s.last_audit_date) },
                        { label: 'Next Audit', value: formatDate(s.next_audit_date) },
                      ]}
                    />
                  </div>
                </div>
                <div className="card">
                  <div className="card__header">
                    <div className="card__title">Certifications</div>
                  </div>
                  <div className="card__body">
                    {s.certifications?.length ? (
                      <div className="tag-list">
                        {s.certifications.map((c) => (
                          <span key={c} className="pill">
                            {c}
                          </span>
                        ))}
                      </div>
                    ) : (
                      <div className="empty-state-sm">None recorded.</div>
                    )}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </>
      )}
    </DetailState>
  );
}
