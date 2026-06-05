import { useParams } from 'react-router-dom';
import { ScrollText } from 'lucide-react';
import { auditHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { formatDate, humanize } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { StatusBadge } from '@/components/StatusBadge';
import { DataList, DetailState } from '@/components/detail';

export default function AuditDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: audit, isLoading, error } = auditHooks.useDetail(id);

  return (
    <DetailState
      loading={isLoading}
      error={error ? getErrorMessage(error) : null}
      notFound={!isLoading && !error && !audit}
    >
      {audit && (
        <>
          <PageHeader
            title={
              <span className="row" style={{ gap: 10 }}>
                <ScrollText size={22} />
                <span className="mono">{audit.audit_number}</span>
                <StatusBadge status={audit.status} />
              </span>
            }
            subtitle={audit.title}
            breadcrumbs={[{ label: 'Audits', to: '/audits' }, { label: audit.audit_number }]}
          />

          <div className="detail-grid">
            <div className="card">
              <div className="card__header">
                <div className="card__title">Findings</div>
                <span className="badge badge--neutral badge--no-dot">{audit.findings?.length ?? 0} total</span>
              </div>
              <div className="table-wrap">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>Ref</th>
                      <th>Clause</th>
                      <th>Type</th>
                      <th>Description</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {audit.findings?.length ? (
                      audit.findings.map((f) => (
                        <tr key={f.id}>
                          <td className="mono">{f.finding_number}</td>
                          <td className="mono">{f.clause_reference ?? '—'}</td>
                          <td><StatusBadge status={f.finding_type} noDot /></td>
                          <td>{f.description}</td>
                          <td><StatusBadge status={f.status} /></td>
                        </tr>
                      ))
                    ) : (
                      <tr className="empty-row">
                        <td colSpan={5}><div className="empty-state-sm">No findings recorded.</div></td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>

            <div className="card">
              <div className="card__header">
                <div className="card__title">Audit Plan</div>
              </div>
              <div className="card__body">
                <DataList
                  items={[
                    { label: 'Type', value: humanize(audit.audit_type) },
                    { label: 'Standard', value: audit.standard ?? '—' },
                    { label: 'Scope', value: audit.scope ?? '—' },
                    { label: 'Lead Auditor', value: audit.lead_auditor_id ?? '—' },
                    { label: 'Auditee Area', value: audit.auditee_area ?? '—' },
                    { label: 'Planned', value: formatDate(audit.planned_date) },
                    { label: 'Actual', value: formatDate(audit.actual_date) },
                  ]}
                />
              </div>
            </div>
          </div>
        </>
      )}
    </DetailState>
  );
}
