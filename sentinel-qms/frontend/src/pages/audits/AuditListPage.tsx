import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ScrollText, Plus, CalendarClock, PlayCircle, CheckCircle2, AlertCircle } from 'lucide-react';
import { auditHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { useUserName } from '@/hooks/useUserLookup';
import { getErrorMessage } from '@/lib/api';
import { formatDate, humanize, isOverdue } from '@/lib/format';
import { usePagePerms } from '@/lib/permissions';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import { AuditCreateModal } from './AuditCreateModal';
import type { Audit } from '@/types';

export default function AuditListPage() {
  const navigate = useNavigate();
  const ctl = useListController({ sort: 'planned_date', order: 'desc', page_size: 100 });
  const userName = useUserName();
  const { canEdit } = usePagePerms();
  const [createOpen, setCreateOpen] = useState(false);
  const { data, isLoading, error } = auditHooks.useList(ctl.params);
  const audits = data?.items ?? [];

  // AEGIS-style status summary.
  const summary = useMemo(() => {
    const s = { planned: 0, in_progress: 0, closed: 0, overdue: 0 };
    for (const a of audits) {
      if (a.status === 'planned') s.planned += 1;
      else if (a.status === 'in_progress') s.in_progress += 1;
      else if (a.status === 'closed') s.closed += 1;
      if (a.status !== 'closed' && a.status !== 'reporting' && isOverdue(a.planned_date)) s.overdue += 1;
    }
    return s;
  }, [audits]);

  const columns: Column<Audit>[] = [
    { key: 'audit_number', header: 'Audit #', sortable: true, width: '120px', render: (r) => <span className="mono">{r.audit_number}</span> },
    { key: 'audit_type', header: 'Type', render: (r) => <span className="pill">{humanize(r.audit_type)}</span> },
    {
      key: 'title',
      header: 'Title / Scope',
      sortable: true,
      render: (r) => (
        <div>
          <strong>{r.title}</strong>
          {r.scope && <div className="text-sm muted text-truncate">{r.scope}</div>}
        </div>
      ),
    },
    { key: 'auditee_area', header: 'Auditee Area', render: (r) => r.auditee_area ?? '—' },
    { key: 'standard', header: 'Standard', render: (r) => r.standard ?? '—' },
    { key: 'lead_auditor_id', header: 'Lead Auditor', render: (r) => userName(r.lead_auditor_id) },
    {
      key: 'planned_date',
      header: 'Planned',
      sortable: true,
      render: (r) => {
        const overdue = r.status !== 'closed' && isOverdue(r.planned_date);
        return (
          <span style={overdue ? { color: 'var(--danger)', fontWeight: 600 } : undefined}>
            {formatDate(r.planned_date)}
            {overdue && ' ⚠'}
          </span>
        );
      },
    },
    { key: 'actual_date', header: 'Actual', render: (r) => formatDate(r.actual_date) },
    { key: 'status', header: 'Status', sortable: true, render: (r) => <StatusBadge status={r.status} /> },
    {
      key: 'findings',
      header: 'Findings',
      align: 'center',
      render: (r) => <span className="badge badge--neutral badge--no-dot">{r.findings?.length ?? 0}</span>,
    },
  ];

  return (
    <>
      <PageHeader
        title="Audit Management"
        icon={<ScrollText size={22} />}
        subtitle="Schedule, track, and complete internal, supplier, and certification audits."
        breadcrumbs={[{ label: 'Audits' }]}
        actions={
          canEdit('audits') && (
            <button type="button" className="btn btn-primary" onClick={() => setCreateOpen(true)}>
              <Plus size={16} /> Schedule Audit
            </button>
          )
        }
      />

      <div className="stack">
        <div className="risk-kpi-grid">
          <div className="risk-kpi">
            <CalendarClock size={20} style={{ color: 'var(--primary)' }} />
            <span className="risk-kpi__num">{summary.planned}</span>
            <span className="risk-kpi__label">Planned</span>
          </div>
          <div className="risk-kpi">
            <PlayCircle size={20} style={{ color: 'var(--info)' }} />
            <span className="risk-kpi__num">{summary.in_progress}</span>
            <span className="risk-kpi__label">In Progress</span>
          </div>
          <div className="risk-kpi">
            <CheckCircle2 size={20} style={{ color: 'var(--success)' }} />
            <span className="risk-kpi__num">{summary.closed}</span>
            <span className="risk-kpi__label">Closed</span>
          </div>
          <div className="risk-kpi risk-kpi--critical">
            <AlertCircle size={20} />
            <span className="risk-kpi__num">{summary.overdue}</span>
            <span className="risk-kpi__label">Overdue</span>
          </div>
        </div>

        <DataTable
          columns={columns}
          rows={audits}
          rowKey={(r) => r.id}
          loading={isLoading}
          error={error ? getErrorMessage(error) : null}
          onRowClick={(r) => navigate(`/audits/${r.id}`)}
          search={ctl.search}
          onSearchChange={ctl.setSearch}
          searchPlaceholder="Search audit # or title…"
          sort={ctl.sort}
          order={ctl.order}
          onSortChange={ctl.onSortChange}
          exportFilename="audits"
          filters={
            <>
              <div className="field">
                <Select aria-label="Filter by status" value={ctl.filters.status ?? ''} onChange={(e) => ctl.setFilter('status', e.target.value)}>
                  <option value="">All statuses</option>
                  {['planned', 'in_progress', 'reporting', 'closed'].map((s) => (
                    <option key={s} value={s}>{humanize(s)}</option>
                  ))}
                </Select>
              </div>
              <div className="field">
                <Select aria-label="Filter by type" value={ctl.filters.audit_type ?? ''} onChange={(e) => ctl.setFilter('audit_type', e.target.value)}>
                  <option value="">All types</option>
                  {['internal', 'external', 'supplier', 'certification', 'process'].map((s) => (
                    <option key={s} value={s}>{humanize(s)}</option>
                  ))}
                </Select>
              </div>
            </>
          }
        />
      </div>

      <AuditCreateModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={(id) => {
          setCreateOpen(false);
          navigate(`/audits/${id}`);
        }}
      />
    </>
  );
}
