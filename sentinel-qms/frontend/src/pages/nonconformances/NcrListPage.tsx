import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Plus, ShieldAlert } from 'lucide-react';
import { ncrHooks } from '@/hooks';
import { useListController } from '@/hooks/useListController';
import { useAuth } from '@/lib/auth';
import { can } from '@/lib/rbac';
import { getErrorMessage } from '@/lib/api';
import { formatDate, isOverdue } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { DataTable, type Column } from '@/components/DataTable';
import { StatusBadge } from '@/components/StatusBadge';
import { Select } from '@/components/FormField';
import { NcrCreateModal } from './NcrCreateModal';
import type { Nonconformance } from '@/types';

const STATUS_OPTIONS = [
  'open',
  'under_review',
  'disposition_pending',
  'dispositioned',
  'closed',
  'cancelled',
];
const SEVERITY_OPTIONS = ['minor', 'major', 'critical'];

export default function NcrListPage() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const ctl = useListController();
  const [createOpen, setCreateOpen] = useState(false);
  const { data, isLoading, error } = ncrHooks.useList(ctl.params);

  const columns: Column<Nonconformance>[] = [
    {
      key: 'ncr_number',
      header: 'NCR #',
      sortable: true,
      render: (r) => <span className="mono">{r.ncr_number}</span>,
      width: '120px',
    },
    { key: 'title', header: 'Title', sortable: true, render: (r) => <strong>{r.title}</strong> },
    {
      key: 'severity',
      header: 'Severity',
      sortable: true,
      render: (r) => <StatusBadge status={r.severity} />,
    },
    {
      key: 'status',
      header: 'Status',
      sortable: true,
      render: (r) => <StatusBadge status={r.status} />,
    },
    {
      key: 'part_number',
      header: 'Part #',
      render: (r) => <span className="mono text-sm">{r.part_number ?? '—'}</span>,
    },
    { key: 'supplier_name', header: 'Supplier', render: (r) => r.supplier_name ?? '—' },
    {
      key: 'due_date',
      header: 'Due',
      sortable: true,
      render: (r) => (
        <span style={isOverdue(r.due_date) && r.status !== 'closed' ? { color: 'var(--danger)', fontWeight: 600 } : undefined}>
          {formatDate(r.due_date)}
        </span>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title="Nonconformances"
        icon={<ShieldAlert size={22} />}
        subtitle="Material review board (MRB) dispositions and nonconformance reports."
        breadcrumbs={[{ label: 'Nonconformances' }]}
        actions={
          can(user?.roles, 'ncr.write') && (
            <button type="button" className="btn btn-primary" onClick={() => setCreateOpen(true)}>
              <Plus size={16} /> New NCR
            </button>
          )
        }
      />

      <DataTable
        columns={columns}
        rows={data?.items ?? []}
        rowKey={(r) => r.id}
        loading={isLoading}
        error={error ? getErrorMessage(error) : null}
        onRowClick={(r) => navigate(`/nonconformances/${r.id}`)}
        search={ctl.search}
        onSearchChange={ctl.setSearch}
        searchPlaceholder="Search NCR #, title, part…"
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
              <Select
                aria-label="Filter by status"
                value={ctl.filters.status ?? ''}
                onChange={(e) => ctl.setFilter('status', e.target.value)}
              >
                <option value="">All statuses</option>
                {STATUS_OPTIONS.map((s) => (
                  <option key={s} value={s}>
                    {s.replace(/_/g, ' ')}
                  </option>
                ))}
              </Select>
            </div>
            <div className="field">
              <Select
                aria-label="Filter by severity"
                value={ctl.filters.severity ?? ''}
                onChange={(e) => ctl.setFilter('severity', e.target.value)}
              >
                <option value="">All severities</option>
                {SEVERITY_OPTIONS.map((s) => (
                  <option key={s} value={s}>
                    {s}
                  </option>
                ))}
              </Select>
            </div>
          </>
        }
      />

      <NcrCreateModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={(id) => {
          setCreateOpen(false);
          navigate(`/nonconformances/${id}`);
        }}
      />
    </>
  );
}
