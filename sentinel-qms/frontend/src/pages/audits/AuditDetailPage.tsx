import { useMemo } from 'react';
import { useParams } from 'react-router-dom';
import { ScrollText } from 'lucide-react';
import { auditHooks } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { formatDate, humanize } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { PrintButton } from '@/components/PrintButton';
import { StatusBadge } from '@/components/StatusBadge';
import { DataList, DetailState } from '@/components/detail';
import { RecordSupplements } from '@/components/RecordSupplements';
import { UserName } from '@/components/UserName';
import type { FindingType } from '@/types';

const FINDING_TONE: Record<FindingType, string> = {
  major_nonconformity: 'danger',
  minor_nonconformity: 'warning',
  observation: 'info',
  opportunity_for_improvement: 'success',
};

export default function AuditDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: audit, isLoading, error } = auditHooks.useDetail(id);

  // AEGIS-style findings tally by type.
  const findingSummary = useMemo(() => {
    const s = { major: 0, minor: 0, observation: 0, ofi: 0, open: 0 };
    for (const f of audit?.findings ?? []) {
      if (f.finding_type === 'major_nonconformity') s.major += 1;
      else if (f.finding_type === 'minor_nonconformity') s.minor += 1;
      else if (f.finding_type === 'observation') s.observation += 1;
      else if (f.finding_type === 'opportunity_for_improvement') s.ofi += 1;
      if (f.status === 'open') s.open += 1;
    }
    return s;
  }, [audit?.findings]);

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
            subtitle={
              <>
                {audit.title} · {humanize(audit.audit_type)}
                {audit.planned_date ? ` · Planned ${formatDate(audit.planned_date)}` : ''}
              </>
            }
            breadcrumbs={[{ label: 'Audits', to: '/audits' }, { label: audit.audit_number }]}
            actions={<PrintButton />}
          />

          {/* KPI metadata bar */}
          <div className="audit-kpi-row">
            <div className="audit-kpi">
              <div className="audit-kpi__label">Status</div>
              <StatusBadge status={audit.status} />
            </div>
            <div className="audit-kpi">
              <div className="audit-kpi__label">Type</div>
              <div className="audit-kpi__value">{humanize(audit.audit_type)}</div>
            </div>
            <div className="audit-kpi">
              <div className="audit-kpi__label">Lead Auditor</div>
              <div className="audit-kpi__value">{audit.lead_auditor_id == null ? 'Unassigned' : <UserName id={audit.lead_auditor_id} />}</div>
            </div>
            <div className="audit-kpi">
              <div className="audit-kpi__label">Findings</div>
              <div className="audit-kpi__value">{audit.findings?.length ?? 0}</div>
            </div>
            <div className="audit-kpi audit-kpi--findings">
              <div className="audit-kpi__label">Breakdown</div>
              <div className="audit-finding-tally">
                <span className="tally tally--danger">{findingSummary.major} Major</span>
                <span className="tally tally--warning">{findingSummary.minor} Minor</span>
                <span className="tally tally--info">{findingSummary.observation} Obs</span>
                <span className="tally tally--success">{findingSummary.ofi} OFI</span>
              </div>
            </div>
          </div>

          <div className="detail-grid">
            {/* Findings */}
            <div className="card">
              <div className="card__header">
                <div className="card__title">Findings</div>
                <span className="badge badge--neutral badge--no-dot">{audit.findings?.length ?? 0} total</span>
              </div>
              <div className="table-wrap">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>Finding #</th>
                      <th>Type</th>
                      <th>Clause</th>
                      <th>Description</th>
                      <th>Status</th>
                      <th>CAPA</th>
                    </tr>
                  </thead>
                  <tbody>
                    {audit.findings?.length ? (
                      audit.findings.map((f) => (
                        <tr key={f.id}>
                          <td className="mono">{f.finding_number}</td>
                          <td>
                            <span className={`badge badge--${FINDING_TONE[f.finding_type]} badge--no-dot`}>
                              {humanize(f.finding_type)}
                            </span>
                          </td>
                          <td className="mono">{f.clause_reference ?? '—'}</td>
                          <td>{f.description}</td>
                          <td><StatusBadge status={f.status} /></td>
                          <td className="mono">{f.capa_id ? `#${f.capa_id}` : '—'}</td>
                        </tr>
                      ))
                    ) : (
                      <tr className="empty-row">
                        <td colSpan={6}><div className="empty-state-sm">No findings recorded.</div></td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>

            {/* Audit plan metadata */}
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
                    { label: 'Lead Auditor', value: <UserName id={audit.lead_auditor_id} /> },
                    { label: 'Auditee Area', value: audit.auditee_area ?? '—' },
                    { label: 'Planned', value: formatDate(audit.planned_date) },
                    { label: 'Actual', value: formatDate(audit.actual_date) },
                  ]}
                />
              </div>
            </div>
          </div>

          {/* Checklist */}
          {audit.checklist_items && audit.checklist_items.length > 0 && (
            <div className="card" style={{ marginTop: 'var(--space-4)' }}>
              <div className="card__header">
                <div className="card__title">Audit Checklist</div>
                <span className="badge badge--neutral badge--no-dot">{audit.checklist_items.length} items</span>
              </div>
              <div className="table-wrap">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>Clause</th>
                      <th>Question</th>
                      <th>Result</th>
                      <th>Notes</th>
                    </tr>
                  </thead>
                  <tbody>
                    {audit.checklist_items.map((c) => (
                      <tr key={c.id}>
                        <td className="mono">{c.clause_reference ?? '—'}</td>
                        <td>{c.question}</td>
                        <td>{c.result ? <StatusBadge status={c.result} /> : '—'}</td>
                        <td>{c.notes ?? '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          <RecordSupplements entityType="audit" entityId={audit.id} canEditPage="audits" />
        </>
      )}
    </DetailState>
  );
}
