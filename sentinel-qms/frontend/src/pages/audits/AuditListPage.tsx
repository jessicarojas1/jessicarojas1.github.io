import { useNavigate } from 'react-router-dom';
import { ScrollText } from 'lucide-react';
import { auditHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { getErrorMessage } from '@/lib/api';
import { formatDate, humanize } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import type { Audit } from '@/types';

export default function AuditListPage() {
  const navigate = useNavigate();
  const ctl = useListController({ sort: 'planned_date', order: 'desc' });
  const { data, isLoading, error } = auditHooks.useList(ctl.params);

  const columns: Column<Audit>[] = [
    { key: 'audit_number', header: 'Audit #', sortable: true, width: '120px', render: (r) => <span className="mono">{r.audit_number}</span> },
    { key: 'title', header: 'Title', sortable: true, render: (r) => <strong>{r.title}</strong> },
    { key: 'type', header: 'Type', render: (r) => humanize(r.type) },
    { key: 'standard', header: 'Standard' },
    { key: 'status', header: 'Status', sortable: true, render: (r) => <StatusBadge status={r.status} /> },
    { key: 'lead_auditor', header: 'Lead Auditor' },
    { key: 'planned_date', header: 'Planned', sortable: true, render: (r) => formatDate(r.planned_date) },
    { key: 'findings', header: 'Findings', align: 'right', render: (r) => r.findings?.length ?? 0 },
  ];

  return (
    <>
      <PageHeader
        title="Audit Program"
        icon={<ScrollText size={22} />}
        subtitle="Internal, supplier, and certification audits with findings tracking."
        breadcrumbs={[{ label: 'Audits' }]}
      />
      <DataTable
        columns={columns}
        rows={data?.items ?? []}
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
        page={ctl.page}
        pageSize={ctl.pageSize}
        total={data?.total}
        onPageChange={ctl.setPage}
        filters={
          <>
            <div className="field">
              <Select aria-label="Filter by status" value={ctl.filters.status ?? ''} onChange={(e) => ctl.setFilter('status', e.target.value)}>
                <option value="">All statuses</option>
                {['planned', 'in_progress', 'reporting', 'closed'].map((s) => (
                  <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
                ))}
              </Select>
            </div>
            <div className="field">
              <Select aria-label="Filter by type" value={ctl.filters.type ?? ''} onChange={(e) => ctl.setFilter('type', e.target.value)}>
                <option value="">All types</option>
                {['internal', 'external', 'supplier', 'certification'].map((s) => (
                  <option key={s} value={s}>{s}</option>
                ))}
              </Select>
            </div>
          </>
        }
      />
    </>
  );
}
